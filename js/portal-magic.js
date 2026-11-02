(function(){'use strict';
const b=document.getElementById('ptl-magic-btn'),s=document.getElementById('ptl-magic-status');
b&&b.addEventListener('click',async()=>{const t=b.dataset.token;const base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';b.disabled=true;const r=await fetch(base+'/portal/auth/magic/'+t,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}).then(x=>x.json()).catch(()=>({success:false}));if(r.success){location.href=base+(r.data&&r.data.redirect||'/portal');}else{s.textContent='Dieser Link ist nicht mehr gültig.';b.hidden=true;}});
})();
