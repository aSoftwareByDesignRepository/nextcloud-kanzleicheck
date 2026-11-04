(function(){'use strict';
/* PortalCheck client portal: Home (to-do) · Chat · Documents · More.
   Plain DOM, no innerHTML of user data — esc() everywhere (NN-P14). */
const el=document.getElementById('ptl-view'),base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('portalcheck',s):s;
let CSRF='';
const ck=n=>{const m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]+)'));return m?decodeURIComponent(m[1]):'';};
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json','X-PTL-CSRF':CSRF||CSRF||ck('ptl_csrf')}},o||{}));if(r.status===401){location.href=base+'/portal/login';throw new Error('auth');}return r.json().catch(()=>({success:false}));};
const post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})});
const put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};
let ORG=null,ME=null,POLL=null,ORGS=[];
const status=t=>{const s=document.getElementById('ptl-live');if(s)s.textContent=t;};

async function init(){
  const s=await api('/portal/api/session');
  if(s.success&&s.data)CSRF=s.data.csrf;
  const r=await api('/portal/api/orgs');
  if(!r.success||!r.data){el.appendChild(h('<div class="ptl-card"><h2>'+esc(t('No access granted'))+'</h2><p class="ptl-muted">'+esc(t('This account is not linked to a portal. If you expected access, contact the organisation that invited you — they can resend your sign-in link.'))+'</p></div>'));return;}
  ME=r.data.me;
  const orgs=r.data.orgs;
  ORGS=orgs;
  if(!orgs.length){el.appendChild(h('<p class="ptl-muted">'+esc(t('No portal section has been opened for your e-mail yet.'))+'</p>'));return;}
  READY=true;
  if(orgs.length>1&&!ORG){route('pick');return;}
  if(!ORG)ORG=orgs[0].id;
  route('start');
}
let READY=false;
async function pollBadges(){
  if(!ORG)return;
  const r=await api('/portal/api/orgs/'+ORG+'/unread');
  if(!r.success||!r.data)return;
  const chat=document.querySelector('.ptl-tab[data-view="chat"] .ptl-badge');
  if(chat){chat.hidden=!(r.data.messages>0);chat.textContent=r.data.messages>0?String(r.data.messages):'';}
}
function route(v){
  if(!READY)return;
  if(v!=='pick'&&!ORG&&ORGS.length>1)v='pick';
  document.querySelectorAll('.ptl-tab').forEach(it=>it.removeAttribute('aria-current'));
  const tab=document.querySelector('.ptl-tab[data-view="'+v+'"]');tab&&tab.setAttribute('aria-current','page');
  if(POLL){clearTimeout(POLL);POLL=null;}
  ({start:viewStart,chat:viewChat,docs:viewDocs,more:viewMore,pick:viewOrgPick})[v]();
}

/* ── Org picker: multi-mandate accounts choose their portal section first
   * (companion parity: App.tsx orgs.pick). Re-entered via the switch on Home. ── */
function viewOrgPick(){
  el.innerHTML='<h1>'+esc(t('Choose organisation'))+'</h1><p class="ptl-muted">'+esc(t('Your sign-in is linked to more than one organisation.'))+'</p><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  const sel=h('<div class="ptl-orgpick"></div>').firstElementChild;
  ORGS.forEach(o=>{
    const b=document.createElement('button');
    b.type='button';b.className='ptl-card ptl-orgpick__btn';
    b.textContent=o.name;
    b.onclick=()=>{ORG=o.id;route('start');};
    sel.appendChild(b);
  });
  el.appendChild(sel);
}
/* ── Home: consolidated to-do stack ── */
async function viewStart(){
  pollBadges();
  const cr=await api('/portal/api/orgs/'+ORG+'/contact');
  const contacts=(cr.data&&cr.data.contacts)||[];
  const orgRow=ORGS.find(o=>o.id===ORG);
  const r=await api('/portal/api/orgs/'+ORG+'/todo');
  el.innerHTML='<h1>'+esc(t('To do'))+'</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  if(ORGS.length>1){
    const ctx=h('<p class="ptl-orgctx"><span class="ptl-muted"></span></p>').firstElementChild;
    ctx.querySelector('.ptl-muted').textContent=orgRow?orgRow.name:'';
    ctx.appendChild(btn(t('Switch organisation'),()=>route('pick'),true));
    el.insertBefore(ctx,el.children[1]);
  }
  const items=(r.data&&r.data.todo)||[];
  if(!items.length){el.appendChild(h('<div class="ptl-card"><h2>'+esc(t('All done'))+'</h2><p class="ptl-muted">'+esc(t('We will let you know when we need something.'))+'</p></div>'));return;}
  const list=h('<div class="ptl-stack"></div>').firstElementChild;
  if(contacts.length){
    const cc=h('<div class="ptl-card ptl-contact"><h2>'+esc(t('Your contact person'))+'</h2></div>').firstElementChild;
    contacts.forEach(c=>{
      cc.appendChild(h('<div class="ptl-kvrow"><span>'+esc(c.name)+(c.role?' · '+esc(c.role):'')+'</span>'+(c.email?'<b>'+esc(c.email)+'</b>':'')+'</div>'));
    });
    el.appendChild(cc);
  }
  if(orgRow&&orgRow.bookingUrl){
    const bk=h('<a class="ptl-card ptl-book" target="_blank" rel="noopener"><b></b></a>').firstElementChild;
    bk.href=orgRow.bookingUrl;bk.querySelector('b').textContent='📅 '+t('Book an appointment');
    el.appendChild(bk);
  }
  items.forEach(it=>list.appendChild(todoCard(it)));
  el.appendChild(list);
}
function todoCard(it){
  const c=h('<div class="ptl-card"><h2></h2><p class="ptl-muted"></p><div class="ptl-actions"></div></div>').firstElementChild;
  c.querySelector('h2').textContent=it.title;
  const sub=it.kind==='request'?t('Requested document'):it.kind==='approval'?t('Approval'):t('Form');
  c.querySelector('.ptl-muted').textContent=sub+(it.deadline?' · '+t('Due')+' '+it.deadline:'')+(it.overdue?' — '+t('Overdue'):'');
  if(it.rejectReason){const r=document.createElement('p');r.className='ptl-field-error';r.removeAttribute('hidden');r.textContent=t('Rejected')+': '+it.rejectReason;c.insertBefore(r,c.querySelector('.ptl-actions'));}
  const act=c.querySelector('.ptl-actions');
  if(it.kind==='request'&&it.itemId){act.appendChild(btn(t('Upload'),()=>uploadForItem(it)));act.appendChild(btn(t('Confirm only'),()=>confirmItem(it),true));}
  if(it.kind==='approval'){act.appendChild(btn(t('Approve'),()=>approvalFlow(it.id,'approve')));act.appendChild(btn(t('Reject'),()=>approvalFlow(it.id,'reject'),true));}
  if(it.kind==='form'){act.appendChild(btn(t('Open'),()=>viewForm(it.id)));}
  return c;
}
function btn(label,fn,ghost){const b=document.createElement('button');b.className='ptl-btn'+(ghost?'':' ptl-btn--primary');b.textContent=label;b.onclick=fn;return b;}
/* Native <dialog> prompt — design-system compliant (never window.prompt). */
function ask(title,label,okLabel){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='ptl-dialog';
  dlg.innerHTML='<form method="dialog" class="ptl-card"><h2></h2><label class="ptl-label"></label><input class="ptl-input" required><div class="ptl-actions"><button value="" class="ptl-btn">'+esc(t('Cancel'))+'</button><button class="ptl-btn ptl-btn--primary"></button></div></form>';
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
    const r=await fetch(base+'/portal/api/orgs/'+ORG+'/uploads',{method:'POST',headers:{'X-PTL-CSRF':CSRF||CSRF||ck('ptl_csrf')},body:fd}).then(x=>x.json()).catch(()=>({success:false}));
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
  const r=await api('/portal/api/forms/'+id);
  if(!r.success){status(t('Form not found.'));return;}
  const f=r.data.form;
  el.innerHTML='<h1>'+esc(t('Form'))+'</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  const wrap=h('<div class="ptl-card"></div>').firstElementChild;
  f.fields.forEach(fd=>{
    const ans=(f.answers||[]).find(a=>a.key===fd.key);
    const w=h('<div class="ptl-field"><label class="ptl-label"></label></div>').firstElementChild;
    w.querySelector('label').textContent=fd.label+(fd.required?' *':'');
    let input;
    if(fd.type==='yes_no'||fd.type==='single_choice'){
      input=document.createElement('select');input.className='ptl-input';
      input.appendChild(new Option('—',''));
      (fd.type==='yes_no'?['yes','no']:fd.options).forEach(o=>input.appendChild(new Option(o,o)));
      input.value=ans?ans.value:'';
    }else{
      input=document.createElement(fd.type==='long_text'?'textarea':'input');
      input.className='ptl-input';if(fd.type==='long_text')input.rows=4;
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
  el.innerHTML='<h1>'+esc(t('Chat'))+'</h1><div id="ptl-msgs" class="ptl-msgs"></div><form id="ptl-cf" class="ptl-composer"><input id="ptl-cm" class="ptl-input" maxlength="4000" required autocomplete="off" placeholder="'+esc(t('Write a message…'))+'"><button class="ptl-btn ptl-btn--primary">'+esc(t('Send'))+'</button></form><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  document.getElementById('ptl-cf').addEventListener('submit',async e=>{
    e.preventDefault();
    const inp=document.getElementById('ptl-cm'),body=inp.value.trim();
    if(!body)return;
    inp.value='';
    const r=await post('/portal/api/orgs/'+ORG+'/messages',{body,clientUuid:crypto.randomUUID()});
    if(r.success)pullMessages();else status(r.message||t('Error'));
  });
  await pullMessages();
}
async function pullMessages(){
  const r=await api('/portal/api/orgs/'+ORG+'/messages?after='+LAST);
  const box=document.getElementById('ptl-msgs');if(!box)return;
  if(r.success&&r.data){
    r.data.messages.forEach(m=>{
      LAST=Math.max(LAST,m.id);
      box.appendChild(h('<div class="ptl-msg'+(m.mine?' ptl-msg--mine':'')+'"><div class="ptl-msg__body"></div><div class="ptl-msg__meta"></div></div>'));
      const last=box.lastElementChild;
      last.querySelector('.ptl-msg__body').textContent=m.body;
      last.querySelector('.ptl-msg__meta').textContent=(m.mine?t('You'):(m.kind==='staff'?t('Your provider'):m.author))+' · '+new Date(m.createdAt).toLocaleString();
    });
    if(r.data.messages.length){box.scrollTop=box.scrollHeight;post('/portal/api/orgs/'+ORG+'/read',{lastReadId:LAST});}
    POLL=setTimeout(pullMessages,r.data.pollAfterMs||15000);
  }else{POLL=setTimeout(pullMessages,30000);}
}

/* ── Documents ── */
async function viewDocs(){
  el.innerHTML='<h1>'+esc(t('Documents'))+'</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div><div class="ptl-card"><button class="ptl-btn ptl-btn--primary" id="ptl-up">'+esc(t('Upload a file'))+'</button></div><div id="ptl-files"></div>';
  document.getElementById('ptl-up').onclick=()=>{
    const inp=document.createElement('input');inp.type='file';
    inp.onchange=async()=>{
      const fd=new FormData();fd.append('file',inp.files[0]);
      status(t('Uploading…'));
      const r=await fetch(base+'/portal/api/orgs/'+ORG+'/uploads',{method:'POST',headers:{'X-PTL-CSRF':CSRF||CSRF||ck('ptl_csrf')},body:fd}).then(x=>x.json()).catch(()=>({success:false}));
      status(r.success?t('Uploaded. Thank you!'):(r.message||t('Error')));
      if(r.success)viewDocs();
    };
    inp.click();
  };
  const r=await api('/portal/api/orgs/'+ORG+'/documents');
  const box=document.getElementById('ptl-files');
  const files=(r.data&&r.data.files)||[];
  if(!files.length){box.appendChild(h('<p class="ptl-muted">'+esc(t('No documents have been shared with you yet.'))+'</p>'));return;}
  files.forEach(f=>{
    const a=document.createElement('a');
    a.className='ptl-file';a.href=base+'/portal/api/orgs/'+ORG+'/files/'+f.id+'/download';
    a.textContent='📄 '+f.name+' ('+Math.ceil(f.size/1024)+' KB)';
    box.appendChild(a);
  });
}

/* ── More ── */
async function viewMore(){
  const r=await api('/portal/api/orgs/'+ORG+'/tasks');
  const d=await api('/portal/api/devices');
  const ir=await api('/portal/api/orgs/'+ORG+'/intake');
  const nr=await api('/portal/api/orgs/'+ORG+'/invoices').catch(()=>({success:false}));
  el.innerHTML='<h1>'+esc(t('More'))+'</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  if(ir.success&&ir.data&&ir.data.address){
    el.appendChild(h('<h2>'+esc(t('Send documents by e-mail'))+'</h2>'));
    el.appendChild(h('<div class="ptl-card"><p>'+esc(t('Attachments to this address land in your inbox for review:'))+'</p><p><b>'+esc(ir.data.address)+'</b></p></div>'));
  }
  const orgRow=ORGS.find(o=>o.id===ORG);
  if(orgRow&&orgRow.bookingUrl){
    const bk=h('<a class="ptl-card ptl-book" target="_blank" rel="noopener"><b></b></a>').firstElementChild;
    bk.href=orgRow.bookingUrl;bk.querySelector('b').textContent='📅 '+t('Book an appointment');el.appendChild(bk);
  }
  if(nr.success&&nr.data&&nr.data.invoices&&nr.data.invoices.length){
    el.appendChild(h('<h2>'+esc(t('Invoices'))+'</h2>'));
    el.appendChild(h('<div class="ptl-card">'+nr.data.invoices.map(i=>'<div class="ptl-kvrow"><span>'+esc(i.ref||('#'+i.id))+(i.overdue?' ⚠':'')+'</span><b>'+esc(i.status)+'</b></div>').join('')+'</div>'));
  }
  el.appendChild(h('<h2>'+esc(t('E-mail notifications'))+'</h2>'));
  const notifyRow=h('<div class="ptl-card"><label class="ptl-check"><input type="checkbox" id="ptl-notify"> '+esc(t('Notify me by e-mail when something new arrives'))+'</label></div>');
  const nq=await api('/portal/api/orgs/'+ORG+'/notify');
  if(nq.success&&nq.data){notifyRow.querySelector('#ptl-notify').checked=!!nq.data.mailsEnabled;}
  notifyRow.querySelector('#ptl-notify').onchange=async e=>{await put('/portal/api/orgs/'+ORG+'/notify',{mailsEnabled:e.target.checked});status(t('Saved.'));};
  el.appendChild(notifyRow);
  el.appendChild(h('<h2>'+esc(t('Tasks'))+'</h2>'));
  const tasks=(r.data&&r.data.tasks)||[];
  el.appendChild(h(tasks.length?'<div class="ptl-card">'+tasks.map(it=>'<p>'+esc(it.title)+' — <b>'+esc(it.statusLabel)+'</b>'+(it.dueDate?' · '+it.dueDate:'')+'</p>').join('')+'</div>':'<p class="ptl-muted">'+esc(t('No tasks visible.'))+'</p>'));
  el.appendChild(h('<h2>'+esc(t('My devices'))+'</h2>'));
  const devs=(d.data&&d.data.devices)||[];
  const dl=h('<div class="ptl-card"></div>').firstElementChild;
  if(!devs.length){dl.appendChild(h('<p class="ptl-muted">'+esc(t('No devices connected.'))+'</p>'));}
  devs.forEach(dv=>{
    const row=h('<div class="ptl-dev"><span></span></div>').firstElementChild;
    row.querySelector('span').textContent=dv.name+' ('+dv.platform+')';
    const rm=btn(t('Remove'),async()=>{const rr=await fetch(base+'/portal/api/devices/'+dv.id,{method:'DELETE',headers:{'X-PTL-CSRF':CSRF||CSRF||ck('ptl_csrf')}});if(rr.ok||rr.status===204)viewMore();},true);
    row.appendChild(rm);dl.appendChild(row);
  });
  el.appendChild(dl);
  el.appendChild(h('<h2>'+esc(t('Account'))+'</h2>'));
  const out=btn(t('Sign out'),async()=>{await post('/portal/api/auth/logout');location.href=base+'/portal/login';},true);
  el.appendChild(out);
}
document.querySelectorAll('.ptl-tab').forEach(it=>it.addEventListener('click',()=>route(it.dataset.view)));
init();
})();
