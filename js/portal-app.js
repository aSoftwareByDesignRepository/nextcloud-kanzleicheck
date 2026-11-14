(function(){'use strict';
/* KanzleiCheck client portal: Home (to-do) · Chat · Documents · More.
   Plain DOM, no innerHTML of user data — esc() everywhere (NN-P14). */
const el=document.getElementById('kzc-view'),base=location.pathname.split('/apps/kanzleicheck')[0]+'/apps/kanzleicheck';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('kanzleicheck',s):s;
let CSRF='';
const ck=n=>{const m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]+)'));return m?decodeURIComponent(m[1]):'';};
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json','X-KZC-CSRF':CSRF||CSRF||ck('kzc_csrf')}},o||{}));if(r.status===401){location.href=base+'/portal/login';throw new Error('auth');}return r.json().catch(()=>({success:false}));};
const post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})});
const put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};
/* VIEW_SEQ invalidates any in-flight view continuation on every view entry
   (stale_response_render_clobber); POLL_* keep exactly one chat chain alive
   per generation (poller_chain_fork_duplicate_render). */
let ORG=null,ME=null,POLL=null,ORGS=[],VIEW_SEQ=0,POLL_GEN=0,POLL_BUSY=0,POLL_WANT=false;
const status=t=>{const s=document.getElementById('kzc-live');if(s)s.textContent=t;};

async function init(){
  const s=await api('/portal/api/session');
  if(s.success&&s.data)CSRF=s.data.csrf;
  const r=await api('/portal/api/orgs');
  if(!r.success||!r.data){el.appendChild(h('<div class="kzc-card"><h2>'+esc(t('No access granted'))+'</h2><p class="kzc-muted">'+esc(t('This account is not linked to a portal. If you expected access, contact the organisation that invited you — they can resend your sign-in link.'))+'</p></div>'));return;}
  ME=r.data.me;
  const orgs=r.data.orgs;
  ORGS=orgs;
  if(!orgs.length){el.appendChild(h('<p class="kzc-muted">'+esc(t('No portal section has been opened for your e-mail yet.'))+'</p>'));return;}
  READY=true;
  if(orgs.length>1&&!ORG){route('pick');return;}
  if(!ORG)ORG=orgs[0].id;
  route('start');
}
let READY=false;
async function pollBadges(){
  const org=ORG;if(!org)return;
  const r=await api('/portal/api/orgs/'+org+'/unread');
  if(!r.success||!r.data||org!==ORG)return;
  const chat=document.querySelector('.kzc-tab[data-view="chat"] .kzc-badge');
  if(chat){chat.hidden=!(r.data.messages>0);chat.textContent=r.data.messages>0?String(r.data.messages):'';}
}
function route(v){
  if(!READY)return;
  if(v!=='pick'&&!ORG&&ORGS.length>1)v='pick';
  document.querySelectorAll('.kzc-tab').forEach(it=>it.removeAttribute('aria-current'));
  const tab=document.querySelector('.kzc-tab[data-view="'+v+'"]');tab&&tab.setAttribute('aria-current','page');
  if(POLL){clearTimeout(POLL);POLL=null;}
  ({start:viewStart,chat:viewChat,docs:viewDocs,more:viewMore,pick:viewOrgPick})[v]();
}

/* ── Org picker: multi-mandate accounts choose their portal section first
   * (companion parity: App.tsx orgs.pick). Re-entered via the switch on Home. ── */
function viewOrgPick(){
  VIEW_SEQ++;
  el.innerHTML='<h1>'+esc(t('Choose organisation'))+'</h1><p class="kzc-muted">'+esc(t('Your sign-in is linked to more than one organisation.'))+'</p><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  const sel=h('<div class="kzc-orgpick"></div>').firstElementChild;
  ORGS.forEach(o=>{
    const b=document.createElement('button');
    b.type='button';b.className='kzc-card kzc-orgpick__btn';
    b.textContent=o.name;
    b.onclick=()=>{ORG=o.id;route('start');};
    sel.appendChild(b);
  });
  el.appendChild(sel);
}
/* ── Home: consolidated to-do stack ── */
async function viewStart(){
  const seq=++VIEW_SEQ,org=ORG;
  // paint the view chrome BEFORE awaiting — a late whole-view paint must
  // never clobber a view the user already navigated to.
  el.innerHTML='<h1>'+esc(t('To do'))+'</h1><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  if(ORGS.length>1){
    const orgRow0=ORGS.find(o=>o.id===org);
    const ctx=h('<p class="kzc-orgctx"><span class="kzc-muted"></span></p>').firstElementChild;
    ctx.querySelector('.kzc-muted').textContent=orgRow0?orgRow0.name:'';
    ctx.appendChild(btn(t('Switch organisation'),()=>route('pick'),true));
    el.insertBefore(ctx,el.children[1]);
  }
  pollBadges();
  const [cr,r]=await Promise.all([
    api('/portal/api/orgs/'+org+'/contact'),
    api('/portal/api/orgs/'+org+'/todo'),
  ]);
  if(seq!==VIEW_SEQ||org!==ORG)return; // superseded — never paint stale
  const contacts=(cr.data&&cr.data.contacts)||[];
  const orgRow=ORGS.find(o=>o.id===org);
  const items=(r.data&&r.data.todo)||[];
  if(!items.length){el.appendChild(h('<div class="kzc-card"><h2>'+esc(t('All done'))+'</h2><p class="kzc-muted">'+esc(t('We will let you know when we need something.'))+'</p></div>'));return;}
  const list=h('<div class="kzc-stack"></div>').firstElementChild;
  if(contacts.length){
    const cc=h('<div class="kzc-card kzc-contact"><h2>'+esc(t('Your contact person'))+'</h2></div>').firstElementChild;
    contacts.forEach(c=>{
      cc.appendChild(h('<div class="kzc-kvrow"><span>'+esc(c.name)+(c.role?' · '+esc(c.role):'')+'</span>'+(c.email?'<b>'+esc(c.email)+'</b>':'')+'</div>'));
    });
    el.appendChild(cc);
  }
  if(orgRow&&orgRow.bookingUrl){
    const bk=h('<a class="kzc-card kzc-book" target="_blank" rel="noopener"><b></b></a>').firstElementChild;
    bk.href=orgRow.bookingUrl;bk.querySelector('b').textContent='📅 '+t('Book an appointment');
    el.appendChild(bk);
  }
  items.forEach(it=>list.appendChild(todoCard(it)));
  el.appendChild(list);
}
function todoCard(it){
  const c=h('<div class="kzc-card"><h2></h2><p class="kzc-muted"></p><div class="kzc-actions"></div></div>').firstElementChild;
  c.querySelector('h2').textContent=it.title;
  const sub=it.kind==='request'?t('Requested document'):it.kind==='approval'?t('Approval'):t('Form');
  c.querySelector('.kzc-muted').textContent=sub+(it.deadline?' · '+t('Due')+' '+it.deadline:'')+(it.overdue?' — '+t('Overdue'):'');
  if(it.rejectReason){const r=document.createElement('p');r.className='kzc-field-error';r.removeAttribute('hidden');r.textContent=t('Rejected')+': '+it.rejectReason;c.insertBefore(r,c.querySelector('.kzc-actions'));}
  const act=c.querySelector('.kzc-actions');
  if(it.kind==='request'&&it.itemId){act.appendChild(btn(t('Upload'),()=>uploadForItem(it)));act.appendChild(btn(t('Confirm only'),()=>confirmItem(it),true));}
  if(it.kind==='approval'){act.appendChild(btn(t('Approve'),()=>approvalFlow(it.id,'approve')));act.appendChild(btn(t('Reject'),()=>approvalFlow(it.id,'reject'),true));}
  if(it.kind==='form'){act.appendChild(btn(t('Open'),()=>viewForm(it.id)));}
  return c;
}
function btn(label,fn,ghost){const b=document.createElement('button');b.className='kzc-btn'+(ghost?'':' kzc-btn--primary');b.textContent=label;b.onclick=fn;return b;}
/* Native <dialog> prompt — design-system compliant (never window.prompt). */
function ask(title,label,okLabel){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='kzc-dialog';
  dlg.innerHTML='<form method="dialog" class="kzc-card"><h2></h2><label class="kzc-label"></label><input class="kzc-input" required><div class="kzc-actions"><button value="" class="kzc-btn">'+esc(t('Cancel'))+'</button><button class="kzc-btn kzc-btn--primary"></button></div></form>';
  dlg.querySelector('h2').textContent=title;
  dlg.querySelector('label').textContent=label;
  const inp=dlg.querySelector('input'),ok=dlg.querySelectorAll('button')[1];
  ok.textContent=okLabel;ok.value='ok';
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  dlg.addEventListener('close',()=>{res(dlg.returnValue==='ok'?inp.value:null);dlg.remove();});
  document.body.appendChild(dlg);dlg.showModal();inp.focus();
});}
async function confirmItem(it){status('');const r=await post('/portal/api/requests/'+it.id+'/items/'+it.itemId+'/confirm');status(r.success?t('Done.'):(r.message||t('Error')));if(r.success)viewStart();}
function uploadForItem(it){
  const inp=document.createElement('input');inp.type='file';
  inp.onchange=async()=>{
    const fd=new FormData();fd.append('file',inp.files[0]);fd.append('reqId',it.id);fd.append('reqItemId',it.itemId);
    status(t('Uploading…'));
    const r=await fetch(base+'/portal/api/orgs/'+ORG+'/uploads',{method:'POST',headers:{'X-KZC-CSRF':CSRF||CSRF||ck('kzc_csrf')},body:fd}).then(x=>x.json()).catch(()=>({success:false}));
    status(r.success?t('Uploaded. Thank you!'):(r.message||t('Error')));if(r.success)viewStart();
  };
  inp.click();
}
async function approvalFlow(id,decision){
  status('');
  if(decision==='reject'){
    const reason=await ask(t('Reject'),t('Why are you rejecting? (short reason)'),t('Reject'));
    if(!reason){status(t('Cancelled — a reason is required.'));return;}
    const r=await post('/portal/api/approvals/'+id+'/decide',{decision:'reject',reason});
    status(r.success?t('Rejected.'):(r.message||t('Error')));if(r.success)viewStart();return;
  }
  // approve: request code first, then confirm with it
  const s=await post('/portal/api/approvals/'+id+'/start');
  if(!s.success){status(s.message||t('Failed to request the code'));return;}
  const code=await ask(t('Confirm approval'),t('We sent you a confirmation code by e-mail. Enter the code:'),t('Confirm'));
  if(!code){status(t('Cancelled.'));return;}
  const r=await post('/portal/api/approvals/'+id+'/decide',{decision:'approve',code:code.trim()});
  status(r.success?t('Approved. Thank you!'):(r.message||t('Invalid code')));
  if(r.success)viewStart();
}
async function viewForm(id){
  const seq=++VIEW_SEQ;
  const r=await api('/portal/api/forms/'+id);
  if(seq!==VIEW_SEQ)return; // superseded — never paint over a newer view
  if(!r.success){status(t('Form not found.'));return;}
  const f=r.data.form;
  el.innerHTML='<h1>'+esc(t('Form'))+'</h1><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  const wrap=h('<div class="kzc-card"></div>').firstElementChild;
  f.fields.forEach(fd=>{
    const ans=(f.answers||[]).find(a=>a.key===fd.key);
    const w=h('<div class="kzc-field"><label class="kzc-label"></label></div>').firstElementChild;
    w.querySelector('label').textContent=fd.label+(fd.required?' *':'');
    let input;
    if(fd.type==='yes_no'||fd.type==='single_choice'){
      input=document.createElement('select');input.className='kzc-input';
      input.appendChild(new Option('—',''));
      (fd.type==='yes_no'?['yes','no']:fd.options).forEach(o=>input.appendChild(new Option(o,o)));
      input.value=ans?ans.value:'';
    }else{
      input=document.createElement(fd.type==='long_text'?'textarea':'input');
      input.className='kzc-input';if(fd.type==='long_text')input.rows=4;
      if(fd.type==='date')input.type='date';if(fd.type==='number')input.type='number';
      input.value=ans?ans.value:'';
    }
    input.disabled=f.state==='submitted';
    input.onchange=async()=>{
      const rev=ans?ans.revision:0;
      const rr=await put('/portal/api/forms/'+id+'/answers',{field:fd.key,value:input.value,revision:rev});
      status(rr.success?t('Saved.'):(rr.error==='CONFLICT'?t('Changed in the meantime — please reload.'):(rr.message||t('Error'))));
    };
    w.appendChild(input);wrap.appendChild(w);
  });
  el.appendChild(wrap);
  if(f.state!=='submitted'){
    const b=btn(t('Submit'),async()=>{const rr=await post('/portal/api/forms/'+id+'/submit');status(rr.success?t('Submitted. Thank you!'):(rr.message||t('Please check required fields')));if(rr.success)route('start');});
    el.appendChild(b);
  }
}

/* ── Chat ── */
let LAST=0;
async function viewChat(){
  VIEW_SEQ++;LAST=0;
  const gen=++POLL_GEN,org=ORG;
  el.innerHTML='<h1>'+esc(t('Chat'))+'</h1><div id="kzc-msgs" class="kzc-msgs"></div><form id="kzc-cf" class="kzc-composer"><input id="kzc-cm" class="kzc-input" maxlength="4000" required autocomplete="off" placeholder="'+esc(t('Write a message…'))+'"><button class="kzc-btn kzc-btn--primary">'+esc(t('Send'))+'</button></form><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  document.getElementById('kzc-cf').addEventListener('submit',async e=>{
    e.preventDefault();
    const inp=document.getElementById('kzc-cm'),body=inp.value.trim();
    if(!body)return;
    inp.value='';
    const r=await post('/portal/api/orgs/'+org+'/messages',{body,clientUuid:crypto.randomUUID()});
    // trigger the pending poll early on the SAME chain — never fork a second
    if(r.success){if(POLL){clearTimeout(POLL);POLL=null;}pullMessages(gen);}else status(r.message||t('Error'));
  });
  await pullMessages(gen);
}
async function pullMessages(gen){
  const org=ORG;
  if(!org||gen!==POLL_GEN)return;
  if(POLL_BUSY===gen){POLL_WANT=true;return;} // same chain already in flight — coalesce
  POLL_BUSY=gen;
  const r=await api('/portal/api/orgs/'+org+'/messages?after='+LAST).catch(()=>({success:false}));
  if(POLL_BUSY===gen)POLL_BUSY=0;
  if(POLL_WANT){POLL_WANT=false;pullMessages(POLL_GEN);return;} // one fresh pull under current gen/org
  const box=document.getElementById('kzc-msgs');
  if(!box||org!==ORG||gen!==POLL_GEN)return; // view/org switched mid-flight — drop, stop chain
  if(r.success&&r.data){
    r.data.messages.forEach(m=>{
      if(m.id<=LAST)return; // already painted — belt dedupe vs overlapping fetches
      LAST=m.id;
      box.appendChild(h('<div class="kzc-msg'+(m.mine?' kzc-msg--mine':'')+'"><div class="kzc-msg__body"></div><div class="kzc-msg__meta"></div></div>'));
      const last=box.lastElementChild;
      last.querySelector('.kzc-msg__body').textContent=m.body;
      last.querySelector('.kzc-msg__meta').textContent=(m.mine?t('You'):(m.kind==='staff'?t('Your provider'):m.author))+' · '+new Date(m.createdAt).toLocaleString();
    });
    if(r.data.messages.length){box.scrollTop=box.scrollHeight;post('/portal/api/orgs/'+org+'/read',{lastReadId:LAST});}
    POLL=setTimeout(()=>{POLL=null;pullMessages(gen);},r.data.pollAfterMs||15000);
  }else{POLL=setTimeout(()=>{POLL=null;pullMessages(gen);},30000);}
}

/* ── Documents ── */
async function viewDocs(){
  const seq=++VIEW_SEQ,org=ORG;
  el.innerHTML='<h1>'+esc(t('Documents'))+'</h1><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div><div class="kzc-card"><button class="kzc-btn kzc-btn--primary" id="kzc-up">'+esc(t('Upload a file'))+'</button></div><div id="kzc-files"></div>';
  document.getElementById('kzc-up').onclick=()=>{
    const inp=document.createElement('input');inp.type='file';
    inp.onchange=async()=>{
      const fd=new FormData();fd.append('file',inp.files[0]);
      status(t('Uploading…'));
      const r=await fetch(base+'/portal/api/orgs/'+org+'/uploads',{method:'POST',headers:{'X-KZC-CSRF':CSRF||CSRF||ck('kzc_csrf')},body:fd}).then(x=>x.json()).catch(()=>({success:false}));
      status(r.success?t('Uploaded. Thank you!'):(r.message||t('Error')));
      if(r.success)viewDocs();
    };
    inp.click();
  };
  const r=await api('/portal/api/orgs/'+org+'/documents');
  if(seq!==VIEW_SEQ)return; // superseded — never paint stale
  const box=document.getElementById('kzc-files');
  if(!box)return;
  const files=(r.data&&r.data.files)||[];
  if(!files.length){box.appendChild(h('<p class="kzc-muted">'+esc(t('No documents have been shared with you yet.'))+'</p>'));return;}
  files.forEach(f=>{
    const a=document.createElement('a');
    a.className='kzc-file';a.href=base+'/portal/api/orgs/'+org+'/files/'+f.id+'/download';
    a.textContent='📄 '+f.name+' ('+Math.ceil(f.size/1024)+' KB)';
    box.appendChild(a);
  });
}

/* ── More ── */
async function viewMore(){
  const seq=++VIEW_SEQ,org=ORG;
  const [r,d,ir,nr]=await Promise.all([
    api('/portal/api/orgs/'+org+'/tasks'),
    api('/portal/api/devices'),
    api('/portal/api/orgs/'+org+'/intake'),
    api('/portal/api/orgs/'+org+'/invoices').catch(()=>({success:false})),
  ]);
  if(seq!==VIEW_SEQ||org!==ORG)return; // superseded — never paint stale
  el.innerHTML='<h1>'+esc(t('More'))+'</h1><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  if(ir.success&&ir.data&&ir.data.address){
    el.appendChild(h('<h2>'+esc(t('Send documents by e-mail'))+'</h2>'));
    el.appendChild(h('<div class="kzc-card"><p>'+esc(t('Attachments to this address land in your inbox for review:'))+'</p><p><b>'+esc(ir.data.address)+'</b></p></div>'));
  }
  const orgRow=ORGS.find(o=>o.id===org);
  if(orgRow&&orgRow.bookingUrl){
    const bk=h('<a class="kzc-card kzc-book" target="_blank" rel="noopener"><b></b></a>').firstElementChild;
    bk.href=orgRow.bookingUrl;bk.querySelector('b').textContent='📅 '+t('Book an appointment');el.appendChild(bk);
  }
  if(nr.success&&nr.data&&nr.data.invoices&&nr.data.invoices.length){
    el.appendChild(h('<h2>'+esc(t('Invoices'))+'</h2>'));
    el.appendChild(h('<div class="kzc-card">'+nr.data.invoices.map(i=>'<div class="kzc-kvrow"><span>'+esc(i.ref||('#'+i.id))+(i.overdue?' ⚠':'')+'</span><b>'+esc(i.status)+'</b></div>').join('')+'</div>'));
  }
  el.appendChild(h('<h2>'+esc(t('E-mail notifications'))+'</h2>'));
  const notifyRow=h('<div class="kzc-card"><label class="kzc-check"><input type="checkbox" id="kzc-notify"> '+esc(t('Notify me by e-mail when something new arrives'))+'</label></div>');
  const nq=await api('/portal/api/orgs/'+org+'/notify');
  if(seq!==VIEW_SEQ||org!==ORG)return; // superseded — never paint stale
  if(nq.success&&nq.data){notifyRow.querySelector('#kzc-notify').checked=!!nq.data.mailsEnabled;}
  notifyRow.querySelector('#kzc-notify').onchange=async e=>{await put('/portal/api/orgs/'+org+'/notify',{mailsEnabled:e.target.checked});status(t('Saved.'));};
  el.appendChild(notifyRow);
  el.appendChild(h('<h2>'+esc(t('Tasks'))+'</h2>'));
  const tasks=(r.data&&r.data.tasks)||[];
  el.appendChild(h(tasks.length?'<div class="kzc-card">'+tasks.map(it=>'<p>'+esc(it.title)+' — <b>'+esc(it.statusLabel)+'</b>'+(it.dueDate?' · '+it.dueDate:'')+'</p>').join('')+'</div>':'<p class="kzc-muted">'+esc(t('No tasks visible.'))+'</p>'));
  el.appendChild(h('<h2>'+esc(t('My devices'))+'</h2>'));
  const devs=(d.data&&d.data.devices)||[];
  const dl=h('<div class="kzc-card"></div>').firstElementChild;
  if(!devs.length){dl.appendChild(h('<p class="kzc-muted">'+esc(t('No devices connected.'))+'</p>'));}
  devs.forEach(dv=>{
    const row=h('<div class="kzc-dev"><span></span></div>').firstElementChild;
    row.querySelector('span').textContent=dv.name+' ('+dv.platform+')';
    const rm=btn(t('Remove'),async()=>{const rr=await fetch(base+'/portal/api/devices/'+dv.id,{method:'DELETE',headers:{'X-KZC-CSRF':CSRF||CSRF||ck('kzc_csrf')}});if(rr.ok||rr.status===204)viewMore();},true);
    row.appendChild(rm);dl.appendChild(row);
  });
  el.appendChild(dl);
  el.appendChild(h('<h2>'+esc(t('Account'))+'</h2>'));
  const out=btn(t('Sign out'),async()=>{await post('/portal/api/auth/logout');location.href=base+'/portal/login';},true);
  el.appendChild(out);
}
document.querySelectorAll('.kzc-tab').forEach(it=>it.addEventListener('click',()=>route(it.dataset.view)));
init();
})();
