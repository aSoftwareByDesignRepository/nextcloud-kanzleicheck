(function(){'use strict';
/* KanzleiCheck client portal: Home (to-do) · Chat · Documents · More.
   Plain DOM, no innerHTML of user data — esc() everywhere (NN-P14).
   Markup mirrors the family pack anatomy: structured .kzc-card
   (__header/__title/__lead/__header-actions), icon wells, dot badges,
   callouts, list rows, canonical .kzc-dialog. */
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

/* ── Family component helpers (portal-scoped) ── */
const ICONS={
 'home':'<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
 'chat':'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
 'file':'<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
 'file-check':'<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/>',
 'clipboard-check':'<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
 'stamp':'<path d="M5 22h14"/><path d="M19.27 13.73A2.5 2.5 0 0 0 17.5 13h-11a2.5 2.5 0 0 0-2.5 2.5V16h14z"/><path d="M14 13V8.5C14 7 15 7 15 5a3 3 0 0 0-6 0c0 2 1 2 1 3.5V13"/>',
 'users':'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
 'upload':'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
 'mail':'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
 'bell':'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
 'device':'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
 'logout':'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
 'calendar':'<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
 'chevron-right':'<polyline points="9 18 15 12 9 6"/>',
 'arrow-left':'<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
 'check-circle':'<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
 'alert':'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
 'x':'<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
 'download':'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
 'list':'<line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/>',
};
const icon=n=>'<svg class="kzc-icon" aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+(ICONS[n]||ICONS.list)+'</svg>';
const well=(v,n)=>'<span class="kzc-well'+(v&&v!=='info'?' kzc-well--'+v:'')+'" aria-hidden="true">'+icon(n)+'</span>';
const badge=(v,text)=>'<span class="kzc-badge'+(v?' kzc-badge--'+v:'')+'"><span class="kzc-badge__dot" aria-hidden="true"></span>'+esc(text)+'</span>';
const empty=(ic,title,text)=>h('<div class="kzc-empty">'+well('neutral',ic)+'<h2 class="kzc-empty__title">'+esc(title)+'</h2><p class="kzc-muted">'+esc(text)+'</p></div>');
const callout=(v,title,text)=>{const c=h('<div class="kzc-callout kzc-callout--'+v+'" role="note">'+well(v==='danger'?'danger':v==='warning'?'warn':v,'alert')+'<div><p class="kzc-callout__title"></p><p class="kzc-muted"></p></div></div>').firstElementChild;c.querySelector('.kzc-callout__title').textContent=title;c.querySelector('.kzc-muted').textContent=text;return c;};
function card(iconName,title,lead){
  const c=h('<div class="kzc-card"><div class="kzc-card__header"><div><h2 class="kzc-card__title">'+(iconName?icon(iconName):'')+'</h2>'+(lead?'<p class="kzc-card__lead"></p>':'')+'</div><div class="kzc-card__header-actions"></div></div><div class="kzc-card__body"></div></div>').firstElementChild;
  c.querySelector('.kzc-card__title').appendChild(document.createTextNode(title));
  if(lead)c.querySelector('.kzc-card__lead').textContent=lead;
  return {card:c,actions:c.querySelector('.kzc-card__header-actions'),body:c.querySelector('.kzc-card__body')};
}
function btn(label,fn,ghost){const b=document.createElement('button');b.type='button';b.className='kzc-btn'+(ghost?'':' kzc-btn--primary');b.textContent=label;b.onclick=fn;return b;}

async function init(){
  const s=await api('/portal/api/session');
  if(s.success&&s.data)CSRF=s.data.csrf;
  const r=await api('/portal/api/orgs');
  if(!r.success||!r.data){el.appendChild(empty('alert',t('No access granted'),t('This account is not linked to a portal. If you expected access, contact the organisation that invited you — they can resend your sign-in link.')));return;}
  ME=r.data.me;
  const orgs=r.data.orgs;
  ORGS=orgs;
  if(!orgs.length){el.appendChild(empty('users',t('No portal section yet'),t('No portal section has been opened for your e-mail yet.')));return;}
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
  const chat=document.querySelector('.kzc-tab[data-view="chat"] .kzc-tab-badge');
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
  el.innerHTML='<h1>'+esc(t('Choose organisation'))+'</h1><p class="kzc-lead">'+esc(t('Your sign-in is linked to more than one organisation.'))+'</p><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  const sel=h('<div class="kzc-orgpick"></div>').firstElementChild;
  ORGS.forEach(o=>{
    const b=h('<button type="button" class="kzc-card kzc-orgpick__btn">'+well('info','users')+'<span class="kzc-orgpick__name"></span><span class="kzc-file__chev" aria-hidden="true">'+icon('chevron-right')+'</span></button>').firstElementChild;
    b.querySelector('.kzc-orgpick__name').textContent=o.name;
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
  if(contacts.length){
    const cc=card('users',t('Your contact person'),null);
    contacts.forEach(c=>{
      cc.body.appendChild(h('<div class="kzc-kvrow"><span>'+esc(c.name)+(c.role?' · '+esc(c.role):'')+'</span>'+(c.email?'<b>'+esc(c.email)+'</b>':'')+'</div>'));
    });
    el.appendChild(cc.card);
  }
  if(orgRow&&orgRow.bookingUrl){
    const bk=h('<a class="kzc-card kzc-book kzc-linkcard" target="_blank" rel="noopener">'+well('info','calendar')+'<span class="kzc-linkcard__label"></span><span class="kzc-file__chev" aria-hidden="true">'+icon('chevron-right')+'</span></a>').firstElementChild;
    bk.href=orgRow.bookingUrl;bk.querySelector('.kzc-linkcard__label').textContent=t('Book an appointment');
    el.appendChild(bk);
  }
  if(!items.length){el.appendChild(empty('check-circle',t('All done'),t('We will let you know when we need something.')));return;}
  const list=h('<div class="kzc-stack"></div>').firstElementChild;
  items.forEach(it=>list.appendChild(todoCard(it)));
  el.appendChild(list);
}
const TODO_META={
  request:{icon:'file-check',label:()=>t('Requested document')},
  approval:{icon:'stamp',label:()=>t('Approval')},
  form:{icon:'clipboard-check',label:()=>t('Form')},
};
function todoCard(it){
  const m=TODO_META[it.kind]||TODO_META.form;
  const c=h('<div class="kzc-card"><div class="kzc-todo">'+well(it.overdue?'warn':'info',m.icon)+'<div class="kzc-todo__body"><h2 class="kzc-todo__title"></h2><div class="kzc-todo__meta kzc-muted"></div></div></div><div class="kzc-actions"></div></div>').firstElementChild;
  c.querySelector('.kzc-todo__title').textContent=it.title;
  const meta=c.querySelector('.kzc-todo__meta');
  meta.appendChild(h(badge('',m.label())));
  if(it.deadline)meta.appendChild(h(badge(it.overdue?'danger':'neutral',t('Due')+' '+it.deadline+(it.overdue?' — '+t('Overdue'):''))));
  if(it.rejectReason){
    // reject reason keeps the .kzc-field-error hook inside the card (e2e
    // contract) — rendered inside a callout instead of as a bare line.
    const co=callout('danger',t('Rejected'),it.rejectReason);
    co.querySelector('.kzc-muted').classList.replace('kzc-muted','kzc-field-error');
    c.insertBefore(co,c.querySelector('.kzc-actions'));
  }
  const act=c.querySelector('.kzc-actions');
  if(it.kind==='request'&&it.itemId){act.appendChild(btn(t('Upload'),()=>uploadForItem(it)));act.appendChild(btn(t('Confirm only'),()=>confirmItem(it),true));}
  if(it.kind==='approval'){act.appendChild(btn(t('Approve'),()=>approvalFlow(it.id,'approve')));act.appendChild(btn(t('Reject'),()=>approvalFlow(it.id,'reject'),true));}
  if(it.kind==='form'){act.appendChild(btn(t('Open'),()=>viewForm(it.id)));}
  return c;
}
/* Native <dialog> prompt — design-system compliant (never window.prompt). */
function ask(title,label,okLabel){return new Promise(res=>{
  const dlg=document.createElement('dialog');dlg.className='kzc-dialog';
  dlg.innerHTML='<form method="dialog" class="kzc-dialog__form"><div class="kzc-dialog__head"><h2 class="kzc-dialog__title"></h2><button type="button" class="kzc-dialog__close" aria-label="'+esc(t('Close'))+'">'+icon('x')+'</button></div><div class="kzc-field"><label class="kzc-label"></label><input class="kzc-input" required></div><div class="kzc-dialog__actions"><button value="" class="kzc-btn">'+esc(t('Cancel'))+'</button><button class="kzc-btn kzc-btn--primary"></button></div></form>';
  dlg.querySelector('.kzc-dialog__title').textContent=title;
  dlg.querySelector('.kzc-label').textContent=label;
  const inp=dlg.querySelector('input'),ok=dlg.querySelectorAll('button')[2];
  ok.textContent=okLabel;ok.value='ok';
  dlg.querySelector('.kzc-dialog__close').addEventListener('click',()=>dlg.close(''));
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
  const c=card('clipboard-check',f.name||t('Form'),f.state==='submitted'?t('Submitted — read only.'):t('Your answers save automatically.'));
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
    w.appendChild(input);c.body.appendChild(w);
  });
  if(f.state!=='submitted'){
    const act=h('<div class="kzc-actions"></div>').firstElementChild;
    act.appendChild(btn(t('Submit'),async()=>{const rr=await post('/portal/api/forms/'+id+'/submit');status(rr.success?t('Submitted. Thank you!'):(rr.message||t('Please check required fields')));if(rr.success)route('start');}));
    c.body.appendChild(act);
  }
  const back=btn(t('Back'),()=>route('start'),true);back.insertAdjacentHTML('afterbegin',icon('arrow-left'));
  c.actions.appendChild(back);
  el.appendChild(c.card);
}

/* ── Chat ── */
let LAST=0;
async function viewChat(){
  VIEW_SEQ++;LAST=0;
  const gen=++POLL_GEN,org=ORG;
  el.innerHTML='<h1>'+esc(t('Chat'))+'</h1><div id="kzc-msgs" class="kzc-msgs"></div><form id="kzc-cf" class="kzc-composer"><label class="kzc-sr-only" for="kzc-cm">'+esc(t('Write a message…'))+'</label><textarea id="kzc-cm" class="kzc-textarea" rows="3" maxlength="4000" required autocomplete="off" placeholder="'+esc(t('Write a message…'))+'"></textarea><button class="kzc-btn kzc-btn--primary">'+esc(t('Send'))+'</button></form><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  /* textarea: Enter sends, Shift+Enter = newline, IME Enter must not send. */
  document.getElementById('kzc-cm').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();e.target.form.requestSubmit();}});
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
  el.innerHTML='<h1>'+esc(t('Documents'))+'</h1><div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div>';
  const uc=card('upload',t('Upload a file'),t('Files you send land in the office inbox right away.'));
  const up=btn(t('Choose file'),null);up.insertAdjacentHTML('afterbegin',icon('upload'));up.id='kzc-up';
  uc.body.appendChild(up);
  el.appendChild(uc.card);
  const fc=card('file',t('Shared with you'),null);
  fc.body.appendChild(h('<div id="kzc-files"></div>'));
  el.appendChild(fc.card);
  up.onclick=()=>{
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
  if(!files.length){box.appendChild(empty('file',t('Nothing shared yet'),t('No documents have been shared with you yet.')));return;}
  files.forEach(f=>{
    const a=h('<a class="kzc-file"><span class="kzc-well kzc-well--neutral" aria-hidden="true">'+icon('file')+'</span><span class="kzc-file__name"></span><span class="kzc-file__size"></span><span class="kzc-file__chev" aria-hidden="true">'+icon('download')+'</span></a>').firstElementChild;
    a.href=base+'/portal/api/orgs/'+org+'/files/'+f.id+'/download';
    a.querySelector('.kzc-file__name').textContent=f.name;
    a.querySelector('.kzc-file__size').textContent=Math.ceil(f.size/1024)+' KB';
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
    const ic=card('mail',t('Send documents by e-mail'),t('Attachments to this address land in your inbox for review:'));
    const code=h('<code class="kzc-mono"></code>').firstElementChild;
    code.textContent=ir.data.address;
    ic.body.appendChild(code);
    el.appendChild(ic.card);
  }
  const orgRow=ORGS.find(o=>o.id===org);
  if(orgRow&&orgRow.bookingUrl){
    const bk=h('<a class="kzc-card kzc-book kzc-linkcard" target="_blank" rel="noopener">'+well('info','calendar')+'<span class="kzc-linkcard__label"></span><span class="kzc-file__chev" aria-hidden="true">'+icon('chevron-right')+'</span></a>').firstElementChild;
    bk.href=orgRow.bookingUrl;bk.querySelector('.kzc-linkcard__label').textContent=t('Book an appointment');
    el.appendChild(bk);
  }
  if(nr.success&&nr.data&&nr.data.invoices&&nr.data.invoices.length){
    const vc=card('file',t('Invoices'),null);
    nr.data.invoices.forEach(i=>{
      const row=h('<div class="kzc-kvrow"><span></span><b></b></div>').firstElementChild;
      row.querySelector('span').textContent=i.ref||('#'+i.id);
      if(i.overdue)row.querySelector('span').prepend(h(badge('danger',t('Overdue'))));
      row.querySelector('b').textContent=i.status;
      vc.body.appendChild(row);
    });
    el.appendChild(vc.card);
  }
  const nc=card('bell',t('E-mail notifications'),null);
  nc.body.appendChild(h('<label class="kzc-check"><input type="checkbox" id="kzc-notify"> '+esc(t('Notify me by e-mail when something new arrives'))+'</label>'));
  el.appendChild(nc.card);
  const nq=await api('/portal/api/orgs/'+org+'/notify');
  if(seq!==VIEW_SEQ||org!==ORG)return; // superseded — never paint stale
  if(nq.success&&nq.data){document.getElementById('kzc-notify').checked=!!nq.data.mailsEnabled;}
  document.getElementById('kzc-notify').onchange=async e=>{await put('/portal/api/orgs/'+org+'/notify',{mailsEnabled:e.target.checked});status(t('Saved.'));};
  const tc=card('list',t('Tasks'),null);
  const tasks=(r.data&&r.data.tasks)||[];
  if(!tasks.length)tc.body.appendChild(empty('check-circle',t('No tasks'),t('No tasks visible.')));
  tasks.forEach(it=>{
    const row=h('<div class="kzc-dev"><span class="kzc-dev__label"></span><span class="kzc-actions"></span></div>').firstElementChild;
    row.querySelector('.kzc-dev__label').textContent=it.title+(it.dueDate?' · '+it.dueDate:'');
    row.querySelector('.kzc-actions').appendChild(h(badge(it.status==='done'?'success':'info',it.statusLabel)));
    tc.body.appendChild(row);
  });
  el.appendChild(tc.card);
  const dc=card('device',t('My devices'),t('Devices linked to your sign-in — remove any you no longer use.'));
  const devs=(d.data&&d.data.devices)||[];
  if(!devs.length){dc.body.appendChild(empty('device',t('No devices'),t('No devices connected.')));}
  devs.forEach(dv=>{
    const row=h('<div class="kzc-dev"><span class="kzc-dev__label"></span><span class="kzc-actions"></span></div>').firstElementChild;
    row.querySelector('.kzc-dev__label').textContent=dv.name+' ('+dv.platform+')';
    row.querySelector('.kzc-actions').appendChild(btn(t('Remove'),async()=>{const rr=await fetch(base+'/portal/api/devices/'+dv.id,{method:'DELETE',headers:{'X-KZC-CSRF':CSRF||CSRF||ck('kzc_csrf')}});if(rr.ok||rr.status===204)viewMore();},true));
    dc.body.appendChild(row);
  });
  el.appendChild(dc.card);
  const oc=card('logout',t('Account'),null);
  const oact=h('<div class="kzc-actions"></div>').firstElementChild;
  oact.appendChild(btn(t('Sign out'),async()=>{await post('/portal/api/auth/logout');location.href=base+'/portal/login';},true));
  oc.body.appendChild(oact);
  el.appendChild(oc.card);
}
document.querySelectorAll('.kzc-tab').forEach(it=>it.addEventListener('click',()=>route(it.dataset.view)));
init();
})();
