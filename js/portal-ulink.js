(function(){'use strict';
const f=document.getElementById('ptl-ulink-form'),s=document.getElementById('ptl-ulink-status');
f&&f.addEventListener('submit',async e=>{e.preventDefault();const file=document.getElementById('ptl-ulink-file').files[0];if(!file)return;const fd=new FormData();fd.append('file',file);const r=await fetch(location.pathname.replace('/portal/u/','/portal/api/u/')+'/files',{method:'POST',body:fd}).then(x=>x.json()).catch(()=>({success:false}));s.textContent=r.success?'Hochgeladen. Danke!':(r.message||'Fehler beim Hochladen');});
})();
