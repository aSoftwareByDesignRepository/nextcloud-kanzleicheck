(function(){'use strict';
const el=document.getElementById('ptl-view'),base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const ck=n=>{const m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]+)'));return m?decodeURIComponent(m[1]):'';};
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json','X-PTL-CSRF':ck('ptl_csrf')}},o||{}));return r.json().catch(()=>({success:false}));};
let org=null;
const esc=t=>{const d=document.createElement('div');d.textContent=t||'';return d.innerHTML;};
async function orgs(){const r=await api('/portal/api/orgs');if(r.success&&r.data&&r.data.orgs.length){org=r.data.orgs[0].id;return r.data.orgs;}el.innerHTML='<p class="ptl-muted">Kein Zugang freigeschaltet.</p>';return[];}
async function view(v){document.querySelectorAll('.ptl-tab').forEach(t=>t.removeAttribute('aria-current'));const tab=document.querySelector('.ptl-tab[data-view="'+v+'"]');tab&&tab.setAttribute('aria-current','page');
if(!org){const o=await orgs();if(!o.length)return;org=o[0].id;}
if(v==='chat'){const r=await api('/portal/api/orgs/'+org+'/messages');el.innerHTML='<h1>Chat</h1>'+(r.data?r.data.messages.map(m=>'<p><b>'+esc(m.author)+':</b> '+esc(m.body)+'</p>').join(''):'')+'<form id="cf"><input class="ptl-input" id="cm" maxlength="4000" required><button class="ptl-btn ptl-btn--primary">Senden</button></form>';document.getElementById('cf').addEventListener('submit',async e=>{e.preventDefault();await api('/portal/api/orgs/'+org+'/messages',{method:'POST',body:JSON.stringify({body:document.getElementById('cm').value,clientUuid:crypto.randomUUID()})});view('chat');});}
else if(v==='docs'){el.innerHTML='<h1>Dokumente</h1><p class="ptl-muted">Ihre Dokumente erscheinen hier.</p>';}
else if(v==='more'){const r=await api('/portal/api/orgs/'+org+'/tasks');el.innerHTML='<h1>Mehr</h1>'+(r.data?r.data.tasks.map(t=>'<p>'+esc(t.title)+' — '+esc(t.statusLabel)+'</p>').join(''):'')+'<button class="ptl-btn" id="lo">Abmelden</button>';document.getElementById('lo').addEventListener('click',async()=>{await api('/portal/api/auth/logout',{method:'POST'});location.href=base+'/portal/login';});}
else{const r=await api('/portal/api/orgs/'+org+'/todo');el.innerHTML='<h1>Zu erledigen</h1>'+(r.data&&r.data.todo.length?r.data.todo.map(t=>'<div class="ptl-card"><h2>'+esc(t.title)+'</h2><p class="ptl-muted">'+esc(t.kind)+(t.overdue?' — überfällig':'')+'</p><button class="ptl-btn ptl-btn--primary">Öffnen</button></div>').join(''):'<p class="ptl-muted">Alles erledigt. Wir melden uns, wenn wir etwas brauchen.</p>');}}
document.querySelectorAll('.ptl-tab').forEach(t=>t.addEventListener('click',()=>view(t.dataset.view)));
view('start');
})();
