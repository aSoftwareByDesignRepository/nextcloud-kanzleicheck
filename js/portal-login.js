(function(){'use strict';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('portalcheck',s):s;
const api=async(u,b)=>{const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});return r.json().catch(()=>({success:false}));};
const base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const send=document.getElementById('ptl-send-form'),verify=document.getElementById('ptl-verify-form'),status=document.getElementById('ptl-login-status'),resend=document.getElementById('ptl-resend-btn');
let email='';
function err(msg){status.textContent=msg||'';}
const msgFor=r=>r&&r.error==='INVALID_CODE'?t('The code is incorrect or has expired.'):(r&&r.message)||t('Error');
send&&send.addEventListener('submit',async e=>{e.preventDefault();email=document.getElementById('ptl-email').value.trim();err('');const r=await api(base+'/portal/api/auth/send',{email});if(r.success!==false){send.hidden=true;verify.hidden=false;document.getElementById('ptl-code').focus();resend.disabled=true;setTimeout(()=>resend.disabled=false,30000);}else{err(msgFor(r));}});
resend&&resend.addEventListener('click',async()=>{await api(base+'/portal/api/auth/send',{email});resend.disabled=true;setTimeout(()=>resend.disabled=false,30000);});
verify&&verify.addEventListener('submit',async e=>{e.preventDefault();const code=document.getElementById('ptl-code').value.trim();const r=await api(base+'/portal/api/auth/verify',{email,code});if(r.success){location.href=base+(r.data&&r.data.redirect||'/portal');}else{err(msgFor(r));}});
const ci=document.getElementById('ptl-code');ci&&ci.addEventListener('input',()=>{if(/^\d{6}$/.test(ci.value))verify.requestSubmit();});
})();
