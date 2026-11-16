(function(){'use strict';
/* KanzleiCheck staff SPA: Overview · Messages · Tasks · Requests ·
   Clients · Uploads · Settings. esc() for all user data — no innerHTML. */
const el=document.getElementById('kzc-staff-view'),base=location.pathname.split('/apps/kanzleicheck')[0]+'/apps/kanzleicheck';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('kanzleicheck',s):s;
const n=(sg,pl,c)=>{const x=Number.isFinite(Number(c))?Number(c):0;return(typeof window.n==='function')?window.n('kanzleicheck',sg,pl,x):String(x===1?sg:pl).replace('%n',String(x));};
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json'}},o||{}));if(r.status===204)return{success:true};return r.json().catch(()=>({success:false}));};
const get=u=>api(u),post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})}),put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})}),del=u=>api(u,{method:'DELETE'});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};
const btn=(label,fn,primary)=>{const b=document.createElement('button');b.className='kzc-btn'+(primary?' kzc-btn--primary':'');b.textContent=label;b.onclick=fn;return b;};
const status=t=>{const s=document.getElementById('kzc-live');if(s)s.textContent=t||'';document.getElementById('kzc-loading')?.remove();};
let ME_ADMIN=false;
/* per-loader generation counters — a superseded list fetch must never paint
   over a newer result (stale_response_render_clobber). Module scope so view
   remounts also invalidate in-flight fetches from the previous mount.
   VIEW_SEQ covers whole-view entries: every view function bumps it on entry,
   so any in-flight continuation of a previous view (await→chrome()/paint)
   detects its own staleness and returns before touching the document. */
let ORG_SEQ=0,PQ_SEQ=0,VIEW_SEQ=0;

/* native dialog helpers (never window.prompt/confirm) */
function ask(title,label,okLabel,fields){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='kzc-dialog';
  let inner='<form method="dialog" class="kzc-card"><h2></h2>';
  (fields||[{name:'v',label}]).forEach(f=>{
    inner+='<label class="kzc-label" for="fd-'+esc(f.name)+'"></label>';
    inner+=f.type==='select'?'<select id="fd-'+esc(f.name)+'" class="kzc-input" name="'+esc(f.name)+'">'+(f.options||[]).map(o=>'<option value="'+esc(o[0])+'">'+esc(o[1])+'</option>').join('')+'</select>'
      :f.type==='textarea'?'<textarea id="fd-'+esc(f.name)+'" class="kzc-input" name="'+esc(f.name)+'" rows="3"></textarea>'
      :'<input id="fd-'+esc(f.name)+'" class="kzc-input" name="'+esc(f.name)+'" type="'+esc(f.type||'text')+'" '+(f.required?'required':'')+'>';
  });
  inner+='<div class="kzc-actions"><button value="" class="kzc-btn">'+esc(t('Cancel'))+'</button><button value="ok" class="kzc-btn kzc-btn--primary"></button></div></form>';
  dlg.innerHTML=inner;dlg.querySelector('h2').textContent=title;
  dlg.querySelectorAll('.kzc-label').forEach((l,i)=>{const f=(fields||[{label}])[i];l.textContent=f.label;});
  dlg.querySelector('.kzc-btn--primary').textContent=okLabel;
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  dlg.addEventListener('close',()=>{
    if(dlg.returnValue!=='ok'){res(null);dlg.remove();return;}
    const out={};dlg.querySelectorAll('[name]').forEach(i=>out[i.name]=i.value);res(out);dlg.remove();
  });
  document.body.appendChild(dlg);dlg.showModal();
});}
function confirmDlg(title,text){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='kzc-dialog';
  dlg.innerHTML='<form method="dialog" class="kzc-card"><h2></h2><p class="kzc-muted"></p><div class="kzc-actions"><button value="" class="kzc-btn">'+esc(t('Cancel'))+'</button><button value="ok" class="kzc-btn kzc-btn--primary">'+esc(t('Confirm'))+'</button></div></form>';
  dlg.querySelector('h2').textContent=title;dlg.querySelector('.kzc-muted').textContent=text;
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  dlg.addEventListener('close',()=>{res(dlg.returnValue==='ok');dlg.remove();});
  document.body.appendChild(dlg);dlg.showModal();});}

const NAV=[['overview',t('Overview')],['inbox',t('Messages')],['board',t('Tasks')],['requests',t('Requests')],['orgs',t('Clients')],['uploads',t('Uploads')],['holds',t('Mail review')],['forms',t('Questionnaires')],['people',t('People')],['settings',t('Settings')]];
function chrome(active,title){
  el.innerHTML='<nav class="kzc-nav" aria-label="'+esc(t('Sections'))+'">'+NAV.map(([k,l])=>'<button class="kzc-navbtn'+(k===active?' kzc-navbtn--active':'')+'" data-v="'+k+'">'+esc(l)+'</button>').join('')+'</nav><h1>'+esc(title)+'</h1><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div><div id="kzc-body"><p class="kzc-muted" id="kzc-loading" role="status"></p></div>';
  const ld=document.getElementById('kzc-loading');ld.textContent=t('Loading…');
  el.querySelectorAll('.kzc-navbtn').forEach(b=>b.onclick=()=>{status(t('Loading…'));VIEWS[b.dataset.v]();});
  const body=document.getElementById('kzc-body');
  // instant feedback on every view switch: clear the loading placeholder on
  // the first real insert (covers success, error and early-return paths).
  const orig=body.appendChild.bind(body);
  let cleared=false;
  body.appendChild=n=>{if(!cleared){cleared=true;ld.remove();}return orig(n);};
  return body;
}

/* ── Overview ── */
async function vOverview(){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/overview');
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  const body=chrome('overview',t('What needs attention?'));
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||t('Error'))+'</p>'));return;}
  const su=await get('/api/portal/setup');
  if(seq!==VIEW_SEQ)return;
  if(su.success&&su.data&&su.data.setup&&!su.data.setup.done){
    const coach=h('<div class="kzc-card kzc-coach"><h2>'+esc(t('Initial setup'))+'</h2><ul></ul></div>');
    const ul=coach.querySelector('ul');
    if(!su.data.setup.preset)ul.appendChild(h('<li>'+esc(t('Choose an industry preset in Settings.'))+'</li>'));
    if(!su.data.setup.docsRoot)ul.appendChild(h('<li>'+esc(t('Set the documents root folder in Settings (create a folder in Files first).'))+'</li>'));
    if(!su.data.setup.orgs)ul.appendChild(h('<li>'+esc(t('Create your first client under Clients, or import a CSV.'))+'</li>'));
    body.appendChild(coach);
  }
  const s=r.data.stats;
  body.appendChild(h('<div class="kzc-stats">'+[
    [s.unread,t('Unread messages'),'inbox'],
    [s.newUploads,t('New uploads'),'uploads'],
    [s.overdueRequests,t('Overdue requests'),'requests'],
    [s.openApprovals,t('Open approvals'),'requests'],
  ].map(([n,l,v])=>'<button class="kzc-stat" data-v="'+v+'"><b>'+n+'</b>'+esc(l)+'</button>').join('')+'</div>'));
  body.querySelectorAll('.kzc-stat').forEach(b=>b.onclick=()=>VIEWS[b.dataset.v]());
  const q=r.data.queue||[];
  if(q.length){
    body.appendChild(h('<h2>'+esc(t('Recently active'))+'</h2>'));
    const list=h('<div class="kzc-list"></div>').firstElementChild;
    q.slice(0,8).forEach(c=>{
      const row=h('<button class="kzc-row"><b></b><span class="kzc-muted"></span>'+(c.unread?'<span class="kzc-badge">'+c.unread+'</span>':'')+'</button>').firstElementChild;
      row.setAttribute('aria-label',c.orgName||t('Client'));
      row.querySelector('b').textContent=c.orgName||t('Client');row.querySelector('.kzc-muted').textContent=c.lastPreview||'';
      row.onclick=()=>openConv(c.orgId,c.orgName);list.appendChild(row);
    });
    body.appendChild(list);
  } else body.appendChild(h('<p class="kzc-muted">'+esc(t('All done — no open items.'))+'</p>'));
}

/* ── Messages (inbox + thread) ── */
async function vInbox(mine){
  VIEW_SEQ++;
  const body=chrome('inbox',t('Messages'));
  const mineCb=h('<label class="kzc-check"><input type="checkbox" id="kzc-mine"> '+esc(t('Only my clients'))+'</label>');
  mineCb.querySelector('#kzc-mine').checked=!!mine;
  mineCb.querySelector('#kzc-mine').onchange=e=>vInbox(e.target.checked);
  body.appendChild(mineCb);
  const r=await get('/api/portal/inbox'+(mine?'?mine=1':''));
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||t('Error'))+'</p>'));return;}
  const convs=r.data.conversations||[];
  if(!convs.length){body.appendChild(h('<p class="kzc-muted">'+esc(t('No conversations.'))+'</p>'));return;}
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  convs.forEach(c=>{
    const row=h('<button class="kzc-row"><b></b><span class="kzc-muted"></span>'+(c.unread?'<span class="kzc-badge">'+c.unread+'</span>':'')+'</button>').firstElementChild;
    row.querySelector('b').textContent=c.orgName;
    row.querySelector('.kzc-muted').textContent=(c.lastAuthorName?c.lastAuthorName+': ':'')+(c.lastPreview||'')+(c.state==='archived'?' '+t('(archived)'):'');
    row.onclick=()=>openConv(c.orgId,c.orgName);list.appendChild(row);
  });
  body.appendChild(list);
}
/* Exactly ONE self-rescheduling chain per conversation generation:
   - CONV_GEN invalidates orphan chains on re-open/view-switch (a stale
     chain must die, not keep polling into a detached or recycled box).
   - CONV_BUSY holds the generation currently in flight: a caller on the
     SAME generation coalesces via WANT (a send marks WANT and the
     finishing pull runs ONE fresh pull — never a second parallel chain,
     poller_chain_fork_duplicate_render); a NEWER generation always fetches
     immediately — the superseded response is dropped on arrival anyway.
   - the painted-org check (o!==CONV_ORG) drops cross-org responses before
     they can paint or corrupt CONV_LAST (stale_response_render_clobber). */
let CONV_ORG=null,CONV_LAST=0,CONV_TIMER=null,CONV_BUSY=0,CONV_WANT=false,CONV_GEN=0;
async function openConv(orgId,name){
  VIEW_SEQ++;
  if(CONV_TIMER){clearTimeout(CONV_TIMER);CONV_TIMER=null;}
  CONV_ORG=orgId;CONV_LAST=0;
  const gen=++CONV_GEN;
  const body=chrome('inbox',t('Chat')+' — '+name);
  body.appendChild(h('<div id="kzc-msgs" class="kzc-msgs"></div>'));
  body.appendChild(h('<form id="kzc-cf" class="kzc-composer"><input id="kzc-cm" class="kzc-input" maxlength="4000" required autocomplete="off" placeholder="'+esc(t('Write a reply…'))+'"><button class="kzc-btn kzc-btn--primary">'+esc(t('Send'))+'</button></form>'));
  body.querySelector('#kzc-cf').addEventListener('submit',async e=>{
    e.preventDefault();const inp=document.getElementById('kzc-cm'),v=inp.value.trim();if(!v)return;
    const r=await post('/api/portal/orgs/'+orgId+'/messages',{body:v,clientUuid:crypto.randomUUID()});
    // send triggers the pending poll EARLY on the same chain — clear the
    // armed timer first, never invoke a second self-rescheduling chain.
    if(r.success){inp.value='';if(CONV_TIMER){clearTimeout(CONV_TIMER);CONV_TIMER=null;}pullConv(gen);}else status(r.message||t('Error'));
  });
  pullConv(gen);
}
async function pullConv(gen){
  const o=CONV_ORG;
  if(o===null||gen!==CONV_GEN)return;
  if(CONV_BUSY===gen){CONV_WANT=true;return;} // same chain already in flight — coalesce
  CONV_BUSY=gen;
  const r=await get('/api/portal/orgs/'+o+'/messages?after='+CONV_LAST).catch(()=>null);
  if(CONV_BUSY===gen)CONV_BUSY=0;
  if(CONV_WANT){CONV_WANT=false;pullConv(CONV_GEN);return;} // coalesced — one fresh pull under current gen/org
  const box=document.getElementById('kzc-msgs');
  if(!box||o!==CONV_ORG||gen!==CONV_GEN)return; // view/org switched mid-flight — drop, stop chain
  if(r&&r.success&&r.data){
    (r.data.messages||[]).forEach(m=>{
      if(m.id<=CONV_LAST)return; // already painted — belt dedupe vs overlapping fetches
      CONV_LAST=m.id;
      const mine=m.kind==='staff';
      const row=h('<div class="kzc-msg'+(mine?' kzc-msg--mine':'')+'"><div class="kzc-msg__body"></div><div class="kzc-msg__meta"></div></div>');
      row.querySelector('.kzc-msg__body').textContent=m.body;
      row.querySelector('.kzc-msg__meta').textContent=(m.authorName||m.author)+' · '+new Date(m.createdAt).toLocaleString();
      box.appendChild(row);
    });
    if(r.data.messages.length)box.scrollTop=box.scrollHeight;
    if(r.data.state==='archived'){document.getElementById('kzc-cf')?.remove();status(t('Conversation archived (read only).'));}
  }
  CONV_TIMER=setTimeout(()=>{CONV_TIMER=null;pullConv(gen);},15000);
}

/* ── Task board ── */
async function vBoard(){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/board');
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  const body=chrome('board',t('Tasks'));
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||t('Error'))+'</p>'));return;}
  body.appendChild(btn(t('+ New task'),async()=>{
    const orgs=(await get('/api/portal/orgs')).data.orgs;
    const f=await ask(t('New task'),null,t('Create'),[
      {name:'orgId',label:t('Client'),type:'select',options:orgs.map(o=>[o.id,o.name])},
      {name:'title',label:t('Title'),required:true},
      {name:'note',label:t('Note'),type:'textarea'},
      {name:'priority',label:t('Priority'),type:'select',options:[['low',t('Low')],['normal',t('Normal')],['high',t('High')],['urgent',t('Urgent')]]},
      {name:'dueDate',label:t('Due'),type:'date'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/tasks',{orgId:+f.orgId,title:f.title,note:f.note||null,priority:f.priority,dueDate:f.dueDate||null});
    status(rr.success?t('Created.'):(rr.message||t('Error')));if(rr.success)vBoard();
  },true));
  const cols=r.data.columns||{};
  const wrap=h('<div class="kzc-board"></div>').firstElementChild;
  Object.entries(cols).forEach(([key,col])=>{
    const c=h('<div class="kzc-col"><h2></h2></div>').firstElementChild;
    c.querySelector('h2').textContent=col.label+' ('+col.cards.length+')';
    col.cards.forEach(card=>c.appendChild(taskCard(card)));
    wrap.appendChild(c);
  });
  body.appendChild(wrap);
}
function taskCard(it){
  const c=h('<div class="kzc-card2"><b></b><div class="kzc-muted"></div><div class="kzc-cardmeta"></div></div>');
  c.querySelector('b').textContent=it.title;
  c.querySelector('.kzc-muted').textContent=it.orgName;
  const meta=c.querySelector('.kzc-cardmeta');
  meta.appendChild(h('<span class="kzc-pill kzc-pill--'+esc(it.priority)+'">'+esc(it.priorityLabel||it.priority)+'</span>'));
  if(it.dueDate)meta.appendChild(h('<span class="kzc-pill'+(it.overdue?' kzc-pill--overdue':'')+'">'+esc(it.dueDate)+(it.overdue?' ⚠':'')+'</span>'));
  const move=btn(t('Move…'),async()=>{
    const f=await ask(t('Move to…'),null,t('Move'),[{name:'status',label:t('Status'),type:'select',options:[['new',t('New')],['in_progress',t('In progress')],['waiting',t('Waiting')],['done',t('Done')]]}]);
    if(!f)return;
    const r=await put('/api/portal/tasks/'+it.id+'/move',{status:f.status,revision:it.revision});
    if(r.success)vBoard();else if(r.error==='CONFLICT'){status(t('Changed in the meantime — reloaded.'));vBoard();}else status(r.message||t('Error'));
  });
  move.className='kzc-btn kzc-btn--sm';meta.appendChild(move);
  const vis=btn(it.visibility==='status'?t('In portal: on'):t('In portal: off'),async()=>{
    const r=await put('/api/portal/tasks/'+it.id,{portalVisibility:it.visibility==='status'?'none':'status',revision:it.revision});
    if(r.success)vBoard();else if(r.error==='CONFLICT')vBoard();else status(r.message||t('Error'));
  },false);
  vis.className='kzc-btn kzc-btn--sm';meta.appendChild(vis);
  return c;
}

/* ── Requests ── */
async function vRequests(mine){
  VIEW_SEQ++;
  const body=chrome('requests',t('Document requests'));
  body.appendChild(btn(t('+ New request'),async()=>{
    const orgs=(await get('/api/portal/orgs')).data.orgs;
    const f=await ask(t('New request'),null,t('Create'),[
      {name:'orgId',label:t('Client'),type:'select',options:orgs.map(o=>[o.id,o.name])},
      {name:'title',label:t('Title'),required:true},
      {name:'items',label:t('Required documents (one per line)'),type:'textarea'},
      {name:'deadline',label:t('Deadline'),type:'date'},
    ]);
    if(!f)return;
    const items=f.items.split('\n').map(x=>x.trim()).filter(Boolean).map(l=>({label:l,kind:'upload'}));
    if(!items.length){status(t('At least one item is required.'));return;}
    const r=await post('/api/portal/requests',{orgId:+f.orgId,title:f.title,deadline:f.deadline||null,items});
    status(r.success?t('Created.'):(r.message||t('Error')));if(r.success)vRequests();
  },true));
  const mineCb=h('<label class="kzc-check"><input type="checkbox" id="kzc-mine"> '+esc(t('Only my clients'))+'</label>');
  mineCb.querySelector('#kzc-mine').checked=!!mine;
  mineCb.querySelector('#kzc-mine').onchange=e=>vRequests(e.target.checked);
  body.appendChild(mineCb);
  const r=await get('/api/portal/requests'+(mine?'?mine=1':''));
  const reqs=(r.data&&r.data.requests)||[];
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  reqs.forEach(rq=>{
    const row=h('<div class="kzc-card"><b></b><div class="kzc-muted"></div><div class="kzc-items"></div></div>');
    row.querySelector('b').textContent=rq.title;
    row.querySelector('.kzc-muted').textContent=(rq.deadline?t('Deadline')+' '+rq.deadline:t('no deadline'))+(rq.overdue?' — '+t('OVERDUE'):'')+' · '+rq.state+' · '+(rq.items?.accepted??0)+'/'+(rq.items?.total??0)+' '+t('accepted');
    const items=row.querySelector('.kzc-items');
    const detail=h('<details class="kzc-items-detail"><summary>'+esc(t('Details'))+'</summary></details>').firstElementChild;
    items.appendChild(detail);
    row.querySelector('details').addEventListener('toggle',async ev=>{
      if(!ev.target.open||ev.target.dataset.loaded)return;
      ev.target.dataset.loaded='1';
      const itemsList=h('<div class="kzc-itemlist"></div>').firstElementChild;
      detail.appendChild(itemsList);
      const dr=await get('/api/portal/requests/'+rq.id);
      if(!dr.success){itemsList.appendChild(h('<p class="kzc-muted">'+esc(t('Error'))+'</p>'));return;}
      const d=dr.data.request;
      if(d.state==='open'){
        const closeB=btn(t('Close request'),async()=>{
          if(!(await confirmDlg(t('Close request'),t('Marks the request as done even if items are missing. Continue?'))))return;
          const cr=await post('/api/portal/requests/'+d.id+'/close');if(cr.status===204||cr.success){vRequests(mine);}
        });
        closeB.className='kzc-btn kzc-btn--sm';itemsList.appendChild(closeB);
      }
      (d.items||[]).forEach(it=>{
        const ir=h('<div class="kzc-itemrow"><div><b></b><div class="kzc-muted"></div><div class="kzc-itemfiles"></div></div><div class="kzc-itemact"></div></div>');
        ir.querySelector('b').textContent=it.label;
        ir.querySelector('.kzc-muted').textContent=it.state+(it.hint?' · '+it.hint:'')+(it.rejectReason?' — '+t('Rejected')+': '+it.rejectReason:'');
        (it.files||[]).forEach(fid=>{
          const a=h('<a class="kzc-file"></a>').firstElementChild;
          a.textContent='📎 '+t('file')+' #'+fid;a.href=base+'/api/portal/uploads/'+fid+'/file';
          ir.querySelector('.kzc-itemfiles').appendChild(a);
        });
        const act=ir.querySelector('.kzc-itemact');
        if(it.state==='delivered'){
          act.appendChild(btn(t('Accept'),async()=>{await put('/api/portal/requests/'+d.id+'/items/'+it.id+'/review',{accept:true});vRequests(mine);},true));
          act.appendChild(btn(t('Reject'),async()=>{
            const f=await ask(t('Reject item'),t('Reason (shown to the client):'),t('Reject'));
            if(f===null)return;
            await put('/api/portal/requests/'+d.id+'/items/'+it.id+'/review',{accept:false,reason:f.v});
            vRequests(mine);
          }));
        }
        itemsList.appendChild(ir);
      });
    });
    list.appendChild(row);
  });
  if(!reqs.length)list.appendChild(h('<p class="kzc-muted">'+esc(t('No requests.'))+'</p>'));
  body.appendChild(list);
}

/* ── Clients ── */
async function vOrgs(){
  VIEW_SEQ++;
  const body=chrome('orgs',t('Clients'));
  body.appendChild(btn(t('+ New client'),async()=>{
    const f=await ask(t('New client'),null,t('Create'),[
      {name:'name',label:t('Name'),required:true},
      {name:'ref',label:t('Reference (optional)')},
      {name:'kind',label:t('Type'),type:'select',options:[['company',t('Company')],['private',t('Private')],['object',t('Property')],['other',t('Other')]]},
    ]);
    if(!f)return;
    const r=await post('/api/portal/orgs',{name:f.name,ref:f.ref||null,kind:f.kind});
    status(r.success?t('Created.'):(r.message||t('Error')));if(r.success)vOrgs();
  },true));
  if(ME_ADMIN)body.appendChild(btn(t('Import CSV'),async()=>{
    const f=await ask(t('Import clients'),null,t('Check'),[{name:'csv',label:t('CSV (email;name;org;kind;ref)'),type:'textarea',required:true},{name:'invite',label:t('Send invitations? (yes/no)')}]);
    if(!f)return;
    const invite=/^y(es)?$/i.test(f.invite||'');
    const p=await post('/api/portal/import',{csv:f.csv,dryRun:'1'});
    if(!p.success){status(p.message||t('Invalid CSV'));return;}
    const rows=(p.data&&p.data.rows)||[];
    if(!(await confirmDlg(t('Import plan'),rows.length+' '+t('rows')+'. '+t('Apply?'))))return;
    const c=await post('/api/portal/import',{csv:f.csv,dryRun:'0',sendInvitations:invite?'1':'0'});
    status(c.success?t('Imported.')+' '+(c.data&&c.data.applied||0)+' '+t('new'):(c.message||t('Error')));
    vOrgs();
  }));
  const q=new URLSearchParams(location.search).get('q')||'';
  body.appendChild(h('<input id="kzc-q" class="kzc-input" placeholder="'+esc(t('Search…'))+'" value="'+esc(q)+'">'));
  body.querySelector('#kzc-q').oninput=e=>loadOrgs(e.target.value);
  body.appendChild(h('<div id="kzc-orglist" class="kzc-list"></div>'));
  loadOrgs(q);
  async function loadOrgs(term){
    const seq=++ORG_SEQ;
    const rr=await get('/api/portal/orgs'+(term?'?q='+encodeURIComponent(term):''));
    if(seq!==ORG_SEQ)return; // superseded by a newer load — never paint stale
    renderOrgs(rr.data&&rr.data.orgs||[]);
  }
  function renderOrgs(orgs){
    const list=document.getElementById('kzc-orglist');if(!list)return;list.innerHTML='';
    if(!orgs.length){list.appendChild(h('<p class="kzc-muted">'+esc(t('No clients.'))+'</p>'));return;}
    orgs.forEach(o=>{
      const row=h('<button class="kzc-row"><b></b><span class="kzc-muted"></span><span class="kzc-pill"></span></button>').firstElementChild;
      row.querySelector('b').textContent=o.name;
      row.querySelector('.kzc-muted').textContent=[o.ref,o.kind,o.state].filter(Boolean).join(' · ');
      row.querySelector('.kzc-pill').textContent=o.appOn?t('App on'):t('App off');
      row.onclick=()=>openOrg(o.id);list.appendChild(row);
    });
  }
}
async function openOrg(id){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/orgs/'+id);
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  if(!r.success){status(t('Not found.'));return;}
  const o=r.data.org,body=chrome('orgs',t('Client')+': '+o.name);
  const info=h('<div class="kzc-card"><div class="kzc-kv"></div><div class="kzc-actions"></div></div>');
  info.querySelector('.kzc-kv').innerHTML=[
    [t('Name'),o.name],[t('Reference'),o.ref||'—'],[t('Type'),o.kind],[t('Status'),o.state],[t('App access'),o.appOn?t('active'):t('off')],[t('Booking link'),o.bookUrl||'—'],[t('Intake'),(r.data.intake&&r.data.intake.address)||'—'],
  ].map(([k,v])=>'<div class="kzc-kvrow"><span>'+esc(k)+'</span><b>'+esc(v)+'</b></div>').join('');
  const act=info.querySelector('.kzc-actions');
  act.appendChild(btn(o.appOn?t('Deactivate app'):t('Activate app'),async()=>{
    const rr=await put('/api/portal/orgs/'+id,{appOn:o.appOn?false:true});
    if(rr.success)openOrg(id);else if(rr.error==='NO_PORTAL_SLOT')status(t('No free app slot (license).'));else status(rr.message||t('Error'));
  },!o.appOn));
  if(o.state==='active')act.appendChild(btn(t('Offboard'),async()=>{
    if(!(await confirmDlg(t('Offboard'),t('Chat will be archived and members lose access. Continue?'))))return;
    const rr=await post('/api/portal/orgs/'+id+'/offboard');
    const wn=rr&&rr.data&&rr.data.partialWarning==='sync_revoke_failed'?(rr.data.failed||0):0;
    await openOrg(id);
    if(wn>0){
      // failed share revokes leave live oc_share rows — surface, never silently
      const w=h('<p class="kzc-chip kzc-chip--warn kzc-warnbar" role="alert"></p>').firstElementChild;
      w.textContent=n('Offboarded — but 1 synced share could not be revoked. Open Desktop sync below and revoke it again.','Offboarded — but %n synced shares could not be revoked. Open Desktop sync below and revoke them again.',wn);
      const pb=document.getElementById('kzc-body');if(pb)pb.prepend(w);
    }else if(rr&&rr.success===false){status(rr.message||t('Error'));}
  }));
  act.appendChild(btn(t('Edit details'),async()=>{
    const f=await ask(t('Client details'),null,t('Save'),[
      {name:'name',label:t('Name'),required:true,value:o.name},
      {name:'ref',label:t('Reference'),value:o.ref||''},
      {name:'bookUrl',label:t('Booking link (/pub/o/slug, optional)'),value:o.bookUrl||''},
      {name:'pcCustId',label:t('InvoiceCheck customer id (optional)'),value:o.pcCustId||''},
    ]);
    if(!f)return;
    const rr=await put('/api/portal/orgs/'+id,{name:f.name,ref:f.ref||null,bookUrl:f.bookUrl||null,pcCustId:f.pcCustId!==''?+f.pcCustId:null});
    if(!rr.success){status(rr.message||t('Invalid input'));return;}
    openOrg(id);
  }));
  body.appendChild(info);
  // responsible staff contacts (US-P25)
  body.appendChild(h('<h2>'+esc(t('Responsible staff'))+'</h2><div id="kzc-resp"></div>'));
  const resp=document.getElementById('kzc-resp');
  (r.data.contacts||[]).forEach(c=>{
    resp.appendChild(h('<div class="kzc-dev"><span>'+esc(c.user_id||c.userId)+'</span></div>'));
  });
  body.appendChild(btn(t('Set responsible staff'),async()=>{
    const f=await ask(t('Responsible staff'),t('NC user ids (comma-separated, max 5)'),t('Save'));
    if(f===null)return;
    const uids=f.v.split(',').map(x=>x.trim()).filter(Boolean).slice(0,5);
    const rr=await put('/api/portal/orgs/'+id+'/contacts',{userIds:uids});
    status(rr.success||rr.status===204?t('Saved.'):(rr.message||t('Error')));openOrg(id);
  }));
  body.appendChild(h('<h2>'+esc(t('Contacts'))+'</h2><div id="kzc-members"></div>'));
  const ml=document.getElementById('kzc-members');
  (r.data.members||[]).forEach(m=>{
    const row=h('<div class="kzc-dev"><span></span></div>').firstElementChild;
    row.querySelector('span').textContent=(m.displayName||m.email)+(m.status!=='active'?' ('+m.status+')':'');
    if(ME_ADMIN&&m.status==='active'){
      const dis=btn(t('Disable'),async()=>{await put('/api/portal/identities/'+m.identityId+'/status',{status:'disabled'});openOrg(id);},false);
      dis.className='kzc-btn kzc-btn--sm';row.appendChild(dis);
    }else if(ME_ADMIN&&m.status==='disabled'){
      const en=btn(t('Enable'),async()=>{await put('/api/portal/identities/'+m.identityId+'/status',{status:'active'});openOrg(id);},false);
      en.className='kzc-btn kzc-btn--sm';row.appendChild(en);
    }
    const rm=btn(t('Remove'),async()=>{await del('/api/portal/orgs/'+id+'/members/'+m.identityId);openOrg(id);},false);
    rm.className='kzc-btn kzc-btn--sm';row.appendChild(rm);ml.appendChild(row);
  });
  body.appendChild(btn(t('+ Add contact'),async()=>{
    const f=await ask(t('Add contact'),null,t('Add'),[{name:'email',label:t('E-mail'),type:'email',required:true},{name:'displayName',label:t('Name')}]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/members',{email:f.email,displayName:f.displayName});
    status(rr.success?t('Added.'):(rr.message||t('Error')));if(rr.success)openOrg(id);
  }));
  renderSyncSection(id,body,r.data);
  body.appendChild(h('<h2>'+esc(t('Upload links'))+'</h2><div id="kzc-ulinks"></div>'));
  const ul=document.getElementById('kzc-ulinks');
  (r.data.ulinks||[]).forEach(l=>{
    const row=h('<div class="kzc-dev"><span></span></div>').firstElementChild;
    row.querySelector('span').textContent=l.label+' · '+l.usedFiles+'/'+l.maxFiles+(l.revoked?' '+t('(revoked)'):'');
    if(!l.revoked){const rm=btn(t('Revoke'),async()=>{await del('/api/portal/ulinks/'+l.id);openOrg(id);},false);rm.className='kzc-btn kzc-btn--sm';row.appendChild(rm);}
    ul.appendChild(row);
  });
  body.appendChild(btn(t('+ Upload link'),async()=>{
    const f=await ask(t('Create upload link'),null,t('Create'),[
      {name:'label',label:t('Label'),required:true},
      {name:'expiryDays',label:t('Valid (days)'),type:'number'},
      {name:'maxFiles',label:t('Max. files'),type:'number'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/ulinks',{label:f.label,expiryDays:+f.expiryDays||7,maxFiles:+f.maxFiles||20});
    if(rr.success){await ask(t('Link created'),t('URL (visible once):')+' '+rr.data.link.url,t('OK'));}else status(rr.message||t('Error'));
    openOrg(id);
  }));
  // intake address (US-P24)
  body.appendChild(h('<h2>'+esc(t('E-mail intake'))+'</h2>'));
  const inRow=h('<div class="kzc-card"><div class="kzc-kvrow"><span>'+esc(t('Address'))+'</span><b id="kzc-ina"></b></div><div class="kzc-actions"></div></div>');
  inRow.querySelector('#kzc-ina').textContent=(r.data.intake&&r.data.intake.address)||t('disabled');
  inRow.querySelector('.kzc-actions').appendChild(btn(r.data.intake&&r.data.intake.address?t('Disable'):t('Enable'),async()=>{
    const rr=await put('/api/portal/orgs/'+id+'/intake',{enabled:!(r.data.intake&&r.data.intake.address)});
    if(rr.success)openOrg(id);else status(rr.message||t('Error'));
  }));
  inRow.querySelector('.kzc-actions').appendChild(btn(t('Rotate address'),async()=>{
    if(!(await confirmDlg(t('Rotate'),t('The old address stops working immediately. Continue?'))))return;
    await post('/api/portal/orgs/'+id+'/intake/rotate');openOrg(id);
  }));
  body.appendChild(inRow);
  // approvals on documents (US-P23)
  body.appendChild(h('<h2>'+esc(t('Approvals'))+'</h2><div id="kzc-appr"></div>'));
  const ap=document.getElementById('kzc-appr');
  const ar=await get('/api/portal/orgs/'+id+'/approvals');
  if(seq!==VIEW_SEQ)return;
  if(ar.success&&ar.data){
    (ar.data.approvals||[]).forEach(a=>{
      const row=h('<div class="kzc-dev"><span></span></div>').firstElementChild;
      row.querySelector('span').textContent=a.fileName+' · '+a.state+(a.deadline?' · '+a.deadline:'');
      if(a.state==='pending'){const w=btn(t('Withdraw'),async()=>{await post('/api/portal/approvals/'+a.id+'/withdraw');openOrg(id);},false);w.className='kzc-btn kzc-btn--sm';row.appendChild(w);}
      const pf=h('<a class="kzc-btn kzc-btn--sm"></a>').firstElementChild;pf.textContent=t('Proof');pf.href=base+'/api/portal/approvals/'+a.id+'/proof';row.appendChild(pf);
      ap.appendChild(row);
    });
  }
  body.appendChild(btn(t('+ Request approval'),async()=>{
    const docs=await get('/api/portal/orgs/'+id+'/documents');
    const files=(docs.data&&docs.data.files)||[];
    if(!files.length){status(t('No documents in this client\'s Dokumente folder yet.'));return;}
    const f=await ask(t('Request approval'),null,t('Send'),[
      {name:'fileId',label:t('Document'),type:'select',options:files.map(fl=>[fl.id,fl.name])},
      {name:'deadline',label:t('Deadline'),type:'date'},
      {name:'note',label:t('Note'),type:'textarea'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/approvals',{fileId:+f.fileId,deadline:f.deadline||null,note:f.note||null});
    status(rr.success?t('Sent.'):(rr.message||t('Error')));if(rr.success)openOrg(id);
  },true));
  // send questionnaire
  body.appendChild(btn(t('+ Send questionnaire'),async()=>{
    const tpls=await get('/api/portal/form-templates');
    const tt=(tpls.data&&tpls.data.templates)||[];
    if(!tt.length){status(t('No form templates yet — create one under Questionnaires.'));return;}
    const f=await ask(t('Send questionnaire'),null,t('Send'),[{name:'tplId',label:t('Template'),type:'select',options:tt.map(tp=>[tp.id,tp.name])}]);
    if(!f)return;
    const rr=await post('/api/portal/forms',{orgId:id,tplId:+f.tplId});
    status(rr.success?t('Sent.'):(rr.message||t('Error')));
  }));
  // invoices (InvoiceCheck facade)
  if(r.data.invoiceAvailable){
    body.appendChild(h('<h2>'+esc(t('Invoices'))+'</h2>'));
    const inv=(r.data.invoices)||[];
    if(!inv.length)body.appendChild(h('<p class="kzc-muted">'+esc(t('No invoices.'))+'</p>'));
    else body.appendChild(h('<div class="kzc-card">'+inv.map(i=>'<div class="kzc-kvrow"><span>'+esc(i.ref||('#'+i.id))+(i.overdue?' ⚠':'')+'</span><b>'+esc(i.status)+'</b></div>').join('')+'</div>'));
  }
}

/* ── Desktop-Sync (nc-user-binding §7) ──
   One control per member: toggle + chip. Three display states only
   (D-B20): Aktiv | Ausstehend | Problem+repair. The two-layer model
   (identity bind vs org share) never leaks into the UI (D-B19). */
const SYNC_CHIP={
  active:['ok',()=>t('Active')],
  pending_accept:['warn',()=>t('Pending (recipient must accept the share)')],
  disabled:['muted',()=>t('Paused by you')],
  broken_user:['bad',()=>t('Problem — account missing or disabled')],
  broken_share:['bad',()=>t('Problem — share missing')],
  broken_perms:['bad',()=>t('Problem — permissions changed')],
  staff_conflict:['bad',()=>t('Problem — account is staff')],
};
function syncChip(state){
  const c=SYNC_CHIP[state]||['muted',()=>state];
  const el=h('<span class="kzc-chip kzc-chip--'+c[0]+'"><span class="kzc-chip-label"></span></span>').firstElementChild;
  el.querySelector('.kzc-chip-label').textContent=c[1]();
  return el;
}
async function syncSetupDialog(orgId,member){
  const s=await get('/api/portal/identities/'+member.identityId+'/nc-suggest');
  if(!s.success){status(s.message||t('Error'));return;}
  if(!s.data.doorRestricted){status(t('Desktop sync requires a restricted access door — see Settings → Access (door).'));return;}
  if(s.data.ncUid){
    // already bound (AC-3): show the bound account + inline unbind escape
    const u=await confirmDlg(t('Already linked'),t('This person is linked to Nextcloud account')+' "'+s.data.ncUid+'". '+t('Unlink now?'));
    if(u){const rr=await api('/api/portal/identities/'+member.identityId+'/nc-bind',{method:'DELETE'});status(rr.success?t('Unlinked.'):(rr.message||t('Error')));openOrg(orgId);}
    return;
  }
  const dlg=document.createElement('dialog');dlg.className='kzc-dialog';
  dlg.innerHTML='<form method="dialog" class="kzc-card"><h2>'+esc(t('Set up desktop sync'))+'</h2>'
    +'<p class="kzc-muted">'+esc(t('The client receives their files as a synced folder on their PC — via their existing Nextcloud account.'))+'</p>'
    +'<label class="kzc-label" for="sd-q">'+esc(t('Nextcloud account'))+'</label><input id="sd-q" class="kzc-input" autocomplete="off"><div id="sd-hits" class="kzc-list"></div><input id="sd-uid" type="hidden">'
    +'<fieldset class="kzc-fieldset"><legend>'+esc(t('Access'))+'</legend>'
    +'<label class="kzc-label"><input type="radio" name="sd-perms" value="rw" checked> '+esc(t('Read & write'))+'</label>'
    +'<label class="kzc-label"><input type="radio" name="sd-perms" value="ro"> '+esc(t('Read only'))+'</label></fieldset>'
    +'<p id="sd-err" class="kzc-field-error" role="alert" hidden></p>'
    +'<div class="kzc-actions"><button value="" class="kzc-btn">'+esc(t('Cancel'))+'</button><button value="ok" class="kzc-btn kzc-btn--primary" disabled>'+esc(t('Link & enable'))+'</button></div></form>';
  document.body.appendChild(dlg);
  const q=dlg.querySelector('#sd-q'),hits=dlg.querySelector('#sd-hits'),uidI=dlg.querySelector('#sd-uid'),okB=dlg.querySelector('.kzc-btn--primary'),err=dlg.querySelector('#sd-err');
  const sug=s.data.suggest;
  let sdSeq=0;
  const fill=async(term)=>{
    const seq=++sdSeq;
    const r=await get('/api/portal/users/search?q='+encodeURIComponent(term));
    if(seq!==sdSeq||!dlg.isConnected)return; // superseded/closed — never paint stale hits
    hits.innerHTML='';
    ((r.data&&r.data.users)||[]).forEach(u=>{
      const b=document.createElement('button');b.type='button';b.className='kzc-row';
      b.textContent=u.displayName+' ('+u.uid+')'+(u.email?' — '+u.email:'')+(u.staff?' ⚠ '+t('staff'):'');
      if(u.staff){b.disabled=true;b.title=t('Staff accounts cannot be linked');}
      b.onclick=()=>{uidI.value=u.uid;q.value=u.displayName+' ('+u.uid+')';okB.disabled=false;hits.innerHTML='';};
      hits.appendChild(b);
    });
  };
  if(sug){uidI.value=sug.uid;q.value=sug.displayName+' ('+sug.uid+')';okB.disabled=false;}
  let deb=null;q.addEventListener('input',()=>{clearTimeout(deb);uidI.value='';okB.disabled=true;deb=setTimeout(()=>fill(q.value),300);});
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  dlg.addEventListener('close',async()=>{
    if(dlg.returnValue!=='ok'){dlg.remove();return;}
    const perms=dlg.querySelector('input[name="sd-perms"]:checked').value;
    const attempt=async(acceptMismatch,acceptShared)=>{
      const r=await put('/api/portal/identities/'+member.identityId+'/nc-bind',{ncUid:uidI.value,acceptMismatch:acceptMismatch?'1':'0',acceptShared:acceptShared?'1':'0'});
      if(r.success)return 'ok';
      return r.error||'ERR';
    };
    const bindAndEnable=async()=>{
      const res=await attempt(false,false);
      if(res==='SYNC_EMAIL_MISMATCH'||res==='SYNC_UID_SHARED'){
        // confirm gate stays inside the same dialog — the click IS the control
        const warn=res==='SYNC_EMAIL_MISMATCH'?t('The account e-mail differs from the portal e-mail. Link anyway?'):t('This account is already linked to another person. Link anyway?');
        if(!(await confirmDlg(t('Please confirm'),warn)))return 'aborted';
        const again=await attempt(true,true);
        if(again!=='ok')return again;
      }else if(res!=='ok')return res;
      const en=await post('/api/portal/orgs/'+orgId+'/sync',{identityId:member.identityId,perms});
      return en.success?'ok':(en.error||'ERR');
    };
    const out=await bindAndEnable();
    const msg={ok:t('Desktop sync enabled.'),SYNC_UID_UNKNOWN:t('Account not found.'),SYNC_UID_IS_STAFF:t('Staff accounts cannot be linked'),SYNC_UID_DISABLED:t('Activate the account in user administration first.'),SYNC_ALREADY_BOUND:t('Already linked — unlink first.'),SYNC_NOT_BOUND:t('Link the account first.'),SYNC_ORG_OFFBOARDED:t('Client is offboarded.'),SYNC_DOOR_OPEN:t('Desktop sync requires a restricted access door.'),NO_SHARE_PERM:t('Check share permission on the documents folder.'),NO_DOCS_ROOT:t('Documents root is not configured.'),aborted:t('Cancelled.')}[out]||t('Error');
    status(msg);
    dlg.remove();
    openOrg(orgId);
  });
  dlg.showModal();
}
function renderSyncSection(orgId,body,data){
  body.appendChild(h('<h2>'+esc(t('Desktop sync'))+'</h2>'));
  const sec=h('<div class="kzc-card"><p class="kzc-muted"></p><div class="kzc-sync-list"></div></div>').firstElementChild;
  sec.querySelector('.kzc-muted').textContent=t('The client receives their files as a synced folder on their PC — via their existing Nextcloud account.');
  body.appendChild(sec);
  const list=sec.querySelector('.kzc-sync-list');
  if(!data.doorRestricted){
    const n=h('<p class="kzc-muted"></p>').firstElementChild;
    n.textContent=t('Desktop sync is unavailable while the access door is open — restrict it under Settings → Access (door).');
    list.appendChild(n);return; // proactive notice (US-S16), no dead-end error
  }
  const bindings={};(data.sync||[]).forEach(b=>bindings[b.identityId]=b);
  const members=(data.members||[]).filter(m=>m.status==='active');
  if(!members.length){list.appendChild(h('<p class="kzc-muted">'+esc(t('No contacts yet.'))+'</p>'));return;}
  members.forEach(m=>{
    const row=h('<div class="kzc-dev kzc-sync-row"></div>').firstElementChild;
    const label=h('<span></span>').firstElementChild;
    label.textContent=(m.displayName||m.email)+(m.ncUid?' — '+(m.ncDisplay||m.ncUid):'');
    row.appendChild(label);
    const b=bindings[m.identityId];
    const wrap=h('<span class="kzc-actions"></span>').firstElementChild;
    if(b){
      wrap.appendChild(syncChip(b.state));
      if(m.ncUidMismatch){const w=h('<span class="kzc-chip kzc-chip--warn"></span>').firstElementChild;w.textContent=t('e-mail differs');wrap.appendChild(w);}
      if(ME_ADMIN&&['broken_share','broken_perms'].includes(b.state)){
        const rp=btn(t('Repair'),async()=>{const rr=await post('/api/portal/sync/'+b.bindingId+'/repair',{});status(rr.success?t('Repaired.'):(rr.message||t('Error')));openOrg(orgId);},true);rp.className='kzc-btn kzc-btn--sm';wrap.appendChild(rp);
      }
      if(ME_ADMIN){
        const tog=btn(t('Off'),async()=>{
          if(!(await confirmDlg(t('Turn off sync'),t('The client loses folder access via their Nextcloud account. Continue?'))))return;
          const rr=await api('/api/portal/sync/'+b.bindingId,{method:'DELETE'});status(rr.success?t('Sync off.'):(rr.message||t('Error')));openOrg(orgId);
        });tog.className='kzc-btn kzc-btn--sm';wrap.appendChild(tog);
      }
    }else if(ME_ADMIN){
      const st=btn(t('Set up'),()=>syncSetupDialog(orgId,m),true);st.className='kzc-btn kzc-btn--sm';wrap.appendChild(st);
    }
    row.appendChild(wrap);
    list.appendChild(row);
  });
}

/* ── Uploads ── */
async function vUploads(){
  VIEW_SEQ++;
  const body=chrome('uploads',t('Inbox — upload queue'));
  const r=await get('/api/portal/uploads');
  const ups=(r.data&&r.data.uploads)||[];
  if(!ups.length){body.appendChild(h('<p class="kzc-muted">'+esc(t('No new uploads.'))+'</p>'));}
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  ups.forEach(u=>{
    const row=h('<div class="kzc-card"><b></b><div class="kzc-muted"></div><div class="kzc-actions"></div></div>');
    row.querySelector('b').textContent=u.fileName;
    row.querySelector('.kzc-muted').textContent=u.orgName+' · '+u.uploader+' · '+u.source+' · '+new Date(u.createdAt).toLocaleString()+(u.duplicate?' · '+t('DUPLICATE'):'')+(u.note?' · '+u.note:'');
    const act=row.querySelector('.kzc-actions');
    const dl=h('<a class="kzc-btn"></a>').firstElementChild;dl.textContent=t('Download');dl.href=base+'/api/portal/uploads/'+u.id+'/file';act.appendChild(dl);
    act.appendChild(btn(t('Seen'),async()=>{await put('/api/portal/uploads/'+u.id+'/review',{state:'eingesehen'});vUploads();}));
    act.appendChild(btn(t('Filed'),async()=>{await put('/api/portal/uploads/'+u.id+'/review',{state:'abgelegt'});vUploads();},true));
    act.appendChild(btn(t('Publish to client'),async()=>{
      const rr=await post('/api/portal/uploads/'+u.id+'/publish');
      status(rr.success?t('Published to Dokumente.'):(rr.message||t('Error')));if(rr.success)vUploads();
    }));
    list.appendChild(row);
  });
  body.appendChild(list);
}

/* ── Settings ── */
async function vSettings(){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/settings');
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  const body=chrome('settings',t('Settings'));
  if(!r.success){
    body.appendChild(h('<div class="kzc-card"><h2>'+esc(t('No access'))+'</h2><p class="kzc-muted">'+esc(t('Settings are only visible to KanzleiCheck app admins. Your account is not on the app-admin list.'))+'</p><p class="kzc-muted">'+esc(t('Next step: ask a Nextcloud administrator to add your account under Settings → Access (door), or to grant you the App Admin role.'))+'</p></div>'));
    body.appendChild(h('<div class="kzc-card"><h2>'+esc(t('About this project'))+'</h2><p class="kzc-muted">'+esc(t('Sponsor, open-source license and project links.'))+'</p><div class="kzc-actions"><a class="kzc-btn" href="'+esc(base+'/settings/about')+'">'+esc(t('Open about page'))+'</a></div></div>'));
    return;
  }
  const s=r.data.settings,d=r.data.door;
  const form=h('<div class="kzc-card"><h2>'+esc(t('General'))+'</h2><label class="kzc-label" for="s-preset">'+esc(t('Industry (preset)'))+'</label><select id="s-preset" class="kzc-input"></select><label class="kzc-label" for="s-label">'+esc(t('Provider name (e-mails)'))+'</label><input id="s-label" class="kzc-input"><label class="kzc-label" for="s-max">'+esc(t('Upload limit (MB)'))+'</label><input id="s-max" class="kzc-input" type="number"><label class="kzc-label" for="s-ttl">'+esc(t('OTP validity (minutes)'))+'</label><input id="s-ttl" class="kzc-input" type="number"><div class="kzc-actions"></div></div>').firstElementChild;
  const sel=form.querySelector('#s-preset');
  r.data.presets.forEach(p=>sel.appendChild(new Option(p,p)));
  sel.value=s.preset;form.querySelector('#s-label').value=s.providerLabel;form.querySelector('#s-max').value=s.uploadMaxMb;form.querySelector('#s-ttl').value=s.otpTtlMin;
  form.querySelector('.kzc-actions').appendChild(btn(t('Save'),async()=>{
    const rr=await put('/api/portal/settings',{preset:sel.value,providerLabel:form.querySelector('#s-label').value,uploadMaxMb:+form.querySelector('#s-max').value,otpTtlMin:+form.querySelector('#s-ttl').value});
    status(rr.success?t('Saved.'):(rr.message||t('Error')));
  },true));
  body.appendChild(form);
  // door
  const door=h('<div class="kzc-card"><h2>'+esc(t('Access (door)'))+'</h2><label class="kzc-label"><input type="checkbox" id="d-restr"> '+esc(t('Restricted (selected groups/users only)'))+'</label><p class="kzc-muted">'+esc(t('Platform restriction:'))+' '+(d.platformGroups.length?esc(d.platformGroups.join(', ')):esc(t('none')))+'</p><label class="kzc-label" for="d-users">'+esc(t('Allowed users (comma-separated)'))+'</label><input id="d-users" class="kzc-input"><label class="kzc-label" for="d-admins">'+esc(t('App admins (comma-separated)'))+'</label><input id="d-admins" class="kzc-input"><div class="kzc-actions"></div></div>').firstElementChild;
  door.querySelector('#d-restr').checked=d.restricted;
  door.querySelector('#d-users').value=(d.allowedUsers||[]).join(', ');
  door.querySelector('#d-admins').value=(d.appAdmins||[]).join(', ');
  door.querySelector('.kzc-actions').appendChild(btn(t('Save door'),async()=>{
    const csv=v=>v.split(',').map(x=>x.trim()).filter(Boolean);
    const rr=await put('/api/portal/door',{restricted:door.querySelector('#d-restr').checked,groups:[],users:csv(door.querySelector('#d-users').value),appAdmins:csv(door.querySelector('#d-admins').value)});
    status(rr.success?t('Saved.'):(rr.message||t('Error')));
  },true));
  body.appendChild(door);
  // config & data transfer — dedicated server-rendered page (synced part)
  const xfer=h('<div class="kzc-card"><h2>'+esc(t('Transfer configuration'))+'</h2><p class="kzc-muted">'+esc(t('Copy all settings, catalogues and business data between a test and a production instance — one encrypted file, dry-run before anything is written.'))+'</p><div class="kzc-actions"><a class="kzc-btn" href="'+esc(base+'/settings/transfer')+'">'+esc(t('Open transfer'))+'</a></div></div>').firstElementChild;
  body.appendChild(xfer);
  // about this project — sponsor + open source (dedicated page, all staff)
  const about=h('<div class="kzc-card"><h2>'+esc(t('About this project'))+'</h2><p class="kzc-muted">'+esc(t('Sponsor, open-source license and project links.'))+'</p><div class="kzc-actions"><a class="kzc-btn" href="'+esc(base+'/settings/about')+'">'+esc(t('Open about page'))+'</a></div></div>').firstElementChild;
  body.appendChild(about);
  // license + diagnostics
  const l=await get('/api/portal/license');
  if(seq!==VIEW_SEQ)return;
  if(l.success&&l.data){
    const lic=l.data.license;
    body.appendChild(h('<div class="kzc-card"><h2>'+esc(t('License'))+'</h2><p>'+esc(t('Status:'))+' <b>'+esc(lic.status)+'</b> · '+esc(t('Portal clients:'))+' '+lic.usedPortalClients+'/'+lic.portalClients+' · '+esc(t('Mobile seats:'))+' '+(lic.mobileSeats??'—')+'</p><div id="l-actions"></div></div>'));
    body.querySelector('#l-actions').appendChild(btn(t('Apply license key'),async()=>{
      const f=await ask(t('KZC2 key'),t('Paste key:'),t('Apply'));
      if(!f)return;
      const rr=await post('/api/portal/license',{key:f.v});
      status(rr.success?t('Applied.'):(rr.message||t('Invalid key')));if(rr.success)vSettings();
    }));
  }
  const diag=await get('/api/portal/diagnostics');
  if(seq!==VIEW_SEQ)return;
  if(diag.success&&diag.data){
    const g=diag.data;
    body.appendChild(h('<div class="kzc-card"><h2>'+esc(t('Diagnostics'))+'</h2><p>'+esc(t('Cron:'))+' '+(g.cron.stale?'<b>'+esc(t('stale'))+'</b>':esc(t('ok')))+' · '+esc(t('Outbox:'))+' '+g.outbox.pending+' '+t('pending')+' / '+g.outbox.failed+' '+t('failed')+' · '+esc(t('Documents root:'))+' '+(g.docsRoot.healthy?esc(t('ok')):esc(t('broken — configure')))+' · '+esc(t('License:'))+' '+esc(g.license.status)+'</p></div>'));
  }
}

/* ── Mail intake holds (US-P24) ── */
async function vHolds(){
  VIEW_SEQ++;
  const body=chrome('holds',t('Mail review'));
  const r=await get('/api/portal/intake-holds');
  const holds=(r.data&&r.data.holds)||[];
  if(!holds.length){body.appendChild(h('<p class="kzc-muted">'+esc(t('No held e-mails.'))+'</p>'));return;}
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  holds.forEach(hh=>{
    const row=h('<div class="kzc-card"><b></b><div class="kzc-muted"></div><div class="kzc-actions"></div></div>');
    row.querySelector('b').textContent=t('Hold')+' #'+hh.id+' · '+t('org')+' '+hh.orgId;
    row.querySelector('.kzc-muted').textContent=new Date(hh.receivedAt).toLocaleString()+' · '+hh.state;
    const act=row.querySelector('.kzc-actions');
    if(hh.state==='held'){
      act.appendChild(btn(t('Accept'),async()=>{
        const rr=await post('/api/portal/intake-holds/'+hh.id+'/decide',{action:'accept'});
        status(rr.success?t('Filed to Eingang.')+' '+(rr.data&&rr.data.stored||0)+' '+t('files'):(rr.message||t('Error')));if(rr.success)vHolds();
      },true));
      act.appendChild(btn(t('Discard'),async()=>{
        if(!(await confirmDlg(t('Discard'),t('Attachments are deleted permanently. Continue?'))))return;
        await post('/api/portal/intake-holds/'+hh.id+'/decide',{action:'discard'});vHolds();
      }));
    }
    list.appendChild(row);
  });
  body.appendChild(list);
}

/* ── Questionnaires (staff side, US-P28) ── */
async function vForms(){
  VIEW_SEQ++;
  const body=chrome('forms',t('Questionnaires'));
  if(ME_ADMIN)body.appendChild(btn(t('+ New template'),async()=>{
    const f=await ask(t('New form template'),null,t('Create'),[
      {name:'name',label:t('Name'),required:true},
      {name:'fields',label:t('Fields (one per line: key|label|type|required)',),type:'textarea',required:true},
    ]);
    if(!f)return;
    const fields=f.fields.split('\n').map(x=>x.trim()).filter(Boolean).map(line=>{
      const p=line.split('|').map(x=>x.trim());
      return {key:p[0]||'',label:p[1]||p[0]||'',type:p[2]||'text',required:/^(1|true|yes)$/i.test(p[3]||'')};
    });
    const rr=await post('/api/portal/form-templates',{name:f.name,fields});
    status(rr.success?t('Created.'):(rr.message||t('Error')));if(rr.success)vForms();
  },true));
  const tr=await get('/api/portal/form-templates');
  const tpls=(tr.data&&tr.data.templates)||[];
  body.appendChild(h('<h2>'+esc(t('Templates'))+'</h2>'));
  if(!tpls.length)body.appendChild(h('<p class="kzc-muted">'+esc(t('No templates.'))+'</p>'));
  tpls.forEach(tp=>{
    body.appendChild(h('<div class="kzc-card"><b>'+esc(tp.name)+'</b><div class="kzc-muted">'+esc((tp.fields||[]).length)+' '+esc(t('fields'))+'</div></div>'));
  });
  body.appendChild(h('<h2>'+esc(t('Forms sent'))+'</h2>'));
  const fr=await get('/api/portal/forms');
  const forms=(fr.data&&fr.data.forms)||[];
  const orgs=await get('/api/portal/orgs');
  const orgNames={};(orgs.data&&orgs.data.orgs||[]).forEach(o=>orgNames[o.id]=o.name);
  if(!forms.length)body.appendChild(h('<p class="kzc-muted">'+esc(t('No forms sent yet.'))+'</p>'));
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  forms.forEach(f=>{
    const row=h('<div class="kzc-card"><b></b><div class="kzc-muted"></div><div class="kzc-actions"></div></div>');
    row.querySelector('b').textContent=(orgNames[f.orgId]||'#'+f.orgId)+' · '+t('Form')+' #'+f.id;
    row.querySelector('.kzc-muted').textContent=f.state+(f.submittedAt?' · '+new Date(f.submittedAt).toLocaleString():'');
    const act=row.querySelector('.kzc-actions');
    act.appendChild(btn(t('Answers'),()=>openForm(f.id),true));
    if(f.state==='submitted')act.appendChild(btn(t('Reopen'),async()=>{await post('/api/portal/forms/'+f.id+'/reopen');vForms();}));
    const csv=h('<a class="kzc-btn kzc-btn--sm"></a>').firstElementChild;csv.textContent='CSV';csv.href=base+'/api/portal/forms/'+f.id+'/export.csv';act.appendChild(csv);
    list.appendChild(row);
  });
  body.appendChild(list);
}
async function openForm(id){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/forms/'+id);
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  if(!r.success){status(t('Not found.'));return;}
  const f=r.data.form,body=chrome('forms',t('Form')+' #'+id+' — '+f.name);
  const card=h('<div class="kzc-card"></div>').firstElementChild;
  (f.fields||[]).forEach(fd=>{
    const ans=(f.answers||[]).find(a=>a.key===fd.key);
    card.appendChild(h('<div class="kzc-kvrow"><span>'+esc(fd.label)+'</span><b>'+esc(ans?String(ans.value):'—')+'</b></div>'));
  });
  body.appendChild(card);
  if(f.state==='submitted')body.appendChild(btn(t('Reopen for the client'),async()=>{await post('/api/portal/forms/'+f.id+'/reopen');openForm(id);}));
}

/* ── People (identities admin, US-P07) ── */
async function vPeople(){
  VIEW_SEQ++;
  const body=chrome('people',t('People'));
  if(!ME_ADMIN){body.appendChild(h('<p class="kzc-muted">'+esc(t('App admins only'))+'</p>'));return;}
  body.appendChild(h('<input id="kzc-pq" class="kzc-input" placeholder="'+esc(t('Search e-mail or name…'))+'">'));
  body.appendChild(h('<div id="kzc-plist" class="kzc-list"></div>'));
  const render=async q=>{
    const seq=++PQ_SEQ;
    const r=await get('/api/portal/identities?q='+encodeURIComponent(q));
    if(seq!==PQ_SEQ)return; // superseded by a newer load — never paint stale
    const list=document.getElementById('kzc-plist');if(!list)return;list.innerHTML='';
    (r.data&&r.data.identities||[]).forEach(i=>{
      const row=h('<div class="kzc-dev"><span></span></div>').firstElementChild;
      row.querySelector('span').textContent=(i.displayName||'')+' <'+i.email+'>'+(i.status!=='active'?' ('+i.status+')':'');
      const st=btn(i.status==='disabled'?t('Enable'):t('Disable'),async()=>{
        await put('/api/portal/identities/'+i.id+'/status',{status:i.status==='disabled'?'active':'disabled'});render(document.getElementById('kzc-pq').value);
      },false);st.className='kzc-btn kzc-btn--sm';row.appendChild(st);
      const ex=btn(t('Export'),async()=>{
        const rj=await get('/api/portal/identities/'+i.id+'/export');
        const blob=new Blob([JSON.stringify(rj.data??rj,null,2)],{type:'application/json'});
        const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='identity-'+i.id+'.json';a.click();
      },false);ex.className='kzc-btn kzc-btn--sm';row.appendChild(ex);
      const er=btn(t('Erase'),async()=>{
        if(!(await confirmDlg(t('Erase person'),t('All personal data is erased permanently. Continue?'))))return;
        await del('/api/portal/identities/'+i.id);render(document.getElementById('kzc-pq').value);
      },false);er.className='kzc-btn kzc-btn--sm';row.appendChild(er);
      list.appendChild(row);
    });
  };
  body.querySelector('#kzc-pq').oninput=e=>render(e.target.value);
  render('');
}

const VIEWS={overview:vOverview,inbox:vInbox,board:vBoard,requests:vRequests,orgs:vOrgs,uploads:vUploads,holds:vHolds,forms:vForms,people:vPeople,settings:vSettings};
(async()=>{
  const d=await get('/api/portal/settings');ME_ADMIN=d.success===true;
  // Deep links (notification mails land ?view=inbox&org=N — see NotifyService).
  const p=new URLSearchParams(location.search);
  const v=p.get('view'),orgId=parseInt(p.get('org')||'',10);
  if(v==='inbox'&&orgId>0){
    const oRes=await get('/api/portal/orgs');
    const o=(oRes.data&&oRes.data.orgs||[]).find(x=>x.id===orgId);
    if(o){openConv(orgId,o.name);return;}
  }
  (VIEWS[v]||vOverview)();
})();
})();
