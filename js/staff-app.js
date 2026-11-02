(function(){'use strict';
/* PortalCheck staff SPA: Übersicht · Nachrichten · Aufgaben · Anfragen ·
   Mandanten · Uploads · Einstellungen. esc() for all user data — no innerHTML. */
const el=document.getElementById('ptl-staff-view'),base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json'}},o||{}));return r.json().catch(()=>({success:false}));};
const get=u=>api(u),post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})}),put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})}),del=u=>api(u,{method:'DELETE'});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};
const btn=(label,fn,primary)=>{const b=document.createElement('button');b.className='ptl-btn'+(primary?' ptl-btn--primary':'');b.textContent=label;b.onclick=fn;return b;};
const status=t=>{const s=document.getElementById('ptl-live');if(s)s.textContent=t||'';};
let ME_ADMIN=false;

/* native dialog helpers (never window.prompt/confirm) */
function ask(title,label,okLabel,fields){return new Promise(res=>{
  const d=document.createElement('dialog');d.className='ptl-dialog';
  let inner='<form method="dialog" class="ptl-card"><h2></h2>';
  (fields||[{name:'v',label}]).forEach(f=>{
    inner+='<label class="ptl-label" for="fd-'+esc(f.name)+'"></label>';
    inner+=f.type==='select'?'<select class="ptl-input" name="'+esc(f.name)+'">'+(f.options||[]).map(o=>'<option value="'+esc(o[0])+'">'+esc(o[1])+'</option>').join('')+'</select>'
      :f.type==='textarea'?'<textarea class="ptl-input" name="'+esc(f.name)+'" rows="3"></textarea>'
      :'<input class="ptl-input" name="'+esc(f.name)+'" type="'+(f.type||'text')+'" '+(f.required?'required':'')+'>';
  });
  inner+='<div class="ptl-actions"><button value="" class="ptl-btn">Abbrechen</button><button value="ok" class="ptl-btn ptl-btn--primary"></button></div></form>';
  d.innerHTML=inner;d.querySelector('h2').textContent=title;
  d.querySelectorAll('.ptl-label').forEach((l,i)=>{const f=(fields||[{label}])[i];l.textContent=f.label;});
  d.querySelector('.ptl-btn--primary').textContent=okLabel;
  d.addEventListener('close',()=>{
    if(d.returnValue!=='ok'){res(null);d.remove();return;}
    const out={};d.querySelectorAll('[name]').forEach(i=>out[i.name]=i.value);res(out);d.remove();
  });
  document.body.appendChild(d);d.showModal();
});}
function confirmDlg(title,text){return new Promise(res=>{
  const d=document.createElement('dialog');d.className='ptl-dialog';
  d.innerHTML='<form method="dialog" class="ptl-card"><h2></h2><p class="ptl-muted"></p><div class="ptl-actions"><button value="" class="ptl-btn">Abbrechen</button><button value="ok" class="ptl-btn ptl-btn--primary">Bestätigen</button></div></form>';
  d.querySelector('h2').textContent=title;d.querySelector('.ptl-muted').textContent=text;
  d.addEventListener('close',()=>{res(d.returnValue==='ok');d.remove();});
  document.body.appendChild(d);d.showModal();});}

const NAV=[['overview','Übersicht'],['inbox','Nachrichten'],['board','Aufgaben'],['requests','Anfragen'],['orgs','Mandanten'],['uploads','Uploads'],['settings','Einstellungen']];
function chrome(active,title){
  el.innerHTML='<nav class="ptl-nav" aria-label="Bereiche">'+NAV.map(([k,l])=>'<button class="ptl-navbtn'+(k===active?' ptl-navbtn--active':'')+'" data-v="'+k+'">'+l+'</button>').join('')+'</nav><h1>'+esc(title)+'</h1><div id="ptl-live" role="status" aria-live="polite" class="ptl-status"></div><div id="ptl-body"></div>';
  el.querySelectorAll('.ptl-navbtn').forEach(b=>b.onclick=()=>VIEWS[b.dataset.v]());
  return document.getElementById('ptl-body');
}

/* ── Übersicht ── */
async function vOverview(){
  const r=await get('/api/portal/overview');
  const body=chrome('overview','Was braucht Aufmerksamkeit?');
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||'Fehler')+'</p>'));return;}
  const s=r.data.stats;
  body.appendChild(h('<div class="ptl-stats">'+[
    [s.unread,'Ungelesene Nachrichten','inbox'],
    [s.newUploads,'Neue Uploads','uploads'],
    [s.overdueRequests,'Überfällige Anfragen','requests'],
    [s.openApprovals,'Offene Freigaben','requests'],
  ].map(([n,l,v])=>'<button class="ptl-stat" data-v="'+v+'"><b>'+n+'</b>'+l+'</button>').join('')+'</div>'));
  body.querySelectorAll('.ptl-stat').forEach(b=>b.onclick=()=>VIEWS[b.dataset.v]());
  const q=r.data.queue||[];
  if(q.length){
    body.appendChild(h('<h2>Zuletzt aktiv</h2>'));
    const list=h('<div class="ptl-list"></div>');
    q.slice(0,8).forEach(c=>{
      const row=h('<button class="ptl-row"><b></b><span class="ptl-muted"></span>'+(c.unread?'<span class="ptl-badge">'+c.unread+'</span>':'')+'</button>');
      row.querySelector('b').textContent=c.orgName;row.querySelector('.ptl-muted').textContent=c.lastPreview||'';
      row.onclick=()=>openConv(c.orgId,c.orgName);list.appendChild(row);
    });
    body.appendChild(list);
  } else body.appendChild(h('<p class="ptl-muted">Alles erledigt — keine offenen Vorgänge.</p>'));
}

/* ── Nachrichten (inbox + thread) ── */
async function vInbox(){
  const body=chrome('inbox','Nachrichten');
  const r=await get('/api/portal/inbox');
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||'Fehler')+'</p>'));return;}
  const convs=r.data.conversations||[];
  if(!convs.length){body.appendChild(h('<p class="ptl-muted">Keine Unterhaltungen.</p>'));return;}
  const list=h('<div class="ptl-list"></div>');
  convs.forEach(c=>{
    const row=h('<button class="ptl-row"><b></b><span class="ptl-muted"></span>'+(c.unread?'<span class="ptl-badge">'+c.unread+'</span>':'')+'</button>');
    row.querySelector('b').textContent=c.orgName;
    row.querySelector('.ptl-muted').textContent=(c.lastAuthorName?c.lastAuthorName+': ':'')+(c.lastPreview||'')+(c.state==='archived'?' (archiviert)':'');
    row.onclick=()=>openConv(c.orgId,c.orgName);list.appendChild(row);
  });
  body.appendChild(list);
}
let CONV_ORG=null,CONV_LAST=0,CONV_TIMER=null;
async function openConv(orgId,name){
  if(CONV_TIMER){clearTimeout(CONV_TIMER);CONV_TIMER=null;}
  CONV_ORG=orgId;CONV_LAST=0;
  const body=chrome('inbox','Chat — '+name);
  body.appendChild(h('<div id="ptl-msgs" class="ptl-msgs"></div>'));
  body.appendChild(h('<form id="ptl-cf" class="ptl-composer"><input id="ptl-cm" class="ptl-input" maxlength="4000" required autocomplete="off" placeholder="Antwort schreiben…"><button class="ptl-btn ptl-btn--primary">Senden</button></form>'));
  body.querySelector('#ptl-cf').addEventListener('submit',async e=>{
    e.preventDefault();const inp=document.getElementById('ptl-cm'),v=inp.value.trim();if(!v)return;
    const r=await post('/api/portal/orgs/'+orgId+'/messages',{body:v,clientUuid:crypto.randomUUID()});
    if(r.success){inp.value='';pullConv();}else status(r.message||'Fehler');
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
    if(r.data.state==='archived'){document.getElementById('ptl-cf')?.remove();status('Unterhaltung archiviert (nur Lesen).');}
  }
  CONV_TIMER=setTimeout(pullConv,15000);
}

/* ── Aufgaben board ── */
async function vBoard(){
  const r=await get('/api/portal/board');
  const body=chrome('board','Aufgaben');
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||'Fehler')+'</p>'));return;}
  body.appendChild(btn('+ Neue Aufgabe',async()=>{
    const orgs=(await get('/api/portal/orgs')).data.orgs;
    const f=await ask('Neue Aufgabe',null,'Anlegen',[
      {name:'orgId',label:'Mandat',type:'select',options:orgs.map(o=>[o.id,o.name])},
      {name:'title',label:'Titel',required:true},
      {name:'note',label:'Notiz',type:'textarea'},
      {name:'priority',label:'Priorität',type:'select',options:[['low','Niedrig'],['normal','Normal'],['high','Hoch'],['urgent','Dringend']]},
      {name:'dueDate',label:'Fällig',type:'date'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/tasks',{orgId:+f.orgId,title:f.title,note:f.note||null,priority:f.priority,dueDate:f.dueDate||null});
    status(rr.success?'Angelegt.':(rr.message||'Fehler'));if(rr.success)vBoard();
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
function taskCard(t){
  const c=h('<div class="ptl-card2"><b></b><div class="ptl-muted"></div><div class="ptl-cardmeta"></div></div>');
  c.querySelector('b').textContent=t.title;
  c.querySelector('.ptl-muted').textContent=t.orgName;
  const meta=c.querySelector('.ptl-cardmeta');
  meta.appendChild(h('<span class="ptl-pill ptl-pill--'+esc(t.priority)+'">'+esc(t.priority)+'</span>'));
  if(t.dueDate)meta.appendChild(h('<span class="ptl-pill'+(t.overdue?' ptl-pill--overdue':'')+'">'+esc(t.dueDate)+(t.overdue?' ⚠':'')+'</span>'));
  const move=btn('Verschieben…',async()=>{
    const f=await ask('Verschieben nach…',null,'Verschieben',[{name:'status',label:'Status',type:'select',options:[['new','Neu'],['in_progress','In Bearbeitung'],['waiting','Wartet'],['done','Erledigt']]}]);
    if(!f)return;
    const r=await put('/api/portal/tasks/'+t.id+'/move',{status:f.status,revision:t.revision});
    if(r.success)vBoard();else if(r.error==='CONFLICT')status('Zwischenzeitlich geändert — neu geladen.')||vBoard();else status(r.message||'Fehler');
  });
  move.className='ptl-btn ptl-btn--sm';meta.appendChild(move);
  const vis=btn(t.visibility==='status'?'Im Portal: an':'Im Portal: aus',async()=>{
    const r=await put('/api/portal/tasks/'+t.id,{portalVisibility:t.visibility==='status'?'none':'status',revision:t.revision});
    if(r.success)vBoard();else if(r.error==='CONFLICT')vBoard();else status(r.message||'Fehler');
  },false);
  vis.className='ptl-btn ptl-btn--sm';meta.appendChild(vis);
  return c;
}

/* ── Anfragen ── */
async function vRequests(){
  const body=chrome('requests','Dokumenten-Anfragen');
  body.appendChild(btn('+ Neue Anfrage',async()=>{
    const orgs=(await get('/api/portal/orgs')).data.orgs;
    const f=await ask('Neue Anfrage',null,'Anlegen',[
      {name:'orgId',label:'Mandat',type:'select',options:orgs.map(o=>[o.id,o.name])},
      {name:'title',label:'Titel',required:true},
      {name:'items',label:'Benötigte Unterlagen (eine pro Zeile)',type:'textarea'},
      {name:'deadline',label:'Frist',type:'date'},
    ]);
    if(!f)return;
    const items=f.items.split('\n').map(x=>x.trim()).filter(Boolean).map(l=>({label:l,kind:'upload'}));
    if(!items.length){status('Mindestens ein Punkt nötig.');return;}
    const r=await post('/api/portal/requests',{orgId:+f.orgId,title:f.title,deadline:f.deadline||null,items});
    status(r.success?'Angelegt.':(r.message||'Fehler'));if(r.success)vRequests();
  },true));
  const r=await get('/api/portal/requests');
  const reqs=(r.data&&r.data.requests)||[];
  const list=h('<div class="ptl-list"></div>');
  reqs.forEach(rq=>{
    const row=h('<div class="ptl-card"><b></b><div class="ptl-muted"></div><div class="ptl-items"></div></div>');
    row.querySelector('b').textContent=rq.title;
    row.querySelector('.ptl-muted').textContent=(rq.deadline?'Frist '+rq.deadline:'keine Frist')+(rq.overdue?' — ÜBERFÄLLIG':'')+' · '+rq.state+' · '+rq.items.accepted+'/'+rq.items.total+' angenommen';
    const items=row.querySelector('.ptl-items');
    const detail=h('<details class="ptl-items-detail"><summary>Details</summary></details>');
    items.appendChild(detail);
    row.querySelector('details').addEventListener('toggle',async ev=>{
      if(!ev.target.open||ev.target.dataset.loaded)return;
      ev.target.dataset.loaded='1';
      const itemsList=h('<div class="ptl-itemlist"></div>');
      detail.appendChild(itemsList);
    });
    list.appendChild(row);
  });
  if(!reqs.length)list.appendChild(h('<p class="ptl-muted">Keine Anfragen.</p>'));
  body.appendChild(list);
}

/* ── Mandanten ── */
async function vOrgs(){
  const body=chrome('orgs','Mandanten');
  body.appendChild(btn('+ Neuer Mandant',async()=>{
    const f=await ask('Neuer Mandant',null,'Anlegen',[
      {name:'name',label:'Name',required:true},
      {name:'ref',label:'Referenz (optional)'},
      {name:'kind',label:'Art',type:'select',options:[['company','Firma'],['private','Privat'],['object','Objekt'],['other','Sonstiges']]},
    ]);
    if(!f)return;
    const r=await post('/api/portal/orgs',{name:f.name,ref:f.ref||null,kind:f.kind});
    status(r.success?'Angelegt.':(r.message||'Fehler'));if(r.success)vOrgs();
  },true));
  const q=new URLSearchParams(location.search).get('q')||'';
  body.appendChild(h('<input id="ptl-q" class="ptl-input" placeholder="Suchen…" value="'+esc(q)+'">'));
  body.querySelector('#ptl-q').oninput=async e=>{const rr=await get('/api/portal/orgs?q='+encodeURIComponent(e.target.value));renderOrgs(rr.data&&rr.data.orgs||[]);};
  body.appendChild(h('<div id="ptl-orglist" class="ptl-list"></div>'));
  const r=await get('/api/portal/orgs');
  renderOrgs(r.data&&r.data.orgs||[]);
  function renderOrgs(orgs){
    const list=document.getElementById('ptl-orglist');list.innerHTML='';
    if(!orgs.length){list.appendChild(h('<p class="ptl-muted">Keine Mandanten.</p>'));return;}
    orgs.forEach(o=>{
      const row=h('<button class="ptl-row"><b></b><span class="ptl-muted"></span><span class="ptl-pill"></span></button>');
      row.querySelector('b').textContent=o.name;
      row.querySelector('.ptl-muted').textContent=[o.ref,o.kind,o.state].filter(Boolean).join(' · ');
      row.querySelector('.ptl-pill').textContent=o.appOn?'App an':'App aus';
      row.onclick=()=>openOrg(o.id);list.appendChild(row);
    });
  }
}
async function openOrg(id){
  const r=await get('/api/portal/orgs/'+id);
  if(!r.success){status('Nicht gefunden.');return;}
  const o=r.data.org,body=chrome('orgs','Mandant: '+o.name);
  const info=h('<div class="ptl-card"><div class="ptl-kv"></div><div class="ptl-actions"></div></div>');
  info.querySelector('.ptl-kv').innerHTML=[
    ['Name',o.name],['Referenz',o.ref||'—'],['Art',o.kind],['Status',o.state],['App-Zugang',o.appOn?'aktiv':'aus'],['Buchungslink',o.bookUrl||'—'],['Intake',(r.data.intake&&r.data.intake.address)||'—'],
  ].map(([k,v])=>'<div class="ptl-kvrow"><span>'+esc(k)+'</span><b>'+esc(v)+'</b></div>').join('');
  const act=info.querySelector('.ptl-actions');
  act.appendChild(btn(o.appOn?'App deaktivieren':'App aktivieren',async()=>{
    const rr=await put('/api/portal/orgs/'+id,{appOn:o.appOn?false:true});
    if(rr.success)openOrg(id);else if(rr.error==='NO_PORTAL_SLOT')status('Kein freier App-Slot (Lizenz).');else status(rr.message||'Fehler');
  },!o.appOn));
  if(o.state==='active')act.appendChild(btn('Offboarden',async()=>{
    if(!(await confirmDlg('Offboarden','Chat wird archiviert, Mitglieder verlieren Zugang. Fortfahren?')))return;
    await post('/api/portal/orgs/'+id+'/offboard');openOrg(id);
  }));
  body.appendChild(info);
  body.appendChild(h('<h2>Kontakte</h2><div id="ptl-members"></div>'));
  const ml=document.getElementById('ptl-members');
  (r.data.members||[]).forEach(m=>{
    const row=h('<div class="ptl-dev"><span></span></div>');
    row.querySelector('span').textContent=(m.displayName||m.email)+(m.status!=='active'?' ('+m.status+')':'');
    const rm=btn('Entfernen',async()=>{await del('/api/portal/orgs/'+id+'/members/'+m.identityId);openOrg(id);},false);
    rm.className='ptl-btn ptl-btn--sm';row.appendChild(rm);ml.appendChild(row);
  });
  body.appendChild(btn('+ Kontakt hinzufügen',async()=>{
    const f=await ask('Kontakt hinzufügen',null,'Hinzufügen',[{name:'email',label:'E-Mail',type:'email',required:true},{name:'displayName',label:'Name'}]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/members',{email:f.email,displayName:f.displayName});
    status(rr.success?'Hinzugefügt.':(rr.message||'Fehler'));if(rr.success)openOrg(id);
  }));
  body.appendChild(h('<h2>Upload-Links</h2><div id="ptl-ulinks"></div>'));
  const ul=document.getElementById('ptl-ulinks');
  (r.data.ulinks||[]).forEach(l=>{
    const row=h('<div class="ptl-dev"><span></span></div>');
    row.querySelector('span').textContent=l.label+' · '+l.usedFiles+'/'+l.maxFiles+(l.revoked?' (widerrufen)':'');
    if(!l.revoked){const rm=btn('Widerrufen',async()=>{await del('/api/portal/ulinks/'+l.id);openOrg(id);},false);rm.className='ptl-btn ptl-btn--sm';row.appendChild(rm);}
    ul.appendChild(row);
  });
  body.appendChild(btn('+ Upload-Link',async()=>{
    const f=await ask('Upload-Link erstellen',null,'Erstellen',[
      {name:'label',label:'Bezeichnung',required:true},
      {name:'expiryDays',label:'Gültig (Tage)',type:'number'},
      {name:'maxFiles',label:'Max. Dateien',type:'number'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/ulinks',{label:f.label,expiryDays:+f.expiryDays||7,maxFiles:+f.maxFiles||20});
    if(rr.success){const f2=await ask('Link erstellt','URL (einmalig sichtbar): '+rr.data.link.url,'OK');}else status(rr.message||'Fehler');
    openOrg(id);
  }));
}

/* ── Uploads ── */
async function vUploads(){
  const body=chrome('uploads','Eingang — Upload-Warteschlange');
  const r=await get('/api/portal/uploads');
  const ups=(r.data&&r.data.uploads)||[];
  if(!ups.length){body.appendChild(h('<p class="ptl-muted">Keine neuen Uploads.</p>'));}
  const list=h('<div class="ptl-list"></div>');
  ups.forEach(u=>{
    const row=h('<div class="ptl-card"><b></b><div class="ptl-muted"></div><div class="ptl-actions"></div></div>');
    row.querySelector('b').textContent=u.fileName;
    row.querySelector('.ptl-muted').textContent=u.orgName+' · '+u.uploader+' · '+u.source+' · '+new Date(u.createdAt).toLocaleString()+(u.duplicate?' · DUPLIKAT':'')+(u.note?' · '+u.note:'');
    const act=row.querySelector('.ptl-actions');
    act.appendChild(btn('Gesehen',async()=>{await put('/api/portal/uploads/'+u.id+'/review',{state:'eingesehen'});vUploads();}));
    act.appendChild(btn('Abgelegt',async()=>{await put('/api/portal/uploads/'+u.id+'/review',{state:'abgelegt'});vUploads();},true));
    list.appendChild(row);
  });
  body.appendChild(list);
}

/* ── Einstellungen ── */
async function vSettings(){
  const r=await get('/api/portal/settings');
  const body=chrome('settings','Einstellungen');
  if(!r.success){body.appendChild(h('<p>'+esc(r.message||'Nur für App-Admins')+'</p>'));return;}
  const s=r.data.settings,d=r.data.door;
  const form=h('<div class="ptl-card"><h2>Allgemein</h2><label class="ptl-label">Branche (Preset)</label><select id="s-preset" class="ptl-input"></select><label class="ptl-label">Anbieter-Name (E-Mails)</label><input id="s-label" class="ptl-input"><label class="ptl-label">Upload-Limit (MB)</label><input id="s-max" class="ptl-input" type="number"><label class="ptl-label">OTP-Gültigkeit (Minuten)</label><input id="s-ttl" class="ptl-input" type="number"><div class="ptl-actions"></div></div>');
  const sel=form.querySelector('#s-preset');
  r.data.presets.forEach(p=>sel.appendChild(new Option(p,p)));
  sel.value=s.preset;form.querySelector('#s-label').value=s.providerLabel;form.querySelector('#s-max').value=s.uploadMaxMb;form.querySelector('#s-ttl').value=s.otpTtlMin;
  form.querySelector('.ptl-actions').appendChild(btn('Speichern',async()=>{
    const rr=await put('/api/portal/settings',{preset:sel.value,providerLabel:form.querySelector('#s-label').value,uploadMaxMb:+form.querySelector('#s-max').value,otpTtlMin:+form.querySelector('#s-ttl').value});
    status(rr.success?'Gespeichert.':(rr.message||'Fehler'));
  },true));
  body.appendChild(form);
  // door
  const door=h('<div class="ptl-card"><h2>Zugang (Tür)</h2><label class="ptl-label"><input type="checkbox" id="d-restr"> Beschränkt (nur ausgewählte Gruppen/Benutzer)</label><p class="ptl-muted">Plattform-Beschränkung: '+(d.platformGroups.length?esc(d.platformGroups.join(', ')):'keine')+'</p><label class="ptl-label">Erlaubte Benutzer (kommagetrennt)</label><input id="d-users" class="ptl-input"><label class="ptl-label">App-Admins (kommagetrennt)</label><input id="d-admins" class="ptl-input"><div class="ptl-actions"></div></div>');
  door.querySelector('#d-restr').checked=d.restricted;
  door.querySelector('#d-users').value=(d.allowedUsers||[]).join(', ');
  door.querySelector('#d-admins').value=(d.appAdmins||[]).join(', ');
  door.querySelector('.ptl-actions').appendChild(btn('Tür speichern',async()=>{
    const csv=v=>v.split(',').map(x=>x.trim()).filter(Boolean);
    const rr=await put('/api/portal/door',{restricted:door.querySelector('#d-restr').checked,groups:[],users:csv(door.querySelector('#d-users').value),appAdmins:csv(door.querySelector('#d-admins').value)});
    status(rr.success?'Gespeichert.':(rr.message||'Fehler'));
  },true));
  body.appendChild(door);
  // license + diagnostics
  const l=await get('/api/portal/license');
  if(l.success&&l.data){
    const lic=l.data.license;
    body.appendChild(h('<div class="ptl-card"><h2>Lizenz</h2><p>Status: <b>'+esc(lic.status)+'</b> · Portal-Kunden: '+lic.usedPortalClients+'/'+lic.portalClients+' · Mobile-Seats: '+(lic.mobileSeats??'—')+'</p><div id="l-actions"></div></div>'));
    body.querySelector('#l-actions').appendChild(btn('Lizenzschlüssel einspielen',async()=>{
      const f=await ask('PTL2-Schlüssel','Schlüssel einfügen:','Einspielen');
      if(!f)return;
      const rr=await post('/api/portal/license',{key:f.v});
      status(rr.success?'Eingespielt.':(rr.message||'Ungültiger Schlüssel'));if(rr.success)vSettings();
    }));
  }
  const diag=await get('/api/portal/diagnostics');
  if(diag.success&&diag.data){
    const g=diag.data;
    body.appendChild(h('<div class="ptl-card"><h2>Diagnose</h2><p>Cron: '+(g.cron.stale?'<b>veraltet</b>':'ok')+' · Outbox: '+g.outbox.pending+' wartend / '+g.outbox.failed+' fehlgeschlagen · Dokumenten-Wurzel: '+(g.docsRoot.healthy?'ok':'FHLER/konfigurieren')+' · Lizenz: '+esc(g.license.status)+'</p></div>'));
  }
}

const VIEWS={overview:vOverview,inbox:vInbox,board:vBoard,requests:vRequests,orgs:vOrgs,uploads:vUploads,settings:vSettings};
vOverview();
})();
