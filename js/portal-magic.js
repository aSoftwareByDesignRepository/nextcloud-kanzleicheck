(function(){'use strict';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('kanzleicheck',s):s;
const b=document.getElementById('kzc-magic-btn'),s=document.getElementById('kzc-magic-status');
b&&b.addEventListener('click',async()=>{const tk=b.dataset.token;const base=location.pathname.split('/apps/kanzleicheck')[0]+'/apps/kanzleicheck';b.disabled=true;const r=await fetch(base+'/portal/auth/magic/'+tk,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}).then(x=>x.json()).catch(()=>({success:false}));if(r.success){location.href=base+(r.data&&r.data.redirect||'/portal');}else{s.textContent=t('This link is no longer valid.');b.hidden=true;}});
})();
