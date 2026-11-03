(function(){'use strict';
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('portalcheck',s):s;
const api=async(u,b)=>{const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});return r.json().catch(()=>({success:false}));};
const base=location.pathname.split('/apps/portalcheck')[0]+'/apps/portalcheck';
const send=document.getElementById('ptl-send-form'),verify=document.getElementById('ptl-verify-form'),status=document.getElementById('ptl-login-status'),resend=document.getElementById('ptl-resend-btn');
let email='';
function err(msg){status.textContent=msg||'';}
function fieldErr(id,msg){const span=document.querySelector('.ptl-field-error[data-for="'+id+'"]'),inp=document.getElementById(id);if(span){span.textContent=msg||'';span.hidden=!msg;}if(inp){if(msg){inp.setAttribute('aria-invalid','true');}else{inp.removeAttribute('aria-invalid');}}}
const msgFor=r=>r&&r.error==='INVALID_CODE'?t('The code is incorrect or has expired.'):(r&&r.message)||t('Error');
const fieldMsg=(r,key)=>((r&&r.fields&&r.fields[key])||msgFor(r));
send&&send.addEventListener('submit',async e=>{e.preventDefault();email=document.getElementById('ptl-email').value.trim();err('');fieldErr('ptl-email','');const r=await api(base+'/portal/api/auth/send',{email});if(r.success!==false){send.hidden=true;verify.hidden=false;document.getElementById('ptl-code').focus();resend.disabled=true;setTimeout(()=>resend.disabled=false,30000);}else{const m=fieldMsg(r,'email');fieldErr('ptl-email',m);err(m);}});
resend&&resend.addEventListener('click',async()=>{await api(base+'/portal/api/auth/send',{email});resend.disabled=true;setTimeout(()=>resend.disabled=false,30000);});
verify&&verify.addEventListener('submit',async e=>{e.preventDefault();const code=document.getElementById('ptl-code').value.trim();fieldErr('ptl-code','');const r=await api(base+'/portal/api/auth/verify',{email,code});if(r.success){location.href=base+(r.data&&r.data.redirect||'/portal');}else{const m=fieldMsg(r,'code');fieldErr('ptl-code',m);err(m);}});
const ci=document.getElementById('ptl-code');ci&&ci.addEventListener('input',()=>{if(/^\d{6}$/.test(ci.value))verify.requestSubmit();});
})();
