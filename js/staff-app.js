(function(){'use strict';
const el=document.getElementById('ptl-staff-view'),base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json'}},o||{}));return r.json().catch(()=>({success:false}));};
const esc=t=>{const d=document.createElement('div');d.textContent=t||'';return d.innerHTML;};
(async()=>{const r=await api('/api/portal/overview');if(!r.success){el.innerHTML='<p>'+esc(r.message||'Kein Zugriff')+'</p>';return;}const s=r.data.stats;el.innerHTML='<h1>Übersicht</h1><div class="ptl-stats"><a class="ptl-stat" href="#inbox"><b>'+s.unread+'</b>Ungelesene Nachrichten</a><a class="ptl-stat" href="#uploads"><b>'+s.newUploads+'</b>Neue Uploads</a><a class="ptl-stat" href="#requests"><b>'+s.overdueRequests+'</b>Überfällige Anfragen</a><a class="ptl-stat" href="#approvals"><b>'+s.openApprovals+'</b>Offene Freigaben</a></div><div id="q"><h2>Offene Vorgänge</h2>'+r.data.queue.map(c=>'<p>'+esc(c.orgName||'')+' — '+esc(c.lastPreview||'')+'</p>').join('')+'</div>';})();
})();
