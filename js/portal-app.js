(function(){'use strict';
/* PortalCheck client portal: Start (Zu erledigen) · Chat · Dokumente · Mehr.
   Plain DOM, no innerHTML of user data — esc() everywhere (NN-P14). */
const el=document.getElementById('ptl-view'),base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const ck=n=>{const m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]+)'));return m?decodeURIComponent(m[1]):'';};
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json','X-PTL-CSRF':ck('ptl_csrf')}},o||{}));if(r.status===401){location.href=base+'/portal/login';throw new Error('auth');}return r.json().catch(()=>({success:false}));};
const post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})});
const put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};
let ORG=null,ME=null,POLL=null;
const status=t=>{const s=document.getElementById('ptl-live');if(s)s.textContent=t;};

async function init(){
  const r=await api('/portal/api/orgs');
  if(!r.success||!r.data){el.appendChild(h('<p class="ptl-muted">Kein Zugang freigeschaltet.</p>'));return;}
  ME=r.data.me;
  const orgs=r.data.orgs;
  if(!orgs.length){el.appendChild(h('<p class="ptl-muted">Für Ihre E-Mail ist noch kein Bereich freigeschaltet.</p>'));return;}
  if(orgs.length>1){
    const sel=h('<div class="ptl-orgpick"></div>');
    orgs.forEach(o=>{const b=document.createElement('button');b.className='ptl-btn';b.textContent=o.name;b.onclick=()=>{ORG=o.id;route('start');};sel.appendChild(b);});
    if(!ORG){ORG=orgs[0].id;}
  } else {ORG=orgs[0].id;}
  route('start');
}
function route(v){
  document.querySelectorAll('.ptl-tab').forEach(t=>t.removeAttribute('aria-current'));
  const tab=document.querySelector('.ptl-tab[data-view="'+v+'"]');tab&&tab.setAttribute('aria-current','page');
  if(POLL){clearTimeout(POLL);POLL=null;}
  ({start:viewStart,chat:viewChat,docs:viewDocs,more:viewMore})[v]();
}

/* ── Start: consolidated Zu-erledigen stack ── */
async function viewStart(){
  const r=await api('/portal/api/orgs/'+ORG+'/todo');
  el.innerHTML='<h1>Zu erledigen</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  const items=(r.data&&r.data.todo)||[];
  if(!items.length){el.appendChild(h('<div class="ptl-card"><h2>Alles erledigt</h2><p class="ptl-muted">Wir melden uns, wenn wir etwas brauchen.</p></div>'));return;}
  const list=h('<div class="ptl-stack"></div>');
  items.forEach(t=>list.appendChild(todoCard(t)));
  el.appendChild(list);
}
function todoCard(t){
  const c=h('<div class="ptl-card"><h2></h2><p class="ptl-muted"></p><div class="ptl-actions"></div></div>');
  c.querySelector('h2').textContent=t.title;
  const sub=t.kind==='request'?'Angefordertes Dokument':t.kind==='approval'?'Freigabe':'Formular';
  c.querySelector('.ptl-muted').textContent=sub+(t.deadline?' · Frist '+t.deadline:'')+(t.overdue?' — überfällig':'');
  const act=c.querySelector('.ptl-actions');
  if(t.kind==='request'&&t.itemId){act.appendChild(btn('Hochladen',()=>uploadForItem(t)));act.appendChild(btn('Nur bestätigen',()=>confirmItem(t),true));}
  if(t.kind==='approval'){act.appendChild(btn('Freigeben',()=>approvalFlow(t.id,'approve')));act.appendChild(btn('Ablehnen',()=>approvalFlow(t.id,'reject'),true));}
  if(t.kind==='form'){act.appendChild(btn('Öffnen',()=>viewForm(t.id)));}
  return c;
}
function btn(label,fn,ghost){const b=document.createElement('button');b.className='ptl-btn'+(ghost?'':' ptl-btn--primary');b.textContent=label;b.onclick=fn;return b;}
/* Native <dialog> prompt — design-system compliant (never window.prompt). */
function ask(title,label,okLabel){return new Promise(res=>{
  const d=document.createElement('dialog');d.className='ptl-dialog';
  d.innerHTML='<form method="dialog" class="ptl-card"><h2></h2><label class="ptl-label"></label><input class="ptl-input" required><div class="ptl-actions"><button value="" class="ptl-btn">Abbrechen</button><button class="ptl-btn ptl-btn--primary"></button></div></form>';
  d.querySelector('h2').textContent=title;
  d.querySelector('label').textContent=label;
  const inp=d.querySelector('input'),ok=d.querySelectorAll('button')[1];
  ok.textContent=okLabel;ok.value='ok';
  d.addEventListener('close',()=>{res(d.returnValue==='ok'?inp.value:null);d.remove();});
  document.body.appendChild(d);d.showModal();inp.focus();
});}
async function confirmItem(t){status('');const r=await post('/portal/api/requests/'+t.id+'/items/'+t.itemId+'/confirm');status(r.success?'Erledigt.':(r.message||'Fehler'));if(r.success)viewStart();}
function uploadForItem(t){
  const inp=document.createElement('input');inp.type='file';
  inp.onchange=async()=>{
    const fd=new FormData();fd.append('file',inp.files[0]);fd.append('reqId',t.id);fd.append('reqItemId',t.itemId);
    status('Lade hoch…');
    const r=await fetch(base+'/portal/api/orgs/'+ORG+'/uploads',{method:'POST',headers:{'X-PTL-CSRF':ck('ptl_csrf')},body:fd}).then(x=>x.json()).catch(()=>({success:false}));
    status(r.success?'Hochgeladen. Danke!':(r.message||'Fehler'));if(r.success)viewStart();
  };
  inp.click();
}
async function approvalFlow(id,decision){
  status('');
  if(decision==='reject'){
    const reason=await ask('Ablehnen','Warum lehnen Sie ab? (kurzer Grund)','Ablehnen');
    if(!reason){status('Abgebrochen — ein Grund ist nötig.');return;}
    const r=await post('/portal/api/approvals/'+id+'/decide',{decision:'reject',reason});
    status(r.success?'Abgelehnt.':(r.message||'Fehler'));if(r.success)viewStart();return;
  }
  // approve: request code first, then confirm with it
  const s=await post('/portal/api/approvals/'+id+'/start');
  if(!s.success){status(s.message||'Fehler beim Anfordern des Codes');return;}
  const code=await ask('Freigabe bestätigen','Wir haben Ihnen einen Bestätigungscode per E-Mail geschickt. Code eingeben:','Bestätigen');
  if(!code){status('Abgebrochen.');return;}
  const r=await post('/portal/api/approvals/'+id+'/decide',{decision:'approve',code:code.trim()});
  status(r.success?'Freigegeben. Danke!':(r.message||'Code ungültig'));
  if(r.success)viewStart();
}
async function viewForm(id){
  const r=await api('/portal/api/forms/'+id);
  if(!r.success){status('Formular nicht gefunden.');return;}
  const f=r.data.form;
  el.innerHTML='<h1>Formular</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  const wrap=h('<div class="ptl-card"></div>');
  f.fields.forEach(fd=>{
    const ans=(f.answers||[]).find(a=>a.key===fd.key);
    const w=h('<div class="ptl-field"><label class="ptl-label"></label></div>');
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
      status(rr.success?'Gespeichert.':(rr.error==='CONFLICT'?'Zwischenzeitlich geändert — neu laden.':(rr.message||'Fehler')));
    };
    w.appendChild(input);wrap.appendChild(w);
  });
  el.appendChild(wrap);
  if(f.state!=='submitted'){
    const b=btn('Absenden',async()=>{const rr=await post('/portal/api/forms/'+id+'/submit');status(rr.success?'Abgesendet. Danke!':(rr.message||'Bitte Pflichtfelder prüfen'));if(rr.success)route('start');});
    el.appendChild(b);
  }
}

/* ── Chat ── */
let LAST=0;
async function viewChat(){
  el.innerHTML='<h1>Chat</h1><div id="ptl-msgs" class="ptl-msgs"></div><form id="ptl-cf" class="ptl-composer"><input id="ptl-cm" class="ptl-input" maxlength="4000" required autocomplete="off" placeholder="Nachricht schreiben…"><button class="ptl-btn ptl-btn--primary">Senden</button></form><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  document.getElementById('ptl-cf').addEventListener('submit',async e=>{
    e.preventDefault();
    const inp=document.getElementById('ptl-cm'),body=inp.value.trim();
    if(!body)return;
    inp.value='';
    const r=await post('/portal/api/orgs/'+ORG+'/messages',{body,clientUuid:crypto.randomUUID()});
    if(r.success)pullMessages();else status(r.message||'Fehler');
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
      last.querySelector('.ptl-msg__meta').textContent=(m.mine?'Sie':(m.kind==='staff'?'Ihr Anbieter':m.author))+' · '+new Date(m.createdAt).toLocaleString();
    });
    if(r.data.messages.length){box.scrollTop=box.scrollHeight;post('/portal/api/orgs/'+ORG+'/read',{lastReadId:LAST});}
    POLL=setTimeout(pullMessages,r.data.pollAfterMs||15000);
  }else{POLL=setTimeout(pullMessages,30000);}
}

/* ── Dokumente ── */
async function viewDocs(){
  el.innerHTML='<h1>Dokumente</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div><div class="ptl-card"><button class="ptl-btn ptl-btn--primary" id="ptl-up">Datei hochladen</button></div><div id="ptl-files"></div>';
  document.getElementById('ptl-up').onclick=()=>{
    const inp=document.createElement('input');inp.type='file';
    inp.onchange=async()=>{
      const fd=new FormData();fd.append('file',inp.files[0]);
      status('Lade hoch…');
      const r=await fetch(base+'/portal/api/orgs/'+ORG+'/uploads',{method:'POST',headers:{'X-PTL-CSRF':ck('ptl_csrf')},body:fd}).then(x=>x.json()).catch(()=>({success:false}));
      status(r.success?'Hochgeladen. Danke!':(r.message||'Fehler'));
      if(r.success)viewDocs();
    };
    inp.click();
  };
  const r=await api('/portal/api/orgs/'+ORG+'/documents');
  const box=document.getElementById('ptl-files');
  const files=(r.data&&r.data.files)||[];
  if(!files.length){box.appendChild(h('<p class="ptl-muted">Noch keine Dokumente für Sie bereitgestellt.</p>'));return;}
  files.forEach(f=>{
    const a=document.createElement('a');
    a.className='ptl-file';a.href=base+'/portal/api/orgs/'+ORG+'/files/'+f.id+'/download';
    a.textContent='📄 '+f.name+' ('+Math.ceil(f.size/1024)+' KB)';
    box.appendChild(a);
  });
}

/* ── Mehr ── */
async function viewMore(){
  const r=await api('/portal/api/orgs/'+ORG+'/tasks');
  const d=await api('/portal/api/devices');
  el.innerHTML='<h1>Mehr</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div>';
  el.appendChild(h('<h2>Aufgaben</h2>'));
  const tasks=(r.data&&r.data.tasks)||[];
  el.appendChild(h(tasks.length?'<div class="ptl-card">'+tasks.map(t=>'<p>'+esc(t.title)+' — <b>'+esc(t.statusLabel)+'</b>'+(t.dueDate?' · '+t.dueDate:'')+'</p>').join('')+'</div>':'<p class="ptl-muted">Keine Aufgaben sichtbar.</p>'));
  el.appendChild(h('<h2>Meine Geräte</h2>'));
  const devs=(d.data&&d.data.devices)||[];
  const dl=h('<div class="ptl-card"></div>');
  if(!devs.length){dl.appendChild(h('<p class="ptl-muted">Keine Geräte verbunden.</p>'));}
  devs.forEach(dv=>{
    const row=h('<div class="ptl-dev"><span></span></div>');
    row.querySelector('span').textContent=dv.name+' ('+dv.platform+')';
    const rm=btn('Entfernen',async()=>{const rr=await fetch(base+'/portal/api/devices/'+dv.id,{method:'DELETE',headers:{'X-PTL-CSRF':ck('ptl_csrf')}});if(rr.ok||rr.status===204)viewMore();},true);
    row.appendChild(rm);dl.appendChild(row);
  });
  el.appendChild(dl);
  el.appendChild(h('<h2>Konto</h2>'));
  const out=btn('Abmelden',async()=>{await post('/portal/api/auth/logout');location.href=base+'/portal/login';},true);
  el.appendChild(out);
}
document.querySelectorAll('.ptl-tab').forEach(t=>t.addEventListener('click',()=>route(t.dataset.view)));
init();
})();
