(function(){'use strict';
/* PortalCheck staff SPA: Overview · Messages · Tasks · Requests ·
   Clients · Uploads · Settings. esc() for all user data — no innerHTML. */
const el=document.getElementById('ptl-staff-view'),base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('portalcheck',s):s;
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json'}},o||{}));if(r.status===204)return{success:true};return r.json().catch(()=>({success:false}));};
const get=u=>api(u),post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})}),put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})}),del=u=>api(u,{method:'DELETE'});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};
const btn=(label,fn,primary)=>{const b=document.createElement('button');b.className='ptl-btn'+(primary?' ptl-btn--primary':'');b.textContent=label;b.onclick=fn;return b;};
const status=t=>{const s=document.getElementById('ptl-live');if(s)s.textContent=t||'';document.getElementById('ptl-loading')?.remove();};
let ME_ADMIN=false;

/* native dialog helpers (never window.prompt/confirm) */
function ask(title,label,okLabel,fields){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='ptl-dialog';
  let inner='<form method="dialog" class="ptl-card"><h2></h2>';
  (fields||[{name:'v',label}]).forEach(f=>{
    inner+='<label class="ptl-label" for="fd-'+esc(f.name)+'"></label>';
    inner+=f.type==='select'?'<select id="fd-'+esc(f.name)+'" class="ptl-input" name="'+esc(f.name)+'">'+(f.options||[]).map(o=>'<option value="'+esc(o[0])+'">'+esc(o[1])+'</option>').join('')+'</select>'
      :f.type==='textarea'?'<textarea id="fd-'+esc(f.name)+'" class="ptl-input" name="'+esc(f.name)+'" rows="3"></textarea>'
      :'<input id="fd-'+esc(f.name)+'" class="ptl-input" name="'+esc(f.name)+'" type="'+esc(f.type||'text')+'" '+(f.required?'required':'')+'>';
  });
  inner+='<div class="ptl-actions"><button value="" class="ptl-btn">'+esc(t('Cancel'))+'</button><button value="ok" class="ptl-btn ptl-btn--primary"></button></div></form>';
  dlg.innerHTML=inner;dlg.querySelector('h2').textContent=title;
  dlg.querySelectorAll('.ptl-label').forEach((l,i)=>{const f=(fields||[{label}])[i];l.textContent=f.label;});
  dlg.querySelector('.ptl-btn--primary').textContent=okLabel;
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  dlg.addEventListener('close',()=>{
    if(dlg.returnValue!=='ok'){res(null);dlg.remove();return;}
    const out={};dlg.querySelectorAll('[name]').forEach(i=>out[i.name]=i.value);res(out);dlg.remove();
  });
  document.body.appendChild(dlg);dlg.showModal();
});}
function confirmDlg(title,text){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='ptl-dialog';
  dlg.innerHTML='<form method="dialog" class="ptl-card"><h2></h2><p class="ptl-muted"></p><div class="ptl-actions"><button value="" class="ptl-btn">'+esc(t('Cancel'))+'</button><button value="ok" class="ptl-btn ptl-btn--primary">'+esc(t('Confirm'))+'</button></div></form>';
  dlg.querySelector('h2').textContent=title;dlg.querySelector('.ptl-muted').textContent=text;
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  dlg.addEventListener('close',()=>{res(dlg.returnValue==='ok');dlg.remove();});
  document.body.appendChild(dlg);dlg.showModal();});}

const NAV=[['overview',t('Overview')],['inbox',t('Messages')],['board',t('Tasks')],['requests',t('Requests')],['orgs',t('Clients')],['uploads',t('Uploads')],['holds',t('Mail review')],['forms',t('Questionnaires')],['people',t('People')],['settings',t('Settings')]];
function chrome(active,title){
  el.innerHTML='<nav class="ptl-nav" aria-label="'+esc(t('Sections'))+'">'+NAV.map(([k,l])=>'<button class="ptl-navbtn'+(k===active?' ptl-navbtn--active':'')+'" data-v="'+k+'">'+esc(l)+'</button>').join('')+'</nav><h1>'+esc(title)+'</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div><div id="ptl-body"><p class="ptl-muted" id="ptl-loading" role="status"></p></div>';
  const ld=document.getElementById('ptl-loading');ld.textContent=t('Loading…');
  el.querySelectorAll('.ptl-navbtn').forEach(b=>b.onclick=()=>{status(t('Loading…'));VIEWS[b.dataset.v]();});
  const body=document.getElementById('ptl-body');
  // instant feedback on every view switch: clear the loading placeholder on
  // the first real insert (covers success, error and early-return paths).
  const orig=body.appendChild.bind(body);
  let cleared=false;
  body.appendChild=n=>{if(!cleared){cleared=true;ld.remove();}return orig(n);};
  return body;
}

/* ── Overview ── */
async function vOverview(){
  const r=await get('/api/portal/overview');
  const body=chrome('overview',t('What needs attention?'));
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||t('Error'))+'</p>'));return;}
  const su=await get('/api/portal/setup');
  if(su.success&&su.data&&su.data.setup&&!su.data.setup.done){
    const coach=h('<div class="ptl-card ptl-coach"><h2>'+esc(t('Initial setup'))+'</h2><ul></ul></div>');
    const ul=coach.querySelector('ul');
    if(!su.data.setup.preset)ul.appendChild(h('<li>'+esc(t('Choose an industry preset in Settings.'))+'</li>'));
    if(!su.data.setup.docsRoot)ul.appendChild(h('<li>'+esc(t('Set the documents root folder in Settings (create a folder in Files first).'))+'</li>'));
    if(!su.data.setup.orgs)ul.appendChild(h('<li>'+esc(t('Create your first client under Clients, or import a CSV.'))+'</li>'));
    body.appendChild(coach);
  }
  const s=r.data.stats;
  body.appendChild(h('<div class="ptl-stats">'+[
    [s.unread,t('Unread messages'),'inbox'],
    [s.newUploads,t('New uploads'),'uploads'],
    [s.overdueRequests,t('Overdue requests'),'requests'],
    [s.openApprovals,t('Open approvals'),'requests'],
  ].map(([n,l,v])=>'<button class="ptl-stat" data-v="'+v+'"><b>'+n+'</b>'+esc(l)+'</button>').join('')+'</div>'));
  body.querySelectorAll('.ptl-stat').forEach(b=>b.onclick=()=>VIEWS[b.dataset.v]());
  const q=r.data.queue||[];
  if(q.length){
    body.appendChild(h('<h2>'+esc(t('Recently active'))+'</h2>'));
    const list=h('<div class="ptl-list"></div>');
    q.slice(0,8).forEach(c=>{
      const row=h('<button class="ptl-row"><b></b><span class="ptl-muted"></span>'+(c.unread?'<span class="ptl-badge">'+c.unread+'</span>':'')+'</button>');
      row.setAttribute('aria-label',c.orgName||t('Client'));
      row.querySelector('b').textContent=c.orgName||t('Client');row.querySelector('.ptl-muted').textContent=c.lastPreview||'';
      row.onclick=()=>openConv(c.orgId,c.orgName);list.appendChild(row);
    });
    body.appendChild(list);
  } else body.appendChild(h('<p class="ptl-muted">'+esc(t('All done — no open items.'))+'</p>'));
}

/* ── Messages (inbox + thread) ── */
async function vInbox(mine){
  const body=chrome('inbox',t('Messages'));
  const mineCb=h('<label class="ptl-check"><input type="checkbox" id="ptl-mine"> '+esc(t('Only my clients'))+'</label>');
  mineCb.querySelector('#ptl-mine').checked=!!mine;
  mineCb.querySelector('#ptl-mine').onchange=e=>vInbox(e.target.checked);
  body.appendChild(mineCb);
  const r=await get('/api/portal/inbox'+(mine?'?mine=1':''));
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||t('Error'))+'</p>'));return;}
  const convs=r.data.conversations||[];
  if(!convs.length){body.appendChild(h('<p class="ptl-muted">'+esc(t('No conversations.'))+'</p>'));return;}
  const list=h('<div class="ptl-list"></div>');
  convs.forEach(c=>{
    const row=h('<button class="ptl-row"><b></b><span class="ptl-muted"></span>'+(c.unread?'<span class="ptl-badge">'+c.unread+'</span>':'')+'</button>');
    row.querySelector('b').textContent=c.orgName;
    row.querySelector('.ptl-muted').textContent=(c.lastAuthorName?c.lastAuthorName+': ':'')+(c.lastPreview||'')+(c.state==='archived'?' '+t('(archived)'):'');
    row.onclick=()=>openConv(c.orgId,c.orgName);list.appendChild(row);
  });
  body.appendChild(list);
}
let CONV_ORG=null,CONV_LAST=0,CONV_TIMER=null;
async function openConv(orgId,name){
  if(CONV_TIMER){clearTimeout(CONV_TIMER);CONV_TIMER=null;}
  CONV_ORG=orgId;CONV_LAST=0;
  const body=chrome('inbox',t('Chat')+' — '+name);
  body.appendChild(h('<div id="ptl-msgs" class="ptl-msgs"></div>'));
  body.appendChild(h('<form id="ptl-cf" class="ptl-composer"><input id="ptl-cm" class="ptl-input" maxlength="4000" required autocomplete="off" placeholder="'+esc(t('Write a reply…'))+'"><button class="ptl-btn ptl-btn--primary">'+esc(t('Send'))+'</button></form>'));
  body.querySelector('#ptl-cf').addEventListener('submit',async e=>{
    e.preventDefault();const inp=document.getElementById('ptl-cm'),v=inp.value.trim();if(!v)return;
    const r=await post('/api/portal/orgs/'+orgId+'/messages',{body:v,clientUuid:crypto.randomUUID()});
    if(r.success){inp.value='';pullConv();}else status(r.message||t('Error'));
  });
  pullConv();
}
async function pullConv(){
  const r=await get('/api/portal/orgs/'+CONV_ORG+'/messages?after='+CONV_LAST);
  const box=document.getElementById('ptl-msgs');if(!box||CONV_ORG===null)return;
  if(r.success&&r.data){
    (r.data.messages||[]).forEach(m=>{
      CONV_LAST=Math.max(CONV_LAST,m.id);
      const mine=m.kind==='staff';
      const row=h('<div class="ptl-msg'+(mine?' ptl-msg--mine':'')+'"><div class="ptl-msg__body"></div><div class="ptl-msg__meta"></div></div>');
      row.querySelector('.ptl-msg__body').textContent=m.body;
      row.querySelector('.ptl-msg__meta').textContent=(m.authorName||m.author)+' · '+new Date(m.createdAt).toLocaleString();
      box.appendChild(row);
    });
    if(r.data.messages.length)box.scrollTop=box.scrollHeight;
    if(r.data.state==='archived'){document.getElementById('ptl-cf')?.remove();status(t('Conversation archived (read only).'));}
  }
  CONV_TIMER=setTimeout(pullConv,15000);
}

/* ── Task board ── */
async function vBoard(){
  const r=await get('/api/portal/board');
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
  const wrap=h('<div class="ptl-board"></div>');
  Object.entries(cols).forEach(([key,col])=>{
    const c=h('<div class="ptl-col"><h2></h2></div>');
    c.querySelector('h2').textContent=col.label+' ('+col.cards.length+')';
    col.cards.forEach(card=>c.appendChild(taskCard(card)));
    wrap.appendChild(c);
  });
  body.appendChild(wrap);
}
function taskCard(it){
  const c=h('<div class="ptl-card2"><b></b><div class="ptl-muted"></div><div class="ptl-cardmeta"></div></div>');
  c.querySelector('b').textContent=it.title;
  c.querySelector('.ptl-muted').textContent=it.orgName;
  const meta=c.querySelector('.ptl-cardmeta');
  meta.appendChild(h('<span class="ptl-pill ptl-pill--'+esc(it.priority)+'">'+esc(it.priorityLabel||it.priority)+'</span>'));
  if(it.dueDate)meta.appendChild(h('<span class="ptl-pill'+(it.overdue?' ptl-pill--overdue':'')+'">'+esc(it.dueDate)+(it.overdue?' ⚠':'')+'</span>'));
  const move=btn(t('Move…'),async()=>{
    const f=await ask(t('Move to…'),null,t('Move'),[{name:'status',label:t('Status'),type:'select',options:[['new',t('New')],['in_progress',t('In progress')],['waiting',t('Waiting')],['done',t('Done')]]}]);
    if(!f)return;
    const r=await put('/api/portal/tasks/'+it.id+'/move',{status:f.status,revision:it.revision});
    if(r.success)vBoard();else if(r.error==='CONFLICT'){status(t('Changed in the meantime — reloaded.'));vBoard();}else status(r.message||t('Error'));
  });
  move.className='ptl-btn ptl-btn--sm';meta.appendChild(move);
  const vis=btn(it.visibility==='status'?t('In portal: on'):t('In portal: off'),async()=>{
    const r=await put('/api/portal/tasks/'+it.id,{portalVisibility:it.visibility==='status'?'none':'status',revision:it.revision});
    if(r.success)vBoard();else if(r.error==='CONFLICT')vBoard();else status(r.message||t('Error'));
  },false);
  vis.className='ptl-btn ptl-btn--sm';meta.appendChild(vis);
  return c;
}

/* ── Requests ── */
async function vRequests(mine){
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
  const mineCb=h('<label class="ptl-check"><input type="checkbox" id="ptl-mine"> '+esc(t('Only my clients'))+'</label>');
  mineCb.querySelector('#ptl-mine').checked=!!mine;
  mineCb.querySelector('#ptl-mine').onchange=e=>vRequests(e.target.checked);
  body.appendChild(mineCb);
  const r=await get('/api/portal/requests'+(mine?'?mine=1':''));
  const reqs=(r.data&&r.data.requests)||[];
  const list=h('<div class="ptl-list"></div>');
  reqs.forEach(rq=>{
    const row=h('<div class="ptl-card"><b></b><div class="ptl-muted"></div><div class="ptl-items"></div></div>');
    row.querySelector('b').textContent=rq.title;
    row.querySelector('.ptl-muted').textContent=(rq.deadline?t('Deadline')+' '+rq.deadline:t('no deadline'))+(rq.overdue?' — '+t('OVERDUE'):'')+' · '+rq.state+' · '+(rq.items?.accepted??0)+'/'+(rq.items?.total??0)+' '+t('accepted');
    const items=row.querySelector('.ptl-items');
    const detail=h('<details class="ptl-items-detail"><summary>'+esc(t('Details'))+'</summary></details>');
    items.appendChild(detail);
    row.querySelector('details').addEventListener('toggle',async ev=>{
      if(!ev.target.open||ev.target.dataset.loaded)return;
      ev.target.dataset.loaded='1';
      const itemsList=h('<div class="ptl-itemlist"></div>');
      detail.appendChild(itemsList);
      const dr=await get('/api/portal/requests/'+rq.id);
      if(!dr.success){itemsList.appendChild(h('<p class="ptl-muted">'+esc(t('Error'))+'</p>'));return;}
      const d=dr.data.request;
      if(d.state==='open'){
        const closeB=btn(t('Close request'),async()=>{
          if(!(await confirmDlg(t('Close request'),t('Marks the request as done even if items are missing. Continue?'))))return;
          const cr=await post('/api/portal/requests/'+d.id+'/close');if(cr.status===204||cr.success){vRequests(mine);}
        });
        closeB.className='ptl-btn ptl-btn--sm';itemsList.appendChild(closeB);
      }
      (d.items||[]).forEach(it=>{
        const ir=h('<div class="ptl-itemrow"><div><b></b><div class="ptl-muted"></div><div class="ptl-itemfiles"></div></div><div class="ptl-itemact"></div></div>');
        ir.querySelector('b').textContent=it.label;
        ir.querySelector('.ptl-muted').textContent=it.state+(it.hint?' · '+it.hint:'')+(it.rejectReason?' — '+t('Rejected')+': '+it.rejectReason:'');
        (it.files||[]).forEach(fid=>{
          const a=h('<a class="ptl-file"></a>');
          a.textContent='📎 '+t('file')+' #'+fid;a.href=base+'/api/portal/uploads/'+fid+'/file';
          ir.querySelector('.ptl-itemfiles').appendChild(a);
        });
        const act=ir.querySelector('.ptl-itemact');
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
  if(!reqs.length)list.appendChild(h('<p class="ptl-muted">'+esc(t('No requests.'))+'</p>'));
  body.appendChild(list);
}

/* ── Clients ── */
async function vOrgs(){
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
  body.appendChild(btn(t('Import CSV'),async()=>{
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
  body.appendChild(h('<input id="ptl-q" class="ptl-input" placeholder="'+esc(t('Search…'))+'" value="'+esc(q)+'">'));
  body.querySelector('#ptl-q').oninput=async e=>{const rr=await get('/api/portal/orgs?q='+encodeURIComponent(e.target.value));renderOrgs(rr.data&&rr.data.orgs||[]);};
  body.appendChild(h('<div id="ptl-orglist" class="ptl-list"></div>'));
  const r=await get('/api/portal/orgs');
  renderOrgs(r.data&&r.data.orgs||[]);
  function renderOrgs(orgs){
    const list=document.getElementById('ptl-orglist');list.innerHTML='';
    if(!orgs.length){list.appendChild(h('<p class="ptl-muted">'+esc(t('No clients.'))+'</p>'));return;}
    orgs.forEach(o=>{
      const row=h('<button class="ptl-row"><b></b><span class="ptl-muted"></span><span class="ptl-pill"></span></button>');
      row.querySelector('b').textContent=o.name;
      row.querySelector('.ptl-muted').textContent=[o.ref,o.kind,o.state].filter(Boolean).join(' · ');
      row.querySelector('.ptl-pill').textContent=o.appOn?t('App on'):t('App off');
      row.onclick=()=>openOrg(o.id);list.appendChild(row);
    });
  }
}
async function openOrg(id){
  const r=await get('/api/portal/orgs/'+id);
  if(!r.success){status(t('Not found.'));return;}
  const o=r.data.org,body=chrome('orgs',t('Client')+': '+o.name);
  const info=h('<div class="ptl-card"><div class="ptl-kv"></div><div class="ptl-actions"></div></div>');
  info.querySelector('.ptl-kv').innerHTML=[
    [t('Name'),o.name],[t('Reference'),o.ref||'—'],[t('Type'),o.kind],[t('Status'),o.state],[t('App access'),o.appOn?t('active'):t('off')],[t('Booking link'),o.bookUrl||'—'],[t('Intake'),(r.data.intake&&r.data.intake.address)||'—'],
  ].map(([k,v])=>'<div class="ptl-kvrow"><span>'+esc(k)+'</span><b>'+esc(v)+'</b></div>').join('');
  const act=info.querySelector('.ptl-actions');
  act.appendChild(btn(o.appOn?t('Deactivate app'):t('Activate app'),async()=>{
    const rr=await put('/api/portal/orgs/'+id,{appOn:o.appOn?false:true});
    if(rr.success)openOrg(id);else if(rr.error==='NO_PORTAL_SLOT')status(t('No free app slot (license).'));else status(rr.message||t('Error'));
  },!o.appOn));
  if(o.state==='active')act.appendChild(btn(t('Offboard'),async()=>{
    if(!(await confirmDlg(t('Offboard'),t('Chat will be archived and members lose access. Continue?'))))return;
    await post('/api/portal/orgs/'+id+'/offboard');openOrg(id);
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
  body.appendChild(h('<h2>'+esc(t('Responsible staff'))+'</h2><div id="ptl-resp"></div>'));
  const resp=document.getElementById('ptl-resp');
  (r.data.contacts||[]).forEach(c=>{
    resp.appendChild(h('<div class="ptl-dev"><span>'+esc(c.user_id||c.userId)+'</span></div>'));
  });
  body.appendChild(btn(t('Set responsible staff'),async()=>{
    const f=await ask(t('Responsible staff'),t('NC user ids (comma-separated, max 5)'),t('Save'));
    if(f===null)return;
    const uids=f.v.split(',').map(x=>x.trim()).filter(Boolean).slice(0,5);
    const rr=await put('/api/portal/orgs/'+id+'/contacts',{userIds:uids});
    status(rr.success||rr.status===204?t('Saved.'):(rr.message||t('Error')));openOrg(id);
  }));
  body.appendChild(h('<h2>'+esc(t('Contacts'))+'</h2><div id="ptl-members"></div>'));
  const ml=document.getElementById('ptl-members');
  (r.data.members||[]).forEach(m=>{
    const row=h('<div class="ptl-dev"><span></span></div>');
    row.querySelector('span').textContent=(m.displayName||m.email)+(m.status!=='active'?' ('+m.status+')':'');
    if(ME_ADMIN&&m.status==='active'){
      const dis=btn(t('Disable'),async()=>{await put('/api/portal/identities/'+m.identityId+'/status',{status:'disabled'});openOrg(id);},false);
      dis.className='ptl-btn ptl-btn--sm';row.appendChild(dis);
    }else if(ME_ADMIN&&m.status==='disabled'){
      const en=btn(t('Enable'),async()=>{await put('/api/portal/identities/'+m.identityId+'/status',{status:'active'});openOrg(id);},false);
      en.className='ptl-btn ptl-btn--sm';row.appendChild(en);
    }
    const rm=btn(t('Remove'),async()=>{await del('/api/portal/orgs/'+id+'/members/'+m.identityId);openOrg(id);},false);
    rm.className='ptl-btn ptl-btn--sm';row.appendChild(rm);ml.appendChild(row);
  });
  body.appendChild(btn(t('+ Add contact'),async()=>{
    const f=await ask(t('Add contact'),null,t('Add'),[{name:'email',label:t('E-mail'),type:'email',required:true},{name:'displayName',label:t('Name')}]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/members',{email:f.email,displayName:f.displayName});
    status(rr.success?t('Added.'):(rr.message||t('Error')));if(rr.success)openOrg(id);
  }));
  body.appendChild(h('<h2>'+esc(t('Upload links'))+'</h2><div id="ptl-ulinks"></div>'));
  const ul=document.getElementById('ptl-ulinks');
  (r.data.ulinks||[]).forEach(l=>{
    const row=h('<div class="ptl-dev"><span></span></div>');
    row.querySelector('span').textContent=l.label+' · '+l.usedFiles+'/'+l.maxFiles+(l.revoked?' '+t('(revoked)'):'');
    if(!l.revoked){const rm=btn(t('Revoke'),async()=>{await del('/api/portal/ulinks/'+l.id);openOrg(id);},false);rm.className='ptl-btn ptl-btn--sm';row.appendChild(rm);}
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
  const inRow=h('<div class="ptl-card"><div class="ptl-kvrow"><span>'+esc(t('Address'))+'</span><b id="ptl-ina"></b></div><div class="ptl-actions"></div></div>');
  inRow.querySelector('#ptl-ina').textContent=(r.data.intake&&r.data.intake.address)||t('disabled');
  inRow.querySelector('.ptl-actions').appendChild(btn(r.data.intake&&r.data.intake.address?t('Disable'):t('Enable'),async()=>{
    const rr=await put('/api/portal/orgs/'+id+'/intake',{enabled:!(r.data.intake&&r.data.intake.address)});
    if(rr.success)openOrg(id);else status(rr.message||t('Error'));
  }));
  inRow.querySelector('.ptl-actions').appendChild(btn(t('Rotate address'),async()=>{
    if(!(await confirmDlg(t('Rotate'),t('The old address stops working immediately. Continue?'))))return;
    await post('/api/portal/orgs/'+id+'/intake/rotate');openOrg(id);
  }));
  body.appendChild(inRow);
  // approvals on documents (US-P23)
  body.appendChild(h('<h2>'+esc(t('Approvals'))+'</h2><div id="ptl-appr"></div>'));
  const ap=document.getElementById('ptl-appr');
  const ar=await get('/api/portal/orgs/'+id+'/approvals');
  if(ar.success&&ar.data){
    (ar.data.approvals||[]).forEach(a=>{
      const row=h('<div class="ptl-dev"><span></span></div>');
      row.querySelector('span').textContent=a.fileName+' · '+a.state+(a.deadline?' · '+a.deadline:'');
      if(a.state==='pending'){const w=btn(t('Withdraw'),async()=>{await post('/api/portal/approvals/'+a.id+'/withdraw');openOrg(id);},false);w.className='ptl-btn ptl-btn--sm';row.appendChild(w);}
      const pf=h('<a class="ptl-btn ptl-btn--sm"></a>');pf.textContent=t('Proof');pf.href=base+'/api/portal/approvals/'+a.id+'/proof';row.appendChild(pf);
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
    if(!inv.length)body.appendChild(h('<p class="ptl-muted">'+esc(t('No invoices.'))+'</p>'));
    else body.appendChild(h('<div class="ptl-card">'+inv.map(i=>'<div class="ptl-kvrow"><span>'+esc(i.ref||('#'+i.id))+(i.overdue?' ⚠':'')+'</span><b>'+esc(i.status)+'</b></div>').join('')+'</div>'));
  }
}

/* ── Uploads ── */
async function vUploads(){
  const body=chrome('uploads',t('Inbox — upload queue'));
  const r=await get('/api/portal/uploads');
  const ups=(r.data&&r.data.uploads)||[];
  if(!ups.length){body.appendChild(h('<p class="ptl-muted">'+esc(t('No new uploads.'))+'</p>'));}
  const list=h('<div class="ptl-list"></div>');
  ups.forEach(u=>{
    const row=h('<div class="ptl-card"><b></b><div class="ptl-muted"></div><div class="ptl-actions"></div></div>');
    row.querySelector('b').textContent=u.fileName;
    row.querySelector('.ptl-muted').textContent=u.orgName+' · '+u.uploader+' · '+u.source+' · '+new Date(u.createdAt).toLocaleString()+(u.duplicate?' · '+t('DUPLICATE'):'')+(u.note?' · '+u.note:'');
    const act=row.querySelector('.ptl-actions');
    const dl=h('<a class="ptl-btn"></a>');dl.textContent=t('Download');dl.href=base+'/api/portal/uploads/'+u.id+'/file';act.appendChild(dl);
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
  const r=await get('/api/portal/settings');
  const body=chrome('settings',t('Settings'));
  if(!r.success){
    body.appendChild(h('<div class="ptl-card"><h2>'+esc(t('No access'))+'</h2><p class="ptl-muted">'+esc(t('Settings are only visible to PortalCheck app admins. Your account is not on the app-admin list.'))+'</p><p class="ptl-muted">'+esc(t('Next step: ask a Nextcloud administrator to add your account under Settings → Access (door), or to grant you the App Admin role.'))+'</p></div>'));
    return;
  }
  const s=r.data.settings,d=r.data.door;
  const form=h('<div class="ptl-card"><h2>'+esc(t('General'))+'</h2><label class="ptl-label" for="s-preset">'+esc(t('Industry (preset)'))+'</label><select id="s-preset" class="ptl-input"></select><label class="ptl-label" for="s-label">'+esc(t('Provider name (e-mails)'))+'</label><input id="s-label" class="ptl-input"><label class="ptl-label" for="s-max">'+esc(t('Upload limit (MB)'))+'</label><input id="s-max" class="ptl-input" type="number"><label class="ptl-label" for="s-ttl">'+esc(t('OTP validity (minutes)'))+'</label><input id="s-ttl" class="ptl-input" type="number"><div class="ptl-actions"></div></div>');
  const sel=form.querySelector('#s-preset');
  r.data.presets.forEach(p=>sel.appendChild(new Option(p,p)));
  sel.value=s.preset;form.querySelector('#s-label').value=s.providerLabel;form.querySelector('#s-max').value=s.uploadMaxMb;form.querySelector('#s-ttl').value=s.otpTtlMin;
  form.querySelector('.ptl-actions').appendChild(btn(t('Save'),async()=>{
    const rr=await put('/api/portal/settings',{preset:sel.value,providerLabel:form.querySelector('#s-label').value,uploadMaxMb:+form.querySelector('#s-max').value,otpTtlMin:+form.querySelector('#s-ttl').value});
    status(rr.success?t('Saved.'):(rr.message||t('Error')));
  },true));
  body.appendChild(form);
  // door
  const door=h('<div class="ptl-card"><h2>'+esc(t('Access (door)'))+'</h2><label class="ptl-label"><input type="checkbox" id="d-restr"> '+esc(t('Restricted (selected groups/users only)'))+'</label><p class="ptl-muted">'+esc(t('Platform restriction:'))+' '+(d.platformGroups.length?esc(d.platformGroups.join(', ')):esc(t('none')))+'</p><label class="ptl-label" for="d-users">'+esc(t('Allowed users (comma-separated)'))+'</label><input id="d-users" class="ptl-input"><label class="ptl-label" for="d-admins">'+esc(t('App admins (comma-separated)'))+'</label><input id="d-admins" class="ptl-input"><div class="ptl-actions"></div></div>');
  door.querySelector('#d-restr').checked=d.restricted;
  door.querySelector('#d-users').value=(d.allowedUsers||[]).join(', ');
  door.querySelector('#d-admins').value=(d.appAdmins||[]).join(', ');
  door.querySelector('.ptl-actions').appendChild(btn(t('Save door'),async()=>{
    const csv=v=>v.split(',').map(x=>x.trim()).filter(Boolean);
    const rr=await put('/api/portal/door',{restricted:door.querySelector('#d-restr').checked,groups:[],users:csv(door.querySelector('#d-users').value),appAdmins:csv(door.querySelector('#d-admins').value)});
    status(rr.success?t('Saved.'):(rr.message||t('Error')));
  },true));
  body.appendChild(door);
  // license + diagnostics
  const l=await get('/api/portal/license');
  if(l.success&&l.data){
    const lic=l.data.license;
    body.appendChild(h('<div class="ptl-card"><h2>'+esc(t('License'))+'</h2><p>'+esc(t('Status:'))+' <b>'+esc(lic.status)+'</b> · '+esc(t('Portal clients:'))+' '+lic.usedPortalClients+'/'+lic.portalClients+' · '+esc(t('Mobile seats:'))+' '+(lic.mobileSeats??'—')+'</p><div id="l-actions"></div></div>'));
    body.querySelector('#l-actions').appendChild(btn(t('Apply license key'),async()=>{
      const f=await ask(t('PTL2 key'),t('Paste key:'),t('Apply'));
      if(!f)return;
      const rr=await post('/api/portal/license',{key:f.v});
      status(rr.success?t('Applied.'):(rr.message||t('Invalid key')));if(rr.success)vSettings();
    }));
  }
  const diag=await get('/api/portal/diagnostics');
  if(diag.success&&diag.data){
    const g=diag.data;
    body.appendChild(h('<div class="ptl-card"><h2>'+esc(t('Diagnostics'))+'</h2><p>'+esc(t('Cron:'))+' '+(g.cron.stale?'<b>'+esc(t('stale'))+'</b>':esc(t('ok')))+' · '+esc(t('Outbox:'))+' '+g.outbox.pending+' '+t('pending')+' / '+g.outbox.failed+' '+t('failed')+' · '+esc(t('Documents root:'))+' '+(g.docsRoot.healthy?esc(t('ok')):esc(t('broken — configure')))+' · '+esc(t('License:'))+' '+esc(g.license.status)+'</p></div>'));
  }
}

/* ── Mail intake holds (US-P24) ── */
async function vHolds(){
  const body=chrome('holds',t('Mail review'));
  const r=await get('/api/portal/intake-holds');
  const holds=(r.data&&r.data.holds)||[];
  if(!holds.length){body.appendChild(h('<p class="ptl-muted">'+esc(t('No held e-mails.'))+'</p>'));return;}
  const list=h('<div class="ptl-list"></div>');
  holds.forEach(hh=>{
    const row=h('<div class="ptl-card"><b></b><div class="ptl-muted"></div><div class="ptl-actions"></div></div>');
    row.querySelector('b').textContent=t('Hold')+' #'+hh.id+' · '+t('org')+' '+hh.orgId;
    row.querySelector('.ptl-muted').textContent=new Date(hh.receivedAt).toLocaleString()+' · '+hh.state;
    const act=row.querySelector('.ptl-actions');
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
  const body=chrome('forms',t('Questionnaires'));
  body.appendChild(btn(t('+ New template'),async()=>{
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
  if(!tpls.length)body.appendChild(h('<p class="ptl-muted">'+esc(t('No templates.'))+'</p>'));
  tpls.forEach(tp=>{
    body.appendChild(h('<div class="ptl-card"><b>'+esc(tp.name)+'</b><div class="ptl-muted">'+esc((tp.fields||[]).length)+' '+esc(t('fields'))+'</div></div>'));
  });
  body.appendChild(h('<h2>'+esc(t('Forms sent'))+'</h2>'));
  const fr=await get('/api/portal/forms');
  const forms=(fr.data&&fr.data.forms)||[];
  const orgs=await get('/api/portal/orgs');
  const orgNames={};(orgs.data&&orgs.data.orgs||[]).forEach(o=>orgNames[o.id]=o.name);
  if(!forms.length)body.appendChild(h('<p class="ptl-muted">'+esc(t('No forms sent yet.'))+'</p>'));
  const list=h('<div class="ptl-list"></div>');
  forms.forEach(f=>{
    const row=h('<div class="ptl-card"><b></b><div class="ptl-muted"></div><div class="ptl-actions"></div></div>');
    row.querySelector('b').textContent=(orgNames[f.orgId]||'#'+f.orgId)+' · '+t('Form')+' #'+f.id;
    row.querySelector('.ptl-muted').textContent=f.state+(f.submittedAt?' · '+new Date(f.submittedAt).toLocaleString():'');
    const act=row.querySelector('.ptl-actions');
    act.appendChild(btn(t('Answers'),()=>openForm(f.id),true));
    if(f.state==='submitted')act.appendChild(btn(t('Reopen'),async()=>{await post('/api/portal/forms/'+f.id+'/reopen');vForms();}));
    const csv=h('<a class="ptl-btn ptl-btn--sm"></a>');csv.textContent='CSV';csv.href=base+'/api/portal/forms/'+f.id+'/export.csv';act.appendChild(csv);
    list.appendChild(row);
  });
  body.appendChild(list);
}
async function openForm(id){
  const r=await get('/api/portal/forms/'+id);
  if(!r.success){status(t('Not found.'));return;}
  const f=r.data.form,body=chrome('forms',t('Form')+' #'+id+' — '+f.name);
  const card=h('<div class="ptl-card"></div>');
  (f.fields||[]).forEach(fd=>{
    const ans=(f.answers||[]).find(a=>a.key===fd.key);
    card.appendChild(h('<div class="ptl-kvrow"><span>'+esc(fd.label)+'</span><b>'+esc(ans?String(ans.value):'—')+'</b></div>'));
  });
  body.appendChild(card);
  if(f.state==='submitted')body.appendChild(btn(t('Reopen for the client'),async()=>{await post('/api/portal/forms/'+f.id+'/reopen');openForm(id);}));
}

/* ── People (identities admin, US-P07) ── */
async function vPeople(){
  const body=chrome('people',t('People'));
  if(!ME_ADMIN){body.appendChild(h('<p class="ptl-muted">'+esc(t('App admins only'))+'</p>'));return;}
  body.appendChild(h('<input id="ptl-pq" class="ptl-input" placeholder="'+esc(t('Search e-mail or name…'))+'">'));
  body.appendChild(h('<div id="ptl-plist" class="ptl-list"></div>'));
  const render=async q=>{
    const r=await get('/api/portal/identities?q='+encodeURIComponent(q));
    const list=document.getElementById('ptl-plist');list.innerHTML='';
    (r.data&&r.data.identities||[]).forEach(i=>{
      const row=h('<div class="ptl-dev"><span></span></div>');
      row.querySelector('span').textContent=(i.displayName||'')+' <'+i.email+'>'+(i.status!=='active'?' ('+i.status+')':'');
      const st=btn(i.status==='disabled'?t('Enable'):t('Disable'),async()=>{
        await put('/api/portal/identities/'+i.id+'/status',{status:i.status==='disabled'?'active':'disabled'});render(document.getElementById('ptl-pq').value);
      },false);st.className='ptl-btn ptl-btn--sm';row.appendChild(st);
      const ex=btn(t('Export'),async()=>{
        const rj=await get('/api/portal/identities/'+i.id+'/export');
        const blob=new Blob([JSON.stringify(rj.data??rj,null,2)],{type:'application/json'});
        const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='identity-'+i.id+'.json';a.click();
      },false);ex.className='ptl-btn ptl-btn--sm';row.appendChild(ex);
      const er=btn(t('Erase'),async()=>{
        if(!(await confirmDlg(t('Erase person'),t('All personal data is erased permanently. Continue?'))))return;
        await del('/api/portal/identities/'+i.id);render(document.getElementById('ptl-pq').value);
      },false);er.className='ptl-btn ptl-btn--sm';row.appendChild(er);
      list.appendChild(row);
    });
  };
  body.querySelector('#ptl-pq').oninput=e=>render(e.target.value);
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
