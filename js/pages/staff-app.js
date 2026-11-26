(function(){'use strict';
/* KanzleiCheck staff SPA inside the synced check-shell chrome. All markup
 * follows the family contracts in css/common/* — structured .kzc-card
 * (__header/__title/__lead/__header-actions/__body), .kzc-table--cards,
 * .kzc-badge__dot, .kzc-empty-state, .kzc-callout, .kzc-quick-filters,
 * .kzc-notif-icon-well. esc() for all user data — no raw innerHTML. */
const el=document.getElementById('kzc-staff-view'),base=location.pathname.split('/apps/kanzleicheck')[0]+'/apps/kanzleicheck';
/* Pathname of the index route (the SPA host) — sidebar ?view= links resolve
 * client-side only while we are on it; elsewhere they navigate normally. */
const INDEX_PATH=(()=>{const a=document.querySelector('#app-navigation .kzc-nav__link[href*="view="]');try{return a?new URL(a.href,location.origin).pathname:location.pathname;}catch(e){return location.pathname;}})();
const t=s=>(window.OC&&OC.L10N&&OC.L10N.translate)?OC.L10N.translate('kanzleicheck',s):s;
const n=(sg,pl,c)=>{const x=Number.isFinite(Number(c))?Number(c):0;return(typeof window.n==='function')?window.n('kanzleicheck',sg,pl,x):String(x===1?sg:pl).replace('%n',String(x));};
const api=async(u,o)=>{const r=await fetch(base+u,Object.assign({headers:{'Content-Type':'application/json'}},o||{}));if(r.status===204)return{success:true};return r.json().catch(()=>({success:false}));};
const get=u=>api(u),post=(u,b)=>api(u,{method:'POST',body:JSON.stringify(b||{})}),put=(u,b)=>api(u,{method:'PUT',body:JSON.stringify(b||{})}),del=u=>api(u,{method:'DELETE'});
const esc=t=>{const d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;};
const h=html=>{const t=document.createElement('template');t.innerHTML=html;return t.content.cloneNode(true);};

/* ── Family component helpers ── */
const ICONS={
 'inbox':'<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
 'users':'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
 'upload':'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
 'file-text':'<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
 'file-check':'<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/>',
 'file-warning':'<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
 'clipboard-list':'<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
 'clipboard-check':'<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
 'alert-triangle':'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
 'check-circle':'<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
 'check':'<path d="M20 6 9 17l-5-5"/>',
 'chevron-right':'<polyline points="9 18 15 12 9 6"/>',
 'arrow-left':'<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
 'search':'<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
 'plus':'<path d="M5 12h14"/><path d="M12 5v14"/>',
 'x':'<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
 'info':'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
 'settings':'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
 'download':'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
 'external-link':'<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
 'list':'<line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/>',
 'activity':'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
 'lock':'<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
 'layout-grid':'<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
 'grip-vertical':'<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
 'eye':'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
 'eye-off':'<path d="M10.733 5.076h.01M14.548 5.076h.01"/><path d="M2 2l20 20"/><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.076A10.73 10.73 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.527 13.527 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>',
};
const icon=n=>'<svg class="kzc-icon" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+(ICONS[n]||ICONS['layout-grid'])+'</svg>';
const well=(v,n)=>'<span class="kzc-notif-icon-well kzc-notif-icon-well--'+(v||'neutral')+'" aria-hidden="true">'+icon(n)+'</span>';
const badge=(v,text)=>'<span class="kzc-badge kzc-badge--'+v+'"><span class="kzc-badge__dot" aria-hidden="true"></span><span class="kzc-badge__label">'+esc(text)+'</span></span>';
/* Structured card → {card, actions, body}. actions is the header-actions
   slot (also .kzc-actions so legacy hooks still find the button row). */
function card(title,lead,opts){
  const c=h('<div class="kzc-card"><div class="kzc-card__header"><div class="kzc-card__header-text"><h2 class="kzc-card__title"></h2>'+(lead?'<p class="kzc-card__lead"></p>':'')+'</div><div class="kzc-card__header-actions kzc-actions"></div></div><div class="kzc-card__body"></div></div>').firstElementChild;
  c.querySelector('.kzc-card__title').textContent=title;
  if(lead)c.querySelector('.kzc-card__lead').textContent=lead;
  return {card:c,actions:c.querySelector('.kzc-card__header-actions'),body:c.querySelector('.kzc-card__body')};
}
function empty(ic,title,text){return h('<div class="kzc-empty-state">'+well('neutral',ic)+'<h3 class="kzc-empty-state__title">'+esc(title)+'</h3><p class="kzc-empty-state__text">'+esc(text)+'</p></div>');}
function emptyErr(title,text){return h('<div class="kzc-empty-state kzc-empty-state--error"><h3 class="kzc-empty-state__title">'+esc(title)+'</h3><p class="kzc-empty-state__text">'+esc(text)+'</p></div>');}
function listHead(title,count){const x=h('<div class="kzc-list-head"><h2 class="kzc-list-head__title"></h2><span class="kzc-list-head__count"></span><span class="kzc-list-head__actions"></span></div>').firstElementChild;x.querySelector('.kzc-list-head__title').textContent=title;x.querySelector('.kzc-list-head__count').textContent=count==null?'':('('+count+')');return x;}
/* Page-header actions via the kernel slot (cleared on every chrome()). */
function pa(defs){if(window.KZC&&KZC.pageActions)KZC.pageActions(defs.map(d=>Object.assign({},d,{iconHtml:d.icon?icon(d.icon):undefined})));}
function btn(label,fn,opts){
  const b=document.createElement('button');b.type='button';
  const v=(opts&&opts.variant)||'';
  b.className='kzc-btn'+(opts&&opts.sm?' kzc-btn--sm':'')+(v==='primary'?' kzc-btn--primary':v==='danger'?' kzc-btn--danger':v==='ghost'?' kzc-btn--ghost':'');
  if(opts&&opts.icon)b.innerHTML=icon(opts.icon);
  b.appendChild(document.createTextNode(label));
  if(fn)b.addEventListener('click',fn);
  return b;
}
/* Back navigation for drill-down views (list → detail). */
function backBtn(label,viewId){return btn(label||t('Back'),()=>{history.pushState({},'',INDEX_PATH+'?view='+viewId);VIEWS[viewId]();},{icon:'arrow-left',variant:'ghost'});}
/* Rich list row: icon well + main(title/sub) + trailing content + chevron. */
function rowEl(o){
  const r=h('<button type="button" class="kzc-row"><span class="kzc-row__icon" aria-hidden="true"></span><span class="kzc-row__main"><b class="kzc-row__title"></b><span class="kzc-muted kzc-row__sub"></span></span><span class="kzc-row__meta"></span></button>').firstElementChild;
  r.querySelector('.kzc-row__icon').innerHTML=well(o.iconVariant,o.icon||'list');
  r.querySelector('.kzc-row__title').textContent=o.title||'';
  r.querySelector('.kzc-row__sub').textContent=o.sub||'';
  const m=r.querySelector('.kzc-row__meta');(o.meta||[]).forEach(x=>m.appendChild(x));
  if(o.chevron!==false)m.appendChild(h('<span class="kzc-row__chevron" aria-hidden="true">'+icon('chevron-right')+'</span>').firstElementChild);
  if(o.onClick)r.addEventListener('click',o.onClick);
  if(o.ariaLabel)r.setAttribute('aria-label',o.ariaLabel);
  return r;
}
/* Flat record rows inside cards (members, upload links, approvals…):
 * label left, grouped .kzc-actions right — never loose children. */
function devRow(label){const r=h('<div class="kzc-dev"><span class="kzc-dev__label"></span><span class="kzc-actions"></span></div>').firstElementChild;r.querySelector('.kzc-dev__label').textContent=label;return r;}
function devAct(row,node){row.querySelector('.kzc-actions').appendChild(node);}
/* Table inside .kzc-table-wrap — td cells carry data-cell for the mobile
   card collapse (.kzc-table--cards, view-components §3.10). */
function tbl(heads,rowsHtml,classes){
  return h('<div class="kzc-table-wrap"><table class="kzc-table kzc-table--cards '+(classes||'')+'"><thead><tr>'+heads.map(x=>'<th scope="col">'+esc(x)+'</th>').join('')+'</tr></thead><tbody>'+rowsHtml+'</tbody></table></div>').firstElementChild;
}
/* .kzc-field contract: label → control → hint, flex column. */
function field(id,label,type,val,hint){
  return '<div class="kzc-field"><label class="kzc-label" for="'+id+'">'+esc(label)+'</label><input id="'+id+'" class="kzc-input" type="'+esc(type||'text')+'" value="'+esc(val==null?'':val)+'">'+(hint?'<p class="kzc-field__hint kzc-muted">'+esc(hint)+'</p>':'')+'</div>';
}
function checkField(id,label){return '<label class="kzc-check"><input type="checkbox" id="'+id+'"> '+esc(label)+'</label>';}
function pickerField(id,label,ph){return '<div class="kzc-field"><label class="kzc-label" for="'+id+'">'+esc(label)+'</label><div class="kzc-picker"><input id="'+id+'" class="kzc-input" type="text" autocomplete="off" role="combobox" aria-expanded="false" aria-haspopup="listbox" aria-autocomplete="list" aria-controls="'+id+'-results" placeholder="'+esc(ph)+'"><ul id="'+id+'-results" class="kzc-picker__results" role="listbox" hidden></ul><div class="kzc-picker__chips" role="list"></div></div></div>';}
const status=t=>{const s=document.getElementById('kzc-live');if(s)s.textContent=t||'';document.getElementById('kzc-loading')?.remove();};
const ok=m=>{if(window.KZCToasts)KZCToasts.success(m);else status(m);};
const warnT=m=>{if(window.KZCToasts)KZCToasts.warn(m);else status(m);};
const err=m=>{if(window.KZCToasts)KZCToasts.error(m);else status(m);};
/* Directory search feed for every people/group picker — directory pick only,
   never raw uid/gid typing (access-door + staff-picker mandate). */
const searchDir=async(path,term,signal)=>{
  const r=await fetch(base+path+'?q='+encodeURIComponent(term),{headers:{Accept:'application/json'},signal}).then(x=>x.json()).catch(e=>{if(e&&e.name==='AbortError')throw e;return{};});
  return ((r&&r.data&&(r.data.users||r.data.groups))||[]).map(u=>({id:u.uid||u.gid,label:(u.displayName&&u.displayName!==u.uid)?u.displayName+' ('+(u.uid||u.gid)+')':(u.uid||u.gid)}));
};
const dirPicker=(root,initial,path,onChange)=>KZC.initPicker({input:root.querySelector('input'),results:root.querySelector('.kzc-picker__results'),chips:root.querySelector('.kzc-picker__chips'),selected:(initial||[]).map(v=>({id:v,label:v})),search:(term,signal)=>searchDir(path,term,signal),onChange});
let ME_ADMIN=false;
/* per-loader generation counters — a superseded list fetch must never paint
   over a newer result (stale_response_render_clobber). Module scope so view
   remounts also invalidate in-flight fetches from the previous mount.
   VIEW_SEQ covers whole-view entries: every view function bumps it on entry,
   so any in-flight continuation of a previous view (await→chrome()/paint)
   detects its own staleness and returns before touching the document. */
let ORG_SEQ=0,PQ_SEQ=0,VIEW_SEQ=0;

/* native dialog helpers (never window.prompt/confirm) — canonical
   .kzc-dialog anatomy: __head (__title + __close) → __body → __actions. */
function dlgShell(title,bodyHtml,okLabel){
  const dlg=document.createElement('dialog');dlg.className='kzc-dialog';
  dlg.innerHTML='<form method="dialog" class="kzc-dialog__form"><div class="kzc-dialog__head"><h2 class="kzc-dialog__title"></h2><button type="button" class="kzc-dialog__close" value="" aria-label="'+esc(t('Close'))+'">'+icon('x')+'</button></div><div class="kzc-dialog__body">'+bodyHtml+'</div><div class="kzc-dialog__actions kzc-actions"><button value="" class="kzc-btn">'+esc(t('Cancel'))+'</button><button value="ok" class="kzc-btn kzc-btn--primary"></button></div></form>';
  dlg.querySelector('.kzc-dialog__title').textContent=title;
  dlg.querySelector('.kzc-btn--primary').textContent=okLabel;
  dlg.querySelector('.kzc-dialog__close').addEventListener('click',()=>dlg.close(''));
  dlg.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dlg.close('');}});
  return dlg;
}
function ask(title,label,okLabel,fields){return new Promise(res=>{
  let inner='';
  (fields||[{name:'v',label}]).forEach(f=>{
    inner+='<div class="kzc-field"><label class="kzc-label" for="fd-'+esc(f.name)+'"></label>';
    inner+=f.type==='select'?'<select id="fd-'+esc(f.name)+'" class="kzc-input" name="'+esc(f.name)+'">'+(f.options||[]).map(o=>'<option value="'+esc(o[0])+'"'+(f.value===o[0]?' selected':'')+'>'+esc(o[1])+'</option>').join('')+'</select>'
      :f.type==='textarea'?'<textarea id="fd-'+esc(f.name)+'" class="kzc-input" name="'+esc(f.name)+'" rows="3">'+(f.value?esc(f.value):'')+'</textarea>'
      :'<input id="fd-'+esc(f.name)+'" class="kzc-input" name="'+esc(f.name)+'" type="'+esc(f.type||'text')+'" value="'+esc(f.value==null?'':f.value)+'" '+(f.required?'required':'')+'>';
    inner+='</div>';
  });
  const dlg=dlgShell(title,inner,okLabel);
  dlg.querySelectorAll('.kzc-field > .kzc-label').forEach((l,i)=>{const f=(fields||[{label}])[i];l.textContent=f.label;});
  dlg.addEventListener('close',()=>{
    if(dlg.returnValue!=='ok'){res(null);dlg.remove();return;}
    const out={};dlg.querySelectorAll('[name]').forEach(i=>out[i.name]=i.value);res(out);dlg.remove();
  });
  document.body.appendChild(dlg);dlg.showModal();
});}
function confirmDlg(title,text){return new Promise(res=>{
  const dlg=dlgShell(title,'<p class="kzc-muted"></p>',t('Confirm'));
  dlg.querySelector('.kzc-muted').textContent=text;
  dlg.addEventListener('close',()=>{res(dlg.returnValue==='ok');dlg.remove();});
  document.body.appendChild(dlg);dlg.showModal();});}

/* Check-shell chrome (synced templates/common/*) renders the sidebar nav,
 * page header and live regions; the SPA only owns the view body inside
 * #kzc-staff-view. chrome() retitles the shell header + marks the active
 * nav item, then hands back a fresh body container. */
const TITLES={overview:()=>t('Overview'),inbox:()=>t('Messages'),board:()=>t('Tasks'),requests:()=>t('Requests'),orgs:()=>t('Clients'),uploads:()=>t('Uploads'),holds:()=>t('Mail review'),forms:()=>t('Questionnaires'),people:()=>t('People'),settings:()=>t('Settings')};
function markNav(active){
  document.querySelectorAll('#app-navigation .kzc-nav__link').forEach(a=>{
    let on=false;
    try{
      const u=new URL(a.href,location.origin);
      const v=u.searchParams.get('view');
      on=v!==null?u.pathname===INDEX_PATH&&v===active:u.pathname===location.pathname&&active==='settings';
    }catch(e){}
    a.classList.toggle('is-active',on);
    const li=a.closest('.kzc-nav__item');if(li)li.classList.toggle('is-active',on);
    if(on)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');
  });
}
function chrome(active,title){
  const pt=document.getElementById('kzc-page-title');if(pt)pt.textContent=title;
  const bc=document.querySelector('.kzc-breadcrumb__current');if(bc)bc.textContent=title;
  document.title=title+' — KanzleiCheck';
  markNav(active);
  pa([]); // clear page-header actions — each view mounts its own
  el.innerHTML='<div id="kzc-live" role="status" aria-live="polite" class="kzc-status"></div><div id="kzc-body"><p class="kzc-muted" id="kzc-loading" role="status"></p></div>';
  const ld=document.getElementById('kzc-loading');ld.textContent=t('Loading…');
  const body=document.getElementById('kzc-body');
  // instant feedback on every view switch: clear the loading placeholder on
  // the first real insert (covers success, error and early-return paths).
  const orig=body.appendChild.bind(body);
  let cleared=false;
  body.appendChild=n=>{if(!cleared){cleared=true;ld.remove();}return orig(n);};
  return body;
}

/* ── Overview ── */
async function vOverview(){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/overview');
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  const body=chrome('overview',t('What needs attention?'));
  if(!r.success){body.appendChild(emptyErr(t('Could not load the overview.'),r.message||t('Please try again.')));return;}
  const su=await get('/api/portal/setup');
  if(seq!==VIEW_SEQ)return;
  if(su.success&&su.data&&su.data.setup&&!su.data.setup.done){
    const coach=h('<details class="kzc-card kzc-coach" open><summary class="kzc-coach__summary"><span class="kzc-card__title"></span></summary><div class="kzc-card__body"><ul class="kzc-coach__list"></ul></div></details>').firstElementChild;
    coach.querySelector('.kzc-card__title').textContent=t('Initial setup');
    const ul=coach.querySelector('ul');
    if(!su.data.setup.preset)ul.appendChild(h('<li>'+esc(t('Choose an industry preset in Settings.'))+'</li>'));
    if(!su.data.setup.docsRoot)ul.appendChild(h('<li>'+esc(t('Set the documents root folder in Settings (create a folder in Files first).'))+'</li>'));
    if(!su.data.setup.orgs)ul.appendChild(h('<li>'+esc(t('Create your first client under Clients, or import a CSV.'))+'</li>'));
    body.appendChild(coach);
  }
  const s=r.data.stats;
  body.appendChild(h('<div class="kzc-stat-strip">'+[
    [s.unread,t('Unread messages'),'inbox','kzc-stat-tile--primary',t('open inbox')],
    [s.newUploads,t('New uploads'),'uploads','kzc-stat-tile--success',t('review queue')],
    [s.overdueRequests,t('Overdue requests'),'requests','kzc-stat-tile--danger',t('needs follow-up')],
    [s.openApprovals,t('Open approvals'),'requests','kzc-stat-tile--warning',t('waiting on client')],
  ].map(([nv,l,v,c,meta])=>'<button class="kzc-stat-tile '+c+'" data-v="'+v+'"><span class="kzc-stat-tile__label">'+esc(l)+'</span><span class="kzc-stat-tile__value">'+nv+'</span><span class="kzc-stat-tile__meta">'+esc(meta)+'</span></button>').join('')+'</div>'));
  body.querySelectorAll('.kzc-stat-tile').forEach(b=>b.onclick=()=>{const v=b.dataset.v;history.pushState({},'',INDEX_PATH+'?view='+v);VIEWS[v]();});
  const q=r.data.queue||[];
  const c=card(t('Recently active'),t('Latest client conversations — unread first.'));
  if(q.length){
    const list=h('<div class="kzc-list"></div>').firstElementChild;
    q.slice(0,8).forEach(cv=>{
      const row=rowEl({icon:'inbox',title:cv.orgName||t('Client'),sub:cv.lastPreview||'',ariaLabel:(cv.orgName||t('Client'))+(cv.unread?', '+t('Unread messages')+': '+cv.unread:''),meta:cv.unread?[h(badge('info',String(cv.unread)))] : [],onClick:()=>openConv(cv.orgId,cv.orgName)});
      row.classList.add(cv.unread?'kzc-row--unread':'kzc-row--read');
      list.appendChild(row);
    });
    c.body.appendChild(list);
  }else c.body.appendChild(empty('check-circle',t('All done — no open items.'),t('New client messages, uploads and approvals will show up here.')));
  body.appendChild(c.card);
}

/* ── Messages (inbox + thread) ── */
async function vInbox(mine){
  VIEW_SEQ++;
  const body=chrome('inbox',t('Messages'));
  const c=card(t('Conversations'),t('Client chats — replies go out as portal messages.'));
  const flt=h('<div class="kzc-quick-filters"><span class="kzc-quick-filters__label">'+esc(t('Filter'))+'</span><label class="kzc-check"><input type="checkbox" id="kzc-mine"> '+esc(t('Only my clients'))+'</label></div>').firstElementChild;
  flt.querySelector('#kzc-mine').checked=!!mine;
  flt.querySelector('#kzc-mine').onchange=e=>vInbox(e.target.checked);
  c.body.appendChild(flt);
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  c.body.appendChild(list);
  body.appendChild(c.card);
  const r=await get('/api/portal/inbox'+(mine?'?mine=1':''));
  if(!r.success){list.appendChild(emptyErr(t('Could not load conversations.'),r.message||t('Please try again.')));return;}
  const convs=r.data.conversations||[];
  if(!convs.length){list.appendChild(empty('inbox',t('No conversations.'),t('Client messages land here once a portal user writes to you.')));return;}
  convs.forEach(cv=>{
    const row=rowEl({icon:'inbox',title:cv.orgName,sub:(cv.lastAuthorName?cv.lastAuthorName+': ':'')+(cv.lastPreview||'')+(cv.state==='archived'?' '+t('(archived)'):''),ariaLabel:cv.orgName+(cv.unread?', '+t('Unread messages')+': '+cv.unread:''),meta:cv.unread?[h(badge('info',String(cv.unread)))]:[],onClick:()=>openConv(cv.orgId,cv.orgName)});
    row.classList.add(cv.unread?'kzc-row--unread':'kzc-row--read');
    list.appendChild(row);
  });
}
/* Exactly ONE self-rescheduling chain per conversation generation:
   - CONV_GEN invalidates orphan chains on re-open/view-switch (a stale
     chain must die, not keep polling into a detached or recycled box).
   - CONV_BUSY holds the generation currently in flight: a caller on the
     SAME generation coalesces via WANT (a send marks WANT and the
     finishing pull runs ONE fresh pull — never a second parallel chain,
     poller_chain_fork_duplicate_render); a NEWER generation always fetches
     immediately — the superseded response is dropped on arrival anyway.
   - the painted-org check (o!==CONV_ORG) drops cross-org responses before
     they can paint or corrupt CONV_LAST (stale_response_render_clobber). */
let CONV_ORG=null,CONV_LAST=0,CONV_TIMER=null,CONV_BUSY=0,CONV_WANT=false,CONV_GEN=0,CONV_MARKED=0;
async function openConv(orgId,name){
  VIEW_SEQ++;
  if(CONV_TIMER){clearTimeout(CONV_TIMER);CONV_TIMER=null;}
  CONV_ORG=orgId;CONV_LAST=0;CONV_MARKED=0;
  const gen=++CONV_GEN;
  const body=chrome('inbox',t('Chat')+' — '+name);
  const c=card(name,t('Client conversation — new replies poll every 15 seconds.'));
  c.actions.appendChild(backBtn(t('All messages'),'inbox'));
  c.body.appendChild(h('<div id="kzc-msgs" class="kzc-msgs"></div>'));
  c.body.appendChild(h('<form id="kzc-cf" class="kzc-composer"><label class="kzc-sr-only" for="kzc-cm">'+esc(t('Write a reply…'))+'</label><textarea id="kzc-cm" class="kzc-textarea" rows="3" maxlength="4000" required autocomplete="off" placeholder="'+esc(t('Write a reply…'))+'"></textarea><button class="kzc-btn kzc-btn--primary">'+esc(t('Send'))+'</button></form>'));
  body.appendChild(c.card);
  /* textarea: Enter sends (input parity), Shift+Enter = newline, IME
     composition Enter must not send (isComposing — CJK input). */
  c.body.querySelector('#kzc-cm').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();e.target.form.requestSubmit();}});
  c.body.querySelector('#kzc-cf').addEventListener('submit',async e=>{
    e.preventDefault();const inp=document.getElementById('kzc-cm'),v=inp.value.trim();if(!v)return;
    const r=await post('/api/portal/orgs/'+orgId+'/messages',{body:v,clientUuid:crypto.randomUUID()});
    // send triggers the pending poll EARLY on the same chain — clear the
    // armed timer first, never invoke a second self-rescheduling chain.
    if(r.success){inp.value='';if(CONV_TIMER){clearTimeout(CONV_TIMER);CONV_TIMER=null;}pullConv(gen);}else err(r.message||t('Error'));
  });
  pullConv(gen);
}
async function pullConv(gen){
  const o=CONV_ORG;
  if(o===null||gen!==CONV_GEN)return;
  if(CONV_BUSY===gen){CONV_WANT=true;return;} // same chain already in flight — coalesce
  CONV_BUSY=gen;
  const r=await get('/api/portal/orgs/'+o+'/messages?after='+CONV_LAST).catch(()=>null);
  if(CONV_BUSY===gen)CONV_BUSY=0;
  if(CONV_WANT){CONV_WANT=false;pullConv(CONV_GEN);return;} // coalesced — one fresh pull under current gen/org
  const box=document.getElementById('kzc-msgs');
  if(!box||o!==CONV_ORG||gen!==CONV_GEN)return; // view/org switched mid-flight — drop, stop chain
  if(r&&r.success&&r.data){
    (r.data.messages||[]).forEach(m=>{
      if(m.id<=CONV_LAST)return; // already painted — belt dedupe vs overlapping fetches
      CONV_LAST=m.id;
      const mine=m.kind==='staff';
      const row=h('<div class="kzc-msg'+(mine?' kzc-msg--mine':'')+'"><div class="kzc-msg__body"></div><div class="kzc-msg__meta"></div></div>');
      row.querySelector('.kzc-msg__body').textContent=m.body;
      row.querySelector('.kzc-msg__meta').textContent=(m.authorName||m.author)+' · '+new Date(m.createdAt).toLocaleString();
      box.appendChild(row);
    });
    if(r.data.messages.length)box.scrollTop=box.scrollHeight;
    if(r.data.state==='archived'){document.getElementById('kzc-cf')?.remove();status(t('Conversation archived (read only).'));}
  }
  /* Unread badge: advance the server read marker to the highest PAINTED id
     (CONV_LAST only grows inside the paint loop). CONV_MARKED retries any
     failed POST on the next cycle instead of leaving the badge stale until
     another client message arrives. */
  if(CONV_LAST>CONV_MARKED){
    const mark=CONV_LAST;
    post('/api/portal/orgs/'+o+'/read',{lastReadId:mark}).then(rr=>{if(rr&&rr.success&&o===CONV_ORG&&mark>CONV_MARKED)CONV_MARKED=mark;}).catch(()=>{});
  }
  CONV_TIMER=setTimeout(()=>{CONV_TIMER=null;pullConv(gen);},15000);
}

/* ── Task board ── */
let BOARD_MOVE_IN_FLIGHT=false; // one CAS move at a time — drop/select during a write is ignored
let BOARD_DRAG=null;            // card element mid-drag (may outlive its column if the board re-renders)
async function vBoard(){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/board');
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  const body=chrome('board',t('Tasks'));
  pa([{label:t('New task'),icon:'plus',primary:true,onClick:async()=>{
    const orgs=(await get('/api/portal/orgs')).data.orgs;
    const f=await ask(t('New task'),null,t('Create'),[
      {name:'orgId',label:t('Client'),type:'select',options:orgs.map(o=>[o.id,o.name])},
      {name:'title',label:t('Title'),required:true},
      {name:'note',label:t('Note'),type:'textarea'},
      {name:'priority',label:t('Priority'),type:'select',options:[['low',t('Low')],['normal',t('Normal')],['high',t('High')],['urgent',t('Urgent')]]},
      {name:'dueDate',label:t('Due'),type:'date'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/tasks',{orgId:+f.orgId,title:f.title,note:f.note||null,priority:f.priority,dueDate:f.dueDate||null});
    rr.success?ok(t('Created.')):err(rr.message||t('Error'));if(rr.success)vBoard();
  }}]);
  if(!r.success){body.appendChild(emptyErr(t('Could not load the board.'),r.message||t('Please try again.')));return;}
  const cols=r.data.columns||{};
  const wrap=h('<div class="kzc-board"></div>').firstElementChild;
  Object.entries(cols).forEach(([key,col])=>{
    const c=h('<div class="kzc-col"><div class="kzc-col__head"><h2></h2><span class="kzc-col__count"></span></div></div>').firstElementChild;
    c.dataset.status=key;
    c.querySelector('h2').textContent=col.label;
    c.querySelector('.kzc-col__count').appendChild(h(badge('neutral',String(col.cards.length))));
    col.cards.forEach(cd=>c.appendChild(taskCard(cd,key,cols)));
    if(!col.cards.length)c.appendChild(h('<p class="kzc-muted kzc-col__empty">'+esc(t('Nothing here.'))+'</p>'));
    wrap.appendChild(c);
  });
  wireBoardDnd(wrap);
  body.appendChild(wrap);
}
function taskCard(it,status,cols){
  const c=h('<div class="kzc-card2" data-task-id="" data-status="" data-revision=""><div class="kzc-card2__head"><span class="kzc-card2__statuswrap"></span><span class="kzc-card2__tools"><button type="button" class="kzc-card2__vis" data-kzc-visibility-toggle></button><button type="button" class="kzc-card2__drag" draggable="true"></button></span></div><button type="button" class="kzc-card2__title"></button><div class="kzc-muted kzc-card2__org"></div><div class="kzc-cardmeta"></div></div>').firstElementChild;
  c.dataset.taskId=it.id;c.dataset.status=status;c.dataset.revision=it.revision;
  const titleBtn=c.querySelector('.kzc-card2__title');
  titleBtn.textContent=it.title;
  titleBtn.title=t('Open details');
  titleBtn.addEventListener('click',()=>openTaskDetail(it.id));
  c.querySelector('.kzc-card2__org').textContent=it.orgName;
  const handle=c.querySelector('.kzc-card2__drag');
  handle.innerHTML=icon('grip-vertical');
  handle.setAttribute('aria-label',t('Move')+': '+it.title);
  handle.title=t('Drag to another column or use the status selector.');
  const vis=c.querySelector('.kzc-card2__vis');
  const visOn=it.visibility==='status';
  vis.innerHTML=icon(visOn?'eye':'eye-off');
  const visLabel=visOn?t('Visible to clients'):t('Hidden from clients');
  vis.setAttribute('aria-label',visLabel+': '+it.title);
  vis.setAttribute('aria-pressed',visOn?'true':'false');
  vis.title=visLabel;
  if(!visOn)vis.classList.add('kzc-card2__vis--off');
  vis.addEventListener('click',async()=>{
    vis.disabled=true;
    const r=await put('/api/portal/tasks/'+it.id,{portalVisibility:visOn?'none':'status',revision:+c.dataset.revision||0});
    if(r.success)vBoard();else{vis.disabled=false;if(r.error==='CONFLICT')vBoard();else err(r.message||t('Error'));}
  });
  const meta=c.querySelector('.kzc-cardmeta');
  const pv={low:'neutral',normal:'info',high:'warning',urgent:'danger'}[it.priority]||'neutral';
  meta.appendChild(h(badge(pv,it.priorityLabel||it.priority)));
  if(it.dueDate)meta.appendChild(h(badge(it.overdue?'danger':'neutral',it.dueDate+(it.overdue?' '+t('(overdue)'):''))));
  const sel=h('<select class="kzc-input kzc-card2__status" data-kzc-status-select>'+Object.entries(cols).map(([k,cl])=>'<option value="'+esc(k)+'"'+(k===status?' selected':'')+'>'+esc(cl.label)+'</option>').join('')+'</select>').firstElementChild;
  sel.setAttribute('aria-label',t('Status')+': '+it.title);
  sel.addEventListener('change',()=>boardMove(c,sel.value,sel));
  handle.addEventListener('click',()=>sel.focus()); // activating the grip without dragging hands keyboard users the select
  c.querySelector('.kzc-card2__statuswrap').appendChild(sel);
  return c;
}
/* Card move — the single write path shared by drag-drop and the status
 * select. Server stays the source of truth: the card only moves after the
 * CAS write succeeds; the board then re-renders so counts and empty-column
 * labels stay honest. CONFLICT (a colleague touched the task) reloads
 * instead of silently diverging — same semantics the Move… dialog had. */
async function boardMove(card,newStatus,focusEl){
  const taskId=+card.dataset.taskId||0,prev=card.dataset.status||'';
  const sel=card.querySelector('[data-kzc-status-select]');
  if(!taskId||!newStatus||newStatus===prev||BOARD_MOVE_IN_FLIGHT){if(sel)sel.value=prev;return;}
  BOARD_MOVE_IN_FLIGHT=true;
  card.classList.add('kzc-card2--saving');card.setAttribute('aria-busy','true');
  if(sel)sel.disabled=true;
  const r=await put('/api/portal/tasks/'+taskId+'/move',{status:newStatus,revision:+card.dataset.revision||0});
  if(r.success){ok(t('Saved.'));await vBoard();}
  else if(r.error==='CONFLICT'){warnT(t('Changed in the meantime — reloaded.'));await vBoard();}
  else{err(r.message||t('Error'));if(sel){sel.value=prev;sel.disabled=false;}card.classList.remove('kzc-card2--saving');card.removeAttribute('aria-busy');if(focusEl)focusEl.focus();}
  BOARD_MOVE_IN_FLIGHT=false;
  if(r.success||r.error==='CONFLICT')document.querySelector('[data-task-id="'+taskId+'"] [data-kzc-status-select]')?.focus();
}
/* Task detail dialog — the board payload stays lean (no note/assignee), so
 * the dialog fetches the full row on open; that fetch also hands back a
 * fresh CAS revision. CONFLICT on save refetches + repopulates instead of
 * clobbering the colleague's write; NOT_FOUND means the task is gone →
 * close + reload. Field errors render via the shared pack (name= hooks). */
async function openTaskDetail(taskId){
  const r=await get('/api/portal/tasks/'+taskId);
  if(!r.success||!r.data||!r.data.task){
    if(r.error==='NOT_FOUND'){err(t('This task no longer exists.'));vBoard();return;}
    err(r.message||t('Error'));return;
  }
  const body='<p class="kzc-muted" data-kzc-td-meta></p>'
    +'<div class="kzc-field"><label class="kzc-label" for="td-title">'+esc(t('Title'))+'</label><input id="td-title" name="title" class="kzc-input" type="text" required maxlength="190"></div>'
    +'<div class="kzc-field"><label class="kzc-label" for="td-note">'+esc(t('Note'))+'</label><textarea id="td-note" name="note" class="kzc-input" rows="4" maxlength="5000"></textarea></div>'
    +'<div class="kzc-field"><label class="kzc-label" for="td-priority">'+esc(t('Priority'))+'</label><select id="td-priority" name="priority" class="kzc-input">'+[['low',t('Low')],['normal',t('Normal')],['high',t('High')],['urgent',t('Urgent')]].map(o=>'<option value="'+o[0]+'">'+esc(o[1])+'</option>').join('')+'</select></div>'
    +'<div class="kzc-field"><label class="kzc-label" for="td-due">'+esc(t('Due'))+'</label><input id="td-due" name="dueDate" class="kzc-input" type="date"></div>'
    +'<div class="kzc-field"><label class="kzc-label" for="td-assignee">'+esc(t('Assignee'))+'</label><input id="td-assignee" name="assignee" class="kzc-input" type="text" maxlength="64"></div>'
    +'<p class="kzc-muted" data-kzc-td-audit></p>';
  const dlg=dlgShell(t('Task'),body,t('Save'));
  let rev=0;
  const fmt=iso=>iso?new Date(iso).toLocaleString():'—';
  function fill(d){
    dlg.querySelector('#td-title').value=d.title||'';
    dlg.querySelector('#td-note').value=d.note||'';
    dlg.querySelector('#td-priority').value=d.priority||'normal';
    dlg.querySelector('#td-due').value=d.dueDate||'';
    dlg.querySelector('#td-assignee').value=d.assignee||'';
    dlg.querySelector('[data-kzc-td-meta]').textContent=(d.orgName?d.orgName+' — ':'')+(d.statusLabel||d.status)+(d.overdue?' '+t('(overdue)'):'');
    const bits=[t('Created')+': '+fmt(d.createdAt)+(d.createdBy?' ('+d.createdBy+')':''),t('Updated')+': '+fmt(d.updatedAt)];
    if(d.doneAt)bits.push(t('Done')+': '+fmt(d.doneAt));
    bits.push(d.visibility==='status'?t('Visible to clients'):t('Hidden from clients'));
    dlg.querySelector('[data-kzc-td-audit]').textContent=bits.join(' · ');
    rev=+d.revision||0;
  }
  fill(r.data.task);
  const okBtn=dlg.querySelector('.kzc-btn--primary');
  okBtn.addEventListener('click',async e=>{
    e.preventDefault(); // method="dialog" would close before the CAS write lands
    const title=dlg.querySelector('#td-title').value.trim();
    if(!title){window.CheckFieldErrors&&CheckFieldErrors.markValidationFields({title:t('Required')});return;}
    okBtn.disabled=true;
    const rr=await put('/api/portal/tasks/'+taskId,{
      title:title,
      note:dlg.querySelector('#td-note').value||null,
      priority:dlg.querySelector('#td-priority').value,
      dueDate:dlg.querySelector('#td-due').value||null,
      assignee:dlg.querySelector('#td-assignee').value.trim(), // '' clears — isset() skips null server-side
      revision:rev,
    });
    if(rr.success){ok(t('Saved.'));dlg.close('saved');vBoard();return;}
    if(rr.error==='CONFLICT'){
      warnT(t('Changed in the meantime — reloaded.'));
      const fresh=await get('/api/portal/tasks/'+taskId);
      if(fresh.success&&fresh.data&&fresh.data.task){fill(fresh.data.task);okBtn.disabled=false;return;} // stays open on fresh data
      dlg.close('');vBoard();return; // task vanished mid-edit
    }
    if(rr.fields&&window.CheckFieldErrors)CheckFieldErrors.markValidationFields(rr.fields);
    err(rr.message||t('Error'));okBtn.disabled=false;
  });
  // Enter inside a field implicitly submits method="dialog" and would close
  // the dialog without saving — reroute to the same CAS write as Save.
  dlg.querySelector('form').addEventListener('submit',e=>{e.preventDefault();okBtn.click();});
  dlg.addEventListener('close',()=>dlg.remove());
  document.body.appendChild(dlg);dlg.showModal();
}
/* Drag-and-drop wiring — the grip handle is the only drag source, so card
 * text stays selectable and the other controls clickable. Keyboard/AT
 * equivalent: the per-card status select (no pointer-only actions). */
function wireBoardDnd(wrap){
  const cols=[...wrap.querySelectorAll('.kzc-col')];
  const clearInd=()=>cols.forEach(c=>c.classList.remove('kzc-col--dragover'));
  const reset=()=>{if(BOARD_DRAG)BOARD_DRAG.classList.remove('kzc-card2--dragging');wrap.classList.remove('kzc-board--dragging');clearInd();BOARD_DRAG=null;};
  wrap.addEventListener('dragstart',e=>{
    const handle=e.target&&e.target.closest?e.target.closest('.kzc-card2__drag'):null;
    const card=handle?handle.closest('.kzc-card2[data-task-id]'):null;
    if(!handle||!card||BOARD_MOVE_IN_FLIGHT){e.preventDefault();return;}
    BOARD_DRAG=card;card.classList.add('kzc-card2--dragging');wrap.classList.add('kzc-board--dragging');
    if(e.dataTransfer){e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.taskId||'');} // Firefox requires setData to start a drag
  });
  wrap.addEventListener('dragend',reset);
  cols.forEach(col=>{
    col.addEventListener('dragover',e=>{
      if(!BOARD_DRAG||BOARD_MOVE_IN_FLIGHT)return;
      e.preventDefault();
      if(e.dataTransfer)e.dataTransfer.dropEffect='move';
      clearInd();
      if(BOARD_DRAG.closest('.kzc-col')!==col)col.classList.add('kzc-col--dragover');
    });
    col.addEventListener('dragleave',e=>{if(!col.contains(e.relatedTarget))col.classList.remove('kzc-col--dragover');});
    col.addEventListener('drop',e=>{
      e.preventDefault();clearInd();
      const card=BOARD_DRAG;reset();
      if(card&&!BOARD_MOVE_IN_FLIGHT)boardMove(card,col.dataset.status||'',null);
    });
  });
}

/* ── Requests ── */
async function vRequests(mine){
  VIEW_SEQ++;
  const body=chrome('requests',t('Document requests'));
  pa([{label:t('New request'),icon:'plus',primary:true,onClick:async()=>{
    const orgs=(await get('/api/portal/orgs')).data.orgs;
    const f=await ask(t('New request'),null,t('Create'),[
      {name:'orgId',label:t('Client'),type:'select',options:orgs.map(o=>[o.id,o.name])},
      {name:'title',label:t('Title'),required:true},
      {name:'items',label:t('Required documents (one per line)'),type:'textarea'},
      {name:'deadline',label:t('Deadline'),type:'date'},
    ]);
    if(!f)return;
    const items=f.items.split('\n').map(x=>x.trim()).filter(Boolean).map(l=>({label:l,kind:'upload'}));
    if(!items.length){warnT(t('At least one item is required.'));return;}
    const r=await post('/api/portal/requests',{orgId:+f.orgId,title:f.title,deadline:f.deadline||null,items});
    r.success?ok(t('Created.')):err(r.message||t('Error'));if(r.success)vRequests();
  }}]);
  const c=card(t('Requests'),t('Documents the clients owe you — tracked per item.'));
  const flt=h('<div class="kzc-quick-filters"><span class="kzc-quick-filters__label">'+esc(t('Filter'))+'</span><label class="kzc-check"><input type="checkbox" id="kzc-mine"> '+esc(t('Only my clients'))+'</label></div>').firstElementChild;
  flt.querySelector('#kzc-mine').checked=!!mine;
  flt.querySelector('#kzc-mine').onchange=e=>vRequests(e.target.checked);
  c.body.appendChild(flt);
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  c.body.appendChild(list);
  body.appendChild(c.card);
  const r=await get('/api/portal/requests'+(mine?'?mine=1':''));
  const reqs=(r.data&&r.data.requests)||[];
  if(!reqs.length){list.appendChild(empty('file-check',t('No requests.'),t('Create a request to tell a client which documents you still need.')));}
  reqs.forEach(rq=>{
    const sv={open:'info',closed:'success',cancelled:'neutral'}[rq.state]||'neutral';
    const cardEl=h('<div class="kzc-card kzc-req"><div class="kzc-card__header"><div class="kzc-card__header-text"><div class="kzc-req__titleline"><b class="kzc-card__title"></b>'+badge(sv,rq.state)+'</div><p class="kzc-card__lead"></p></div></div><div class="kzc-card__body"></div></div>').firstElementChild;
    cardEl.querySelector('.kzc-card__title').textContent=rq.title;
    cardEl.querySelector('.kzc-card__lead').innerHTML='';
    const lead=cardEl.querySelector('.kzc-card__lead');
    lead.appendChild(h((rq.deadline?badge(rq.overdue?'danger':'neutral',(rq.overdue?t('OVERDUE')+' — ':'')+t('Deadline')+' '+rq.deadline):badge('neutral',t('no deadline')))+' '+badge('info',(rq.items?.accepted??0)+'/'+(rq.items?.total??0)+' '+t('accepted'))));
    const items=cardEl.querySelector('.kzc-card__body');
    const detail=h('<details class="kzc-items-detail"><summary class="kzc-btn kzc-btn--sm">'+esc(t('Details'))+'</summary></details>').firstElementChild;
    items.appendChild(detail);
    detail.addEventListener('toggle',async ev=>{
      if(!ev.target.open||ev.target.dataset.loaded)return;
      ev.target.dataset.loaded='1';
      const itemsList=h('<div class="kzc-itemlist"></div>').firstElementChild;
      detail.appendChild(itemsList);
      const dr=await get('/api/portal/requests/'+rq.id);
      if(!dr.success){itemsList.appendChild(h('<p class="kzc-muted">'+esc(t('Error'))+'</p>'));return;}
      const d=dr.data.request;
      if(d.state==='open'){
        const closeB=btn(t('Close request'),async()=>{
          if(!(await confirmDlg(t('Close request'),t('Marks the request as done even if items are missing. Continue?'))))return;
          const cr=await post('/api/portal/requests/'+d.id+'/close');if(cr.status===204||cr.success){vRequests(mine);}
        },{sm:true});itemsList.appendChild(closeB);
      }
      (d.items||[]).forEach(it=>{
        const isv={delivered:'info',accepted:'success',rejected:'danger',open:'neutral'}[it.state]||'neutral';
        const ir=h('<div class="kzc-itemrow"><div class="kzc-itemrow__main"><b></b><div class="kzc-muted"></div><div class="kzc-itemfiles"></div></div><div class="kzc-itemact"></div></div>');
        ir.querySelector('b').textContent=it.label;
        const stateEl=h(badge(isv,it.state));
        ir.querySelector('.kzc-muted').textContent=(it.hint||'')+(it.rejectReason?' — '+t('Rejected')+': '+it.rejectReason:'');
        ir.querySelector('.kzc-muted').prepend(stateEl);
        (it.files||[]).forEach(fid=>{
          const a=h('<a class="kzc-file"></a>').firstElementChild;
          a.textContent='📎 '+t('file')+' #'+fid;a.href=base+'/api/portal/uploads/'+fid+'/file';
          ir.querySelector('.kzc-itemfiles').appendChild(a);
        });
        const act=ir.querySelector('.kzc-itemact');
        if(it.state==='delivered'){
          act.appendChild(btn(t('Accept'),async()=>{await put('/api/portal/requests/'+d.id+'/items/'+it.id+'/review',{accept:true});vRequests(mine);},{variant:'primary',sm:true}));
          act.appendChild(btn(t('Reject'),async()=>{
            const f=await ask(t('Reject item'),t('Reason (shown to the client):'),t('Reject'));
            if(f===null)return;
            await put('/api/portal/requests/'+d.id+'/items/'+it.id+'/review',{accept:false,reason:f.v});
            vRequests(mine);
          },{sm:true}));
        }
        itemsList.appendChild(ir);
      });
    });
    list.appendChild(cardEl);
  });
}

/* ── Clients ── */
async function vOrgs(){
  VIEW_SEQ++;
  const body=chrome('orgs',t('Clients'));
  pa([
    {label:t('New client'),icon:'plus',primary:true,onClick:async()=>{
      const f=await ask(t('New client'),null,t('Create'),[
        {name:'name',label:t('Name'),required:true},
        {name:'ref',label:t('Reference (optional)')},
        {name:'kind',label:t('Type'),type:'select',options:[['company',t('Company')],['private',t('Private')],['object',t('Property')],['other',t('Other')]]},
      ]);
      if(!f)return;
      const r=await post('/api/portal/orgs',{name:f.name,ref:f.ref||null,kind:f.kind});
      r.success?ok(t('Created.')):err(r.message||t('Error'));if(r.success)vOrgs();
    }},
    ...(ME_ADMIN?[{label:t('Import CSV'),icon:'upload',onClick:async()=>{
      const f=await ask(t('Import clients'),null,t('Check'),[{name:'csv',label:t('CSV (email;name;org;kind;ref)'),type:'textarea',required:true},{name:'invite',label:t('Send invitations? (yes/no)')}]);
      if(!f)return;
      const invite=/^y(es)?$/i.test(f.invite||'');
      const p=await post('/api/portal/import',{csv:f.csv,dryRun:'1'});
      if(!p.success){err(p.message||t('Invalid CSV'));return;}
      const rows=(p.data&&p.data.rows)||[];
      if(!(await confirmDlg(t('Import plan'),rows.length+' '+t('rows')+'. '+t('Apply?'))))return;
      const c2=await post('/api/portal/import',{csv:f.csv,dryRun:'0',sendInvitations:invite?'1':'0'});
      c2.success?ok(t('Imported.')+' '+(c2.data&&c2.data.applied||0)+' '+t('new')):err(c2.message||t('Error'));
      vOrgs();
    }}]:[]),
  ]);
  const q=new URLSearchParams(location.search).get('q')||'';
  const c=card(t('Clients'),t('Organisations with a portal — activate the app, manage contacts, sync and links.'));
  c.body.appendChild(h('<div class="kzc-filter-grid kzc-filter-grid--search"><div class="kzc-field"><label class="kzc-label" for="kzc-q">'+esc(t('Search'))+'</label><input id="kzc-q" class="kzc-input" placeholder="'+esc(t('Search…'))+'" value="'+esc(q)+'"></div></div>'));
  c.body.querySelector('#kzc-q').oninput=e=>loadOrgs(e.target.value);
  c.body.appendChild(h('<div id="kzc-orghead"></div><div id="kzc-orglist" class="kzc-list"></div>'));
  body.appendChild(c.card);
  loadOrgs(q);
  async function loadOrgs(term){
    const seq=++ORG_SEQ;
    const rr=await get('/api/portal/orgs'+(term?'?q='+encodeURIComponent(term):''));
    if(seq!==ORG_SEQ)return; // superseded by a newer load — never paint stale
    renderOrgs(rr.data&&rr.data.orgs||[]);
  }
  function renderOrgs(orgs){
    const list=document.getElementById('kzc-orglist'),head=document.getElementById('kzc-orghead');if(!list||!head)return;
    list.innerHTML='';head.innerHTML='';
    head.appendChild(listHead(t('Client list'),orgs.length));
    if(!orgs.length){list.appendChild(empty('users',t('No clients.'),t('Create your first client to give them a portal, or import a CSV.')));return;}
    orgs.forEach(o=>{
      const row=rowEl({icon:'users',title:o.name,sub:[o.ref,o.kind,o.state].filter(Boolean).join(' · '),ariaLabel:o.name,
        meta:[h(badge(o.appOn?'success':'neutral',o.appOn?t('App on'):t('App off')))],onClick:()=>openOrg(o.id)});
      list.appendChild(row);
    });
  }
}
async function openOrg(id){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/orgs/'+id);
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  if(!r.success){err(t('Not found.'));return;}
  const o=r.data.org,body=chrome('orgs',t('Client')+': '+o.name);
  pa([{label:t('All clients'),icon:'arrow-left',onClick:()=>{history.pushState({},'',INDEX_PATH+'?view=orgs');VIEWS.orgs();}}]);
  const c=card(o.name,null);
  c.actions.appendChild(h(badge(o.state==='active'?'success':'neutral',o.state)));
  const act=c.actions;
  act.appendChild(btn(o.appOn?t('Deactivate app'):t('Activate app'),async()=>{
    const rr=await put('/api/portal/orgs/'+id,{appOn:o.appOn?false:true});
    if(rr.success)openOrg(id);else if(rr.error==='NO_PORTAL_SLOT')err(t('No free app slot (license).'));else err(rr.message||t('Error'));
  },{variant:o.appOn?'':'primary'}));
  if(o.state==='active')act.appendChild(btn(t('Offboard'),async()=>{
    if(!(await confirmDlg(t('Offboard'),t('Chat will be archived and members lose access. Continue?'))))return;
    const rr=await post('/api/portal/orgs/'+id+'/offboard');
    const wn=rr&&rr.data&&rr.data.partialWarning==='sync_revoke_failed'?(rr.data.failed||0):0;
    await openOrg(id);
    if(wn>0){
      // failed share revokes leave live oc_share rows — surface, never silently
      const w=h('<p class="kzc-badge kzc-badge--warn kzc-warnbar" role="alert"></p>').firstElementChild;
      w.textContent=n('Offboarded — but 1 synced share could not be revoked. Open Desktop sync below and revoke it again.','Offboarded — but %n synced shares could not be revoked. Open Desktop sync below and revoke them again.',wn);
      const pb=document.getElementById('kzc-body');if(pb)pb.prepend(w);
    }else if(rr&&rr.success===false){err(rr.message||t('Error'));}
  },{variant:'danger'}));
  act.appendChild(btn(t('Edit details'),async()=>{
    const f=await ask(t('Client details'),null,t('Save'),[
      {name:'name',label:t('Name'),required:true,value:o.name},
      {name:'ref',label:t('Reference'),value:o.ref||''},
      {name:'bookUrl',label:t('Booking link (/pub/o/slug, optional)'),value:o.bookUrl||''},
      {name:'pcCustId',label:t('Invoicing app customer id (optional)'),value:o.pcCustId||''},
    ]);
    if(!f)return;
    const rr=await put('/api/portal/orgs/'+id,{name:f.name,ref:f.ref||null,bookUrl:f.bookUrl||null,pcCustId:f.pcCustId!==''?+f.pcCustId:null});
    if(!rr.success){err(rr.message||t('Invalid input'));return;}
    openOrg(id);
  }));
  const kv=h('<div class="kzc-kv"></div>').firstElementChild;
  kv.innerHTML=[
    [t('Name'),o.name],[t('Reference'),o.ref||'—'],[t('Type'),o.kind],[t('Status'),o.state],[t('App access'),o.appOn?t('active'):t('off')],[t('Booking link'),o.bookUrl||'—'],[t('Intake'),(r.data.intake&&r.data.intake.address)||'—'],
  ].map(([k,v])=>'<div class="kzc-kvrow"><span>'+esc(k)+'</span><b>'+esc(v)+'</b></div>').join('');
  c.body.appendChild(kv);
  body.appendChild(c.card);
  // responsible staff contacts (US-P25)
  const rc=card(t('Responsible staff'),t('Colleagues assigned to this client — shown in "Only my clients" filters.'));
  rc.body.appendChild(h('<div id="kzc-resp" class="kzc-list"></div>'));
  rc.actions.appendChild(btn(t('Set responsible staff'),async()=>{
    const dlg=dlgShell(t('Set responsible staff'),pickerField('rs-q',t('Responsible staff'),t('Search users…')),t('Save'));
    document.body.appendChild(dlg);
    let pk;
    pk=dirPicker(dlg.querySelector('.kzc-picker'),(r.data.contacts||[]).map(c=>c.user_id||c.userId),'/api/portal/users/search',ids=>{if(ids.length>5){pk.setSelected(pk.getSelected().slice(0,5));warnT(t('Choose up to five colleagues.'));}});
    dlg.addEventListener('close',async()=>{
      if(dlg.returnValue!=='ok'){dlg.remove();return;}
      const uids=pk.getSelected().map(x=>x.id).slice(0,5);
      const rr=await put('/api/portal/orgs/'+id+'/contacts',{userIds:uids});
      rr.success||rr.status===204?ok(t('Saved.')):err(rr.message||t('Error'));dlg.remove();openOrg(id);
    });
    dlg.showModal();
  },{sm:true}));
  body.appendChild(rc.card);
  const resp=document.getElementById('kzc-resp');
  (r.data.contacts||[]).forEach(ct=>{
    resp.appendChild(devRow(ct.user_id||ct.userId));
  });
  if(!(r.data.contacts||[]).length)resp.appendChild(h('<p class="kzc-muted">'+esc(t('Nobody assigned yet.'))+'</p>'));
  const mc=card(t('Contacts'),t('Portal users of this client — they sign in by e-mail code.'));
  mc.body.appendChild(h('<div id="kzc-members" class="kzc-list"></div>'));
  mc.actions.appendChild(btn(t('Add contact'),async()=>{
    const f=await ask(t('Add contact'),null,t('Add'),[{name:'email',label:t('E-mail'),type:'email',required:true},{name:'displayName',label:t('Name')}]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/members',{email:f.email,displayName:f.displayName});
    rr.success?ok(t('Added.')):err(rr.message||t('Error'));if(rr.success)openOrg(id);
  },{sm:true,icon:'plus'}));
  body.appendChild(mc.card);
  const ml=document.getElementById('kzc-members');
  (r.data.members||[]).forEach(m=>{
    const row=devRow((m.displayName||m.email)+(m.status!=='active'?' ('+m.status+')':''));
    if(ME_ADMIN&&m.status==='active'){
      devAct(row,btn(t('Disable'),async()=>{await put('/api/portal/identities/'+m.identityId+'/status',{status:'disabled'});openOrg(id);},{sm:true}));
    }else if(ME_ADMIN&&m.status==='disabled'){
      devAct(row,btn(t('Enable'),async()=>{await put('/api/portal/identities/'+m.identityId+'/status',{status:'active'});openOrg(id);},{sm:true}));
    }
    devAct(row,btn(t('Remove'),async()=>{await del('/api/portal/orgs/'+id+'/members/'+m.identityId);openOrg(id);},{sm:true}));
    ml.appendChild(row);
  });
  if(!(r.data.members||[]).length)ml.appendChild(h('<p class="kzc-muted">'+esc(t('No contacts yet.'))+'</p>'));
  renderSyncSection(id,body,r.data);
  const uc=card(t('Upload links'),t('Public upload addresses — for files from people without a portal account.'));
  uc.body.appendChild(h('<div id="kzc-ulinks" class="kzc-list"></div>'));
  body.appendChild(uc.card);
  const ul=document.getElementById('kzc-ulinks');
  (r.data.ulinks||[]).forEach(l=>{
    const row=devRow(l.label+' · '+l.usedFiles+'/'+l.maxFiles+(l.revoked?' '+t('(revoked)'):''));
    if(!l.revoked)devAct(row,btn(t('Revoke'),async()=>{await del('/api/portal/ulinks/'+l.id);openOrg(id);},{sm:true}));
    ul.appendChild(row);
  });
  if(!(r.data.ulinks||[]).length)ul.appendChild(h('<p class="kzc-muted">'+esc(t('No links yet.'))+'</p>'));
  // #kzc-ulinks + button adjacency is an e2e hook (l10n sweep) — keep it.
  const ub=btn(t('Upload link'),async()=>{
    const f=await ask(t('Create upload link'),null,t('Create'),[
      {name:'label',label:t('Label'),required:true},
      {name:'expiryDays',label:t('Valid (days)'),type:'number'},
      {name:'maxFiles',label:t('Max. files'),type:'number'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/ulinks',{label:f.label,expiryDays:+f.expiryDays||7,maxFiles:+f.maxFiles||20});
    if(rr.success){await ask(t('Link created'),t('URL (visible once):')+' '+rr.data.link.url,t('OK'));}else err(rr.message||t('Error'));
    openOrg(id);
  },{icon:'plus'});
  ul.parentNode.insertBefore(ub,ul.nextSibling);
  // intake address (US-P24)
  const ic=card(t('E-mail intake'),t('The client mails files to a generated address — they land in the upload queue.'));
  const inRow=h('<div class="kzc-kvrow"><span>'+esc(t('Address'))+'</span><b id="kzc-ina"></b></div>').firstElementChild;
  inRow.querySelector('#kzc-ina').textContent=(r.data.intake&&r.data.intake.address)||t('disabled');
  ic.body.appendChild(inRow);
  ic.actions.appendChild(btn(r.data.intake&&r.data.intake.address?t('Disable'):t('Enable'),async()=>{
    const rr=await put('/api/portal/orgs/'+id+'/intake',{enabled:!(r.data.intake&&r.data.intake.address)});
    if(rr.success)openOrg(id);else err(rr.message||t('Error'));
  },{sm:true}));
  ic.actions.appendChild(btn(t('Rotate address'),async()=>{
    if(!(await confirmDlg(t('Rotate'),t('The old address stops working immediately. Continue?'))))return;
    await post('/api/portal/orgs/'+id+'/intake/rotate');openOrg(id);
  },{sm:true}));
  body.appendChild(ic.card);
  // approvals on documents (US-P23)
  const ac=card(t('Approvals'),t('Documents the client must formally approve or reject.'));
  ac.body.appendChild(h('<div id="kzc-appr" class="kzc-list"></div>'));
  ac.actions.appendChild(btn(t('Request approval'),async()=>{
    const docs=await get('/api/portal/orgs/'+id+'/documents');
    const files=(docs.data&&docs.data.files)||[];
    if(!files.length){warnT(t('No documents in this client\'s Dokumente folder yet.'));return;}
    const f=await ask(t('Request approval'),null,t('Send'),[
      {name:'fileId',label:t('Document'),type:'select',options:files.map(fl=>[fl.id,fl.name])},
      {name:'deadline',label:t('Deadline'),type:'date'},
      {name:'note',label:t('Note'),type:'textarea'},
    ]);
    if(!f)return;
    const rr=await post('/api/portal/orgs/'+id+'/approvals',{fileId:+f.fileId,deadline:f.deadline||null,note:f.note||null});
    rr.success?ok(t('Sent.')):err(rr.message||t('Error'));if(rr.success)openOrg(id);
  },{sm:true,icon:'plus'}));
  body.appendChild(ac.card);
  const ap=document.getElementById('kzc-appr');
  const ar=await get('/api/portal/orgs/'+id+'/approvals');
  if(seq!==VIEW_SEQ)return;
  if(ar.success&&ar.data){
    (ar.data.approvals||[]).forEach(a=>{
      const row=devRow(a.fileName+' · '+a.state+(a.deadline?' · '+a.deadline:''));
      if(a.state==='pending')devAct(row,btn(t('Withdraw'),async()=>{await post('/api/portal/approvals/'+a.id+'/withdraw');openOrg(id);},{sm:true}));
      const pf=h('<a class="kzc-btn kzc-btn--sm"></a>').firstElementChild;pf.textContent=t('Proof');pf.href=base+'/api/portal/approvals/'+a.id+'/proof';devAct(row,pf);
      ap.appendChild(row);
    });
    if(!(ar.data.approvals||[]).length)ap.appendChild(h('<p class="kzc-muted">'+esc(t('No approvals yet.'))+'</p>'));
  }
  // send questionnaire — secondary action under approvals/forms workflows
  const qc=card(t('Questionnaires'),t('Send a form for the client to fill in.'));
  qc.actions.appendChild(btn(t('Send questionnaire'),async()=>{
    const tpls=await get('/api/portal/form-templates');
    const tt=(tpls.data&&tpls.data.templates)||[];
    if(!tt.length){warnT(t('No form templates yet — create one under Questionnaires.'));return;}
    const f=await ask(t('Send questionnaire'),null,t('Send'),[{name:'tplId',label:t('Template'),type:'select',options:tt.map(tp=>[tp.id,tp.name])}]);
    if(!f)return;
    const rr=await post('/api/portal/forms',{orgId:id,tplId:+f.tplId});
    rr.success?ok(t('Sent.')):err(rr.message||t('Error'));
  },{sm:true,icon:'plus'}));
  body.appendChild(qc.card);
  // invoices (the invoicing app facade)
  if(r.data.invoiceAvailable){
    const vc=card(t('Invoices'),null);
    const inv=(r.data.invoices)||[];
    if(!inv.length)vc.body.appendChild(h('<p class="kzc-muted">'+esc(t('No invoices.'))+'</p>'));
    else vc.body.appendChild(h('<div>'+inv.map(i=>'<div class="kzc-kvrow"><span>'+esc(i.ref||('#'+i.id))+(i.overdue?' ⚠':'')+'</span><b>'+esc(i.status)+'</b></div>').join('')+'</div>'));
    body.appendChild(vc.card);
  }
}

/* ── Desktop-Sync (nc-user-binding §7) ──
   One control per member: toggle + chip. Three display states only
   (D-B20): Aktiv | Ausstehend | Problem+repair. The two-layer model
   (identity bind vs org share) never leaks into the UI (D-B19). */
const SYNC_CHIP={
  active:['success',()=>t('Active')],
  pending_accept:['warn',()=>t('Pending (recipient must accept the share)')],
  disabled:['neutral',()=>t('Paused by you')],
  broken_user:['danger',()=>t('Problem — account missing or disabled')],
  broken_share:['danger',()=>t('Problem — share missing')],
  broken_perms:['danger',()=>t('Problem — permissions changed')],
  staff_conflict:['danger',()=>t('Problem — account is staff')],
};
function syncChip(state){
  const c=SYNC_CHIP[state]||['neutral',()=>state];
  const el=h('<span class="kzc-badge kzc-chip kzc-badge--'+c[0]+'"><span class="kzc-badge__dot" aria-hidden="true"></span><span class="kzc-badge__label"></span></span>').firstElementChild;
  el.querySelector('.kzc-badge__label').textContent=c[1]();
  return el;
}
async function syncSetupDialog(orgId,member){
  const s=await get('/api/portal/identities/'+member.identityId+'/nc-suggest');
  if(!s.success){err(s.message||t('Error'));return;}
  if(!s.data.doorRestricted){warnT(t('Desktop sync requires a restricted access door — see Settings → Access (door).'));return;}
  if(s.data.ncUid){
    // already bound (AC-3): show the bound account + inline unbind escape
    const u=await confirmDlg(t('Already linked'),t('This person is linked to Nextcloud account')+' "'+s.data.ncUid+'". '+t('Unlink now?'));
    if(u){const rr=await api('/api/portal/identities/'+member.identityId+'/nc-bind',{method:'DELETE'});rr.success?ok(t('Unlinked.')):err(rr.message||t('Error'));openOrg(orgId);}
    return;
  }
  const dlg=dlgShell(t('Set up desktop sync'),
    '<p class="kzc-muted">'+esc(t('The client receives their files as a synced folder on their PC — via their existing Nextcloud account.'))+'</p>'
    +'<div class="kzc-field"><label class="kzc-label" for="sd-q">'+esc(t('Nextcloud account'))+'</label><input id="sd-q" class="kzc-input" autocomplete="off"><div id="sd-hits" class="kzc-list"></div><input id="sd-uid" type="hidden"></div>'
    +'<fieldset class="kzc-fieldset"><legend>'+esc(t('Access'))+'</legend>'
    +'<label class="kzc-label"><input type="radio" name="sd-perms" value="rw" checked> '+esc(t('Read & write'))+'</label>'
    +'<label class="kzc-label"><input type="radio" name="sd-perms" value="ro"> '+esc(t('Read only'))+'</label></fieldset>'
    +'<p id="sd-err" class="kzc-field-error" role="alert" hidden></p>',t('Link & enable'));
  document.body.appendChild(dlg);
  const q=dlg.querySelector('#sd-q'),hits=dlg.querySelector('#sd-hits'),uidI=dlg.querySelector('#sd-uid'),okB=dlg.querySelector('.kzc-btn--primary'),errE=dlg.querySelector('#sd-err');
  okB.disabled=true;
  const sug=s.data.suggest;
  let sdSeq=0;
  const fill=async(term)=>{
    const seq=++sdSeq;
    const r=await get('/api/portal/users/search?q='+encodeURIComponent(term));
    if(seq!==sdSeq||!dlg.isConnected)return; // superseded/closed — never paint stale hits
    hits.innerHTML='';
    ((r.data&&r.data.users)||[]).forEach(u=>{
      const b=document.createElement('button');b.type='button';b.className='kzc-row';
      b.textContent=u.displayName+' ('+u.uid+')'+(u.email?' — '+u.email:'')+(u.staff?' ⚠ '+t('staff'):'');
      if(u.staff){b.disabled=true;b.title=t('Staff accounts cannot be linked');}
      b.onclick=()=>{uidI.value=u.uid;q.value=u.displayName+' ('+u.uid+')';okB.disabled=false;hits.innerHTML='';};
      hits.appendChild(b);
    });
  };
  if(sug){uidI.value=sug.uid;q.value=sug.displayName+' ('+sug.uid+')';okB.disabled=false;}
  let deb=null;q.addEventListener('input',()=>{clearTimeout(deb);uidI.value='';okB.disabled=true;deb=setTimeout(()=>fill(q.value),300);});
  dlg.addEventListener('close',async()=>{
    if(dlg.returnValue!=='ok'){dlg.remove();return;}
    const perms=dlg.querySelector('input[name="sd-perms"]:checked').value;
    const attempt=async(acceptMismatch,acceptShared)=>{
      const r=await put('/api/portal/identities/'+member.identityId+'/nc-bind',{ncUid:uidI.value,acceptMismatch:acceptMismatch?'1':'0',acceptShared:acceptShared?'1':'0'});
      if(r.success)return 'ok';
      return r.error||'ERR';
    };
    const bindAndEnable=async()=>{
      const res=await attempt(false,false);
      if(res==='SYNC_EMAIL_MISMATCH'||res==='SYNC_UID_SHARED'){
        // confirm gate stays inside the same dialog — the click IS the control
        const warn2=res==='SYNC_EMAIL_MISMATCH'?t('The account e-mail differs from the portal e-mail. Link anyway?'):t('This account is already linked to another person. Link anyway?');
        if(!(await confirmDlg(t('Please confirm'),warn2)))return 'aborted';
        const again=await attempt(true,true);
        if(again!=='ok')return again;
      }else if(res!=='ok')return res;
      const en=await post('/api/portal/orgs/'+orgId+'/sync',{identityId:member.identityId,perms});
      return en.success?'ok':(en.error||'ERR');
    };
    const out=await bindAndEnable();
    const msg={ok:t('Desktop sync enabled.'),SYNC_UID_UNKNOWN:t('Account not found.'),SYNC_UID_IS_STAFF:t('Staff accounts cannot be linked'),SYNC_UID_DISABLED:t('Activate the account in user administration first.'),SYNC_ALREADY_BOUND:t('Already linked — unlink first.'),SYNC_NOT_BOUND:t('Link the account first.'),SYNC_ORG_OFFBOARDED:t('Client is offboarded.'),SYNC_DOOR_OPEN:t('Desktop sync requires a restricted access door.'),NO_SHARE_PERM:t('Check share permission on the documents folder.'),NO_DOCS_ROOT:t('Documents root is not configured.'),aborted:t('Cancelled.')}[out]||t('Error');
    out==='ok'?ok(msg):out==='aborted'?status(msg):err(msg);
    dlg.remove();
    openOrg(orgId);
  });
  dlg.showModal();
}
function renderSyncSection(orgId,body,data){
  const c=card(t('Desktop sync'),t('The client receives their files as a synced folder on their PC — via their existing Nextcloud account.'));
  body.appendChild(c.card);
  c.body.appendChild(h('<div class="kzc-sync-list"></div>'));
  const list=c.body.querySelector('.kzc-sync-list');
  if(!data.doorRestricted){
    const nEl=h('<p class="kzc-muted"></p>').firstElementChild;
    nEl.textContent=t('Desktop sync is unavailable while the access door is open — restrict it under Settings → Access (door).');
    list.appendChild(nEl);return; // proactive notice (US-S16), no dead-end error
  }
  const bindings={};(data.sync||[]).forEach(b=>bindings[b.identityId]=b);
  const members=(data.members||[]).filter(m=>m.status==='active');
  if(!members.length){list.appendChild(h('<p class="kzc-muted">'+esc(t('No contacts yet.'))+'</p>'));return;}
  members.forEach(m=>{
    const row=devRow((m.displayName||m.email)+(m.ncUid?' — '+(m.ncDisplay||m.ncUid):''));
    row.classList.add('kzc-sync-row');
    const b=bindings[m.identityId];
    const wrap=row.querySelector('.kzc-actions');
    if(b){
      wrap.appendChild(syncChip(b.state));
      if(m.ncUidMismatch){const w=h('<span class="kzc-badge kzc-chip kzc-badge--warn"></span>').firstElementChild;w.textContent=t('e-mail differs');wrap.appendChild(w);}
      if(ME_ADMIN&&['broken_share','broken_perms'].includes(b.state)){
        const rp=btn(t('Repair'),async()=>{const rr=await post('/api/portal/sync/'+b.bindingId+'/repair',{});rr.success?ok(t('Repaired.')):err(rr.message||t('Error'));openOrg(orgId);},{variant:'primary',sm:true});wrap.appendChild(rp);
      }
      if(ME_ADMIN){
        const tog=btn(t('Off'),async()=>{
          if(!(await confirmDlg(t('Turn off sync'),t('The client loses folder access via their Nextcloud account. Continue?'))))return;
          const rr=await api('/api/portal/sync/'+b.bindingId,{method:'DELETE'});rr.success?ok(t('Sync off.')):err(rr.message||t('Error'));openOrg(orgId);
        },{sm:true});wrap.appendChild(tog);
      }
    }else if(ME_ADMIN){
      const st=btn(t('Set up'),()=>syncSetupDialog(orgId,m),{variant:'primary',sm:true});wrap.appendChild(st);
    }
    list.appendChild(row);
  });
}

/* ── Uploads ── */
async function vUploads(){
  VIEW_SEQ++;
  const body=chrome('uploads',t('Inbox — upload queue'));
  const r=await get('/api/portal/uploads');
  const ups=(r.data&&r.data.uploads)||[];
  const c=card(t('Upload queue'),t('Files clients or upload-link visitors sent you — review, file or publish.'));
  c.body.appendChild(listHead(t('New uploads'),ups.length));
  if(!ups.length){c.body.appendChild(empty('upload',t('No new uploads.'),t('Files from portal uploads, upload links and e-mail intake show up here.')));}
  else{
    const rowsHtml=ups.map((u,i)=>'<tr data-i="'+i+'"><td data-cell="'+esc(t('File'))+'"><b>'+esc(u.fileName)+'</b></td><td data-cell="'+esc(t('Client'))+'">'+esc(u.orgName)+'</td><td data-cell="'+esc(t('From'))+'">'+esc(u.uploader+' · '+u.source)+'</td><td data-cell="'+esc(t('Received'))+'">'+esc(new Date(u.createdAt).toLocaleString())+'</td><td data-cell="'+esc(t('State'))+'">'+(u.duplicate?badge('warning',t('DUPLICATE')):badge('info',t('new')))+'</td><td class="kzc-table-actions-col"><div class="kzc-table-actions" data-i="'+i+'"></div></td></tr>').join('');
    const tw=tbl([t('File'),t('Client'),t('From'),t('Received'),t('State'),t('Actions')],rowsHtml);
    c.body.appendChild(tw);
    tw.querySelectorAll('.kzc-table-actions').forEach(act=>{
      const u=ups[+act.dataset.i];
      const dl=h('<a class="kzc-btn kzc-btn--sm"></a>').firstElementChild;dl.textContent=t('Download');dl.href=base+'/api/portal/uploads/'+u.id+'/file';act.appendChild(dl);
      act.appendChild(btn(t('Seen'),async()=>{await put('/api/portal/uploads/'+u.id+'/review',{state:'eingesehen'});vUploads();},{sm:true}));
      act.appendChild(btn(t('Filed'),async()=>{await put('/api/portal/uploads/'+u.id+'/review',{state:'abgelegt'});vUploads();},{variant:'primary',sm:true}));
      act.appendChild(btn(t('Publish to client'),async()=>{
        const rr=await post('/api/portal/uploads/'+u.id+'/publish');
        rr.success?ok(t('Published to Dokumente.')):err(rr.message||t('Error'));if(rr.success)vUploads();
      },{sm:true}));
    });
  }
  body.appendChild(c.card);
}

/* ── Settings — one URL per topic (/settings/{section}); each section is a
   card rendered inside the shared shell chrome. The catalog order lives in
 * PageController (settingsNav) — this map only renders section bodies. */
const SETTINGS_SECTIONS={
  general:()=>t('Appearance & preset'),
  access:()=>t('Access'),
  documents:()=>t('Documents'),
  mail:()=>t('Inbound e-mail'),
  license:()=>t('License'),
  diagnostics:()=>t('Diagnostics'),
};
async function vSettings(section){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/settings');
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  const sec=SETTINGS_SECTIONS[section]?section:'general';
  const body=chrome('settings',t('Settings')+' — '+SETTINGS_SECTIONS[sec]());
  if(!r.success){
    body.appendChild(emptyErr(t('No access'),t('Settings are only visible to KanzleiCheck app admins. Your account is not on the app-admin list.')));
    const a=card(t('About this project'),t('Sponsor, open-source license and project links.'));
    a.body.appendChild(h('<div class="kzc-actions"><a class="kzc-btn" href="'+esc(base+'/settings/about')+'">'+esc(t('Open about page'))+'</a></div>'));
    body.appendChild(a.card);
    return;
  }
  const s=r.data.settings,d=r.data.door;
  const saveBtn=(cardEl,payloadFn)=>{cardEl.querySelector('.kzc-actions').appendChild(btn(t('Save'),async()=>{
    const rr=await put('/api/portal/settings',payloadFn());
    rr.success?ok(t('Saved.')):err(rr.message||t('Error'));
  },{variant:'primary'}));};

  if(sec==='general'){
    const c=card(t('Appearance & preset'),t('Which industry wording the portal and e-mails use, and the sender name.'));
    const form=h('<div><div class="kzc-field"><label class="kzc-label" for="s-preset">'+esc(t('Industry (preset)'))+'</label><select id="s-preset" class="kzc-input"></select></div>'+field('s-label',t('Provider name (e-mails)'),'text',s.providerLabel)+'<div class="kzc-actions"></div></div>').firstElementChild;
    const sel=form.querySelector('#s-preset');
    r.data.presets.forEach(p=>sel.appendChild(new Option(p,p)));
    sel.value=s.preset;
    saveBtn(form,()=>({preset:sel.value,providerLabel:form.querySelector('#s-label').value}));
    c.body.appendChild(form);
    body.appendChild(c.card);
  }else if(sec==='access'){
    const dc=card(t('Access (door)'),t('Who may open the staff area — restrict to groups/users, plus the app-admin role.'));
    const door=h('<div>'+checkField('d-restr',t('Restricted (selected groups/users only)'))+'<p class="kzc-muted">'+esc(t('Platform restriction:'))+' '+(d.platformGroups.length?esc(d.platformGroups.join(', ')):esc(t('none')))+'</p>'+pickerField('d-groups',t('Allowed groups'),t('Search groups…'))+pickerField('d-users',t('Allowed users'),t('Search users…'))+pickerField('d-admins',t('App admins'),t('Search users…'))+'<div class="kzc-actions"></div></div>').firstElementChild;
    door.querySelector('#d-restr').checked=d.restricted;
    const pGroups=dirPicker(door.querySelector('#d-groups').closest('.kzc-picker'),d.allowedGroups,'/api/portal/groups/search');
    const pUsers=dirPicker(door.querySelector('#d-users').closest('.kzc-picker'),d.allowedUsers,'/api/portal/users/search');
    const pAdmins=dirPicker(door.querySelector('#d-admins').closest('.kzc-picker'),d.appAdmins,'/api/portal/users/search');
    door.querySelector('.kzc-actions').appendChild(btn(t('Save door'),async()=>{
      const ids=p=>p.getSelected().map(x=>x.id);
      const rr=await put('/api/portal/door',{restricted:door.querySelector('#d-restr').checked,groups:ids(pGroups),users:ids(pUsers),appAdmins:ids(pAdmins)});
      rr.success?ok(t('Saved.')):err(rr.message||t('Error'));
    },{variant:'primary'}));
    dc.body.appendChild(door);
    body.appendChild(dc.card);
    const ac=card(t('Sign-in'),t('How long codes and sessions stay valid.'));
    const auth=h('<div>'+field('s-ttl',t('OTP validity (minutes)'),'number',s.otpTtlMin)+field('s-sess',t('Portal session lifetime (days)'),'number',s.sessDays)+'<div class="kzc-actions"></div></div>').firstElementChild;
    saveBtn(auth,()=>({otpTtlMin:+auth.querySelector('#s-ttl').value,sessDays:+auth.querySelector('#s-sess').value}));
    ac.body.appendChild(auth);
    body.appendChild(ac.card);
  }else if(sec==='documents'){
    const dc=card(t('Documents'),t('Upload limits, the portal file root and audit retention.'));
    const docs=h('<div>'+field('s-max',t('Upload limit (MB)'),'number',s.uploadMaxMb)+field('s-root',t('Documents root folder (file ID)'),'number',s.docsRootId,t('The file ID of an existing Nextcloud folder — visible in the Files app URL.'))+field('s-audit',t('Audit retention (days)'),'number',s.auditKeepDays)+'<div class="kzc-actions"></div></div>').firstElementChild;
    saveBtn(docs,()=>({uploadMaxMb:+docs.querySelector('#s-max').value,docsRootId:+docs.querySelector('#s-root').value||'',auditKeepDays:+docs.querySelector('#s-audit').value}));
    dc.body.appendChild(docs);
    body.appendChild(dc.card);
  }else if(sec==='mail'){
    const inb=(s.inbound)||{};
    const mc=card(t('Inbound e-mail'),t('Poll an IMAP mailbox — client attachments land in the upload queue.'));
    const mail=h('<div>'+checkField('m-en',t('Poll an IMAP mailbox for client e-mail'))+field('m-host',t('IMAP host'),'text',inb.host)+field('m-port',t('Port'),'number',inb.port)+field('m-user',t('IMAP user'),'text',inb.user)+field('m-pass',t('IMAP password (leave empty to keep)'),'password','')+field('m-local',t('Intake address local part'),'text',inb.local)+field('m-domain',t('Intake domain'),'text',inb.domain)+'<div class="kzc-actions"></div></div>').firstElementChild;
    mail.querySelector('#m-en').checked=!!inb.enabled;
    saveBtn(mail,()=>({inbound:{enabled:mail.querySelector('#m-en').checked,host:mail.querySelector('#m-host').value,port:+mail.querySelector('#m-port').value,user:mail.querySelector('#m-user').value,pass:mail.querySelector('#m-pass').value,local:mail.querySelector('#m-local').value,domain:mail.querySelector('#m-domain').value}}));
    mc.body.appendChild(mail);
    body.appendChild(mc.card);
  }else if(sec==='license'){
    const l=await get('/api/portal/license');
    if(seq!==VIEW_SEQ)return;
    if(l.success&&l.data){
      const lic=l.data.license;
      const c=card(t('License'),t('Portal clients and mobile seats on this instance.'));
      c.body.appendChild(h('<p>'+esc(t('Status:'))+' '+badge(lic.status==='active'?'success':'warning',lic.status)+' '+esc(t('Portal clients:'))+' '+lic.usedPortalClients+'/'+lic.portalClients+' · '+esc(t('Mobile seats:'))+' '+(lic.mobileSeats??'—')+'</p><div class="kzc-actions" id="l-actions"></div>'));
      c.body.querySelector('#l-actions').appendChild(btn(t('Apply license key'),async()=>{
        const f=await ask(t('KZC2 key'),t('Paste key:'),t('Apply'));
        if(!f)return;
        const rr=await post('/api/portal/license',{key:f.v});
        rr.success?ok(t('Applied.')):err(rr.message||t('Invalid key'));if(rr.success)vSettings(sec);
      },{variant:'primary'}));
      body.appendChild(c.card);
      // Named mobile seats (P6-08): staff who may sign in with the companion
      // app. Assignment is admin-only; each change writes an audit row (AC-5).
      const sr=await get('/api/portal/seats');
      if(seq!==VIEW_SEQ)return;
      if(sr.success&&sr.data){
        const sc=card(t('Mobile seats'),t('Staff who may sign in with the KanzleiCheck app. Named Nextcloud users only.'));
        const used=sr.data.seats.filter(x=>x.withinLimit).length;
        sc.body.appendChild(h('<p class="kzc-muted">'+esc(t('Seats used:'))+' '+used+'/'+sr.data.mobileSeats+'</p>'));
        const list=h('<div class="kzc-list"></div>').firstElementChild;
        if(!sr.data.seats.length){sc.body.appendChild(empty('users',t('No seats assigned.'),t('Assign a seat so staff can use the companion app.')));}
        sr.data.seats.forEach(srow=>{
          const row=h('<div class="kzc-card2"><div class="kzc-card2__head"><b class="kzc-card2__title"></b>'+(srow.withinLimit?badge('success',t('active')):badge('warning',t('over limit')))+'</div><div class="kzc-muted"></div><div class="kzc-actions"></div></div>').firstElementChild;
          row.querySelector('.kzc-card2__title').textContent=srow.displayName+' ('+srow.uid+')';
          row.querySelector('.kzc-muted').textContent=(srow.assignedAt?t('Assigned:')+' '+new Date(srow.assignedAt).toLocaleDateString():'')+(srow.assignedBy?' · '+srow.assignedBy:'');
          row.querySelector('.kzc-actions').appendChild(btn(t('Remove'),async()=>{
            if(!(await confirmDlg(t('Remove seat'),t('This person can no longer sign in with the app. Continue?'))))return;
            const rr=await del('/api/portal/seats/'+srow.id);
            rr.success?vSettings(sec):err(rr.message||t('Error'));
          },{variant:'danger',sm:true}));
          list.appendChild(row);
        });
        sc.body.appendChild(list);
        sc.body.appendChild(h(pickerField('seat-q',t('Assign seat'),t('Search users…'))));
        const pk=dirPicker(sc.body.querySelector('#seat-q').closest('.kzc-picker'),[],'/api/portal/users/search');
        sc.body.appendChild(h('<div class="kzc-actions" id="seat-add"></div>'));
        sc.body.querySelector('#seat-add').appendChild(btn(t('Assign seat'),async()=>{
          const uid=(pk.getSelected()[0]||{}).id;
          if(!uid){err(t('Pick a user first.'));return;}
          const rr=await post('/api/portal/seats',{uid});
          rr.success?ok(t('Seat assigned.')):err(rr.message||t('Error'));if(rr.success)vSettings(sec);
        },{variant:'primary'}));
        body.appendChild(sc.card);
      }
    }
  }else if(sec==='diagnostics'){
    const pc=card(t('Runtime'),t('Background polling interval.'));
    const poll=h('<div>'+field('s-poll',t('Poll interval (ms)'),'number',s.pollMs)+'<div class="kzc-actions"></div></div>').firstElementChild;
    saveBtn(poll,()=>({pollMs:+poll.querySelector('#s-poll').value}));
    pc.body.appendChild(poll);
    body.appendChild(pc.card);
    const diag=await get('/api/portal/diagnostics');
    if(seq!==VIEW_SEQ)return;
    if(diag.success&&diag.data){
      const g=diag.data;
      const dc=card(t('Diagnostics'),t('Health of cron, mail outbox, documents root and license.'));
      dc.body.appendChild(h('<div>'
        +'<div class="kzc-kvrow"><span>'+esc(t('Cron'))+'</span><b>'+(g.cron.stale?badge('danger',t('stale')):badge('success',t('ok')))+'</b></div>'
        +'<div class="kzc-kvrow"><span>'+esc(t('Outbox'))+'</span><b>'+g.outbox.pending+' '+esc(t('pending'))+' / '+g.outbox.failed+' '+esc(t('failed'))+'</b></div>'
        +'<div class="kzc-kvrow"><span>'+esc(t('Documents root'))+'</span><b>'+(g.docsRoot.healthy?badge('success',t('ok')):badge('danger',t('broken — configure')))+'</b></div>'
        +'<div class="kzc-kvrow"><span>'+esc(t('License'))+'</span><b>'+esc(g.license.status)+'</b></div>'
        +'</div>'));
      body.appendChild(dc.card);
    }
  }
}

/* ── Mail intake holds (US-P24) ── */
async function vHolds(){
  VIEW_SEQ++;
  const body=chrome('holds',t('Mail review'));
  const r=await get('/api/portal/intake-holds');
  const holds=(r.data&&r.data.holds)||[];
  const c=card(t('Held e-mails'),t('Inbound mail waiting for a decision — attachments go to the upload queue or are discarded.'));
  c.body.appendChild(listHead(t('On hold'),holds.length));
  if(!holds.length){c.body.appendChild(empty('file-warning',t('No held e-mails.'),t('Suspicious or unassignable inbound mail is held here for review.')));}
  const list=h('<div class="kzc-list"></div>').firstElementChild;
  holds.forEach(hh=>{
    const row=h('<div class="kzc-card2"><div class="kzc-card2__head"><b class="kzc-card2__title"></b>'+badge(hh.state==='held'?'warning':'neutral',hh.state)+'</div><div class="kzc-muted"></div><div class="kzc-actions"></div></div>').firstElementChild;
    row.querySelector('.kzc-card2__title').textContent=t('Hold')+' #'+hh.id+' · '+t('org')+' '+hh.orgId;
    row.querySelector('.kzc-muted').textContent=new Date(hh.receivedAt).toLocaleString();
    const act=row.querySelector('.kzc-actions');
    if(hh.state==='held'){
      act.appendChild(btn(t('Accept'),async()=>{
        const rr=await post('/api/portal/intake-holds/'+hh.id+'/decide',{action:'accept'});
        rr.success?ok(t('Filed to Eingang.')+' '+(rr.data&&rr.data.stored||0)+' '+t('files')):err(rr.message||t('Error'));if(rr.success)vHolds();
      },{variant:'primary',sm:true}));
      act.appendChild(btn(t('Discard'),async()=>{
        if(!(await confirmDlg(t('Discard'),t('Attachments are deleted permanently. Continue?'))))return;
        await post('/api/portal/intake-holds/'+hh.id+'/decide',{action:'discard'});vHolds();
      },{variant:'danger',sm:true}));
    }
    list.appendChild(row);
  });
  c.body.appendChild(list);
  body.appendChild(c.card);
}

/* ── Questionnaires (staff side, US-P28) ── */
async function vForms(){
  VIEW_SEQ++;
  const body=chrome('forms',t('Questionnaires'));
  if(ME_ADMIN)pa([{label:t('New template'),icon:'plus',primary:true,onClick:async()=>{
    const f=await ask(t('New form template'),null,t('Create'),[
      {name:'name',label:t('Name'),required:true},
      {name:'fields',label:t('Fields (one per line: key|label|type|required)',),type:'textarea',required:true},
    ]);
    if(!f)return;
    const fields=f.fields.split('\n').map(x=>x.trim()).filter(Boolean).map(line=>{
      const p=line.split('|').map(x=>x.trim());
      return {key:p[0]||'',label:p[1]||p[0]||'',type:p[2]||'text',required:/^(1|true|yes)$/i.test(p[3]||'')};
    });
    const rr=await post('/api/portal/form-templates',{name:f.name,fields});
    rr.success?ok(t('Created.')):err(rr.message||t('Error'));if(rr.success)vForms();
  }}]);
  const tr=await get('/api/portal/form-templates');
  const tpls=(tr.data&&tr.data.templates)||[];
  const tc=card(t('Templates'),t('Reusable question sets.'));
  tc.body.appendChild(listHead(t('Templates'),tpls.length));
  if(!tpls.length)tc.body.appendChild(h('<p class="kzc-muted">'+esc(t('No templates.'))+'</p>'));
  tpls.forEach(tp=>{
    const trow=devRow(tp.name);
    devAct(trow,h('<span class="kzc-muted">'+esc((tp.fields||[]).length)+' '+esc(t('fields'))+'</span>'));
    tc.body.appendChild(trow);
  });
  body.appendChild(tc.card);
  const fr=await get('/api/portal/forms');
  const forms=(fr.data&&fr.data.forms)||[];
  const orgs=await get('/api/portal/orgs');
  const orgNames={};(orgs.data&&orgs.data.orgs||[]).forEach(o=>orgNames[o.id]=o.name);
  const fc=card(t('Forms sent'),t('Questionnaires sent to clients and their answers.'));
  fc.body.appendChild(listHead(t('Sent'),forms.length));
  if(!forms.length){fc.body.appendChild(empty('clipboard-check',t('No forms sent yet.'),t('Send a template to a client from their detail page.')));}
  else{
    const rowsHtml=forms.map((f,i)=>'<tr data-i="'+i+'"><td data-cell="'+esc(t('Client'))+'"><b>'+esc(orgNames[f.orgId]||'#'+f.orgId)+'</b></td><td data-cell="'+esc(t('Form'))+'">'+esc('#'+f.id)+'</td><td data-cell="'+esc(t('State'))+'">'+badge(f.state==='submitted'?'success':'info',f.state)+'</td><td data-cell="'+esc(t('Submitted'))+'">'+esc(f.submittedAt?new Date(f.submittedAt).toLocaleString():'—')+'</td><td class="kzc-table-actions-col"><div class="kzc-table-actions" data-i="'+i+'"></div></td></tr>').join('');
    const tw=tbl([t('Client'),t('Form'),t('State'),t('Submitted'),t('Actions')],rowsHtml);
    fc.body.appendChild(tw);
    tw.querySelectorAll('.kzc-table-actions').forEach(act=>{
      const f=forms[+act.dataset.i];
      act.appendChild(btn(t('Answers'),()=>openForm(f.id),{variant:'primary',sm:true}));
      if(f.state==='submitted')act.appendChild(btn(t('Reopen'),async()=>{await post('/api/portal/forms/'+f.id+'/reopen');vForms();},{sm:true}));
      const csv=h('<a class="kzc-btn kzc-btn--sm"></a>').firstElementChild;csv.textContent='CSV';csv.href=base+'/api/portal/forms/'+f.id+'/export.csv';act.appendChild(csv);
    });
  }
  body.appendChild(fc.card);
}
async function openForm(id){
  const seq=++VIEW_SEQ;
  const r=await get('/api/portal/forms/'+id);
  if(seq!==VIEW_SEQ)return; // superseded — never chrome() over a newer view
  if(!r.success){err(t('Not found.'));return;}
  const f=r.data.form,body=chrome('forms',t('Form')+' #'+id+' — '+f.name);
  const c=card(t('Form')+' #'+id+' — '+f.name,null);
  c.actions.appendChild(h(badge(f.state==='submitted'?'success':'info',f.state)));
  c.actions.appendChild(backBtn(t('All forms'),'forms'));
  (f.fields||[]).forEach(fd=>{
    const ans=(f.answers||[]).find(a=>a.key===fd.key);
    c.body.appendChild(h('<div class="kzc-kvrow"><span>'+esc(fd.label)+'</span><b>'+esc(ans?String(ans.value):'—')+'</b></div>'));
  });
  if(f.state==='submitted')c.body.appendChild(h('<div class="kzc-actions"></div>').firstElementChild).appendChild(btn(t('Reopen for the client'),async()=>{await post('/api/portal/forms/'+id+'/reopen');openForm(id);}));
  body.appendChild(c.card);
}

/* ── People (identities admin, US-P07) ── */
async function vPeople(){
  VIEW_SEQ++;
  const body=chrome('people',t('People'));
  if(!ME_ADMIN){body.appendChild(emptyErr(t('App admins only'),t('Identity management is restricted to KanzleiCheck app admins.')));return;}
  const c=card(t('Identities'),t('Portal sign-in identities across all clients — disable or erase on GDPR request.'));
  c.body.appendChild(h('<div class="kzc-filter-grid kzc-filter-grid--search"><div class="kzc-field"><label class="kzc-label" for="kzc-pq">'+esc(t('Search'))+'</label><input id="kzc-pq" class="kzc-input" placeholder="'+esc(t('Search e-mail or name…'))+'"></div></div>'));
  c.body.appendChild(h('<div id="kzc-plist" class="kzc-list"></div>'));
  body.appendChild(c.card);
  c.body.querySelector('#kzc-pq').oninput=e=>render(e.target.value);
  render('');
  async function render(q){
    const seq=++PQ_SEQ;
    const r=await get('/api/portal/identities?q='+encodeURIComponent(q));
    if(seq!==PQ_SEQ)return; // superseded by a newer load — never paint stale
    const list=document.getElementById('kzc-plist');if(!list)return;list.innerHTML='';
    const ids=(r.data&&r.data.identities)||[];
    if(!ids.length){list.appendChild(h('<p class="kzc-muted">'+esc(t('No identities found.'))+'</p>'));return;}
    ids.forEach(i=>{
      const row=devRow((i.displayName||'')+' <'+i.email+'>');
      if(i.status!=='active')row.querySelector('.kzc-dev__label').prepend(h(badge('neutral',i.status)));
      devAct(row,btn(i.status==='disabled'?t('Enable'):t('Disable'),async()=>{
        await put('/api/portal/identities/'+i.id+'/status',{status:i.status==='disabled'?'active':'disabled'});render(document.getElementById('kzc-pq').value);
      },{sm:true}));
      devAct(row,btn(t('Export'),async()=>{
        const rj=await get('/api/portal/identities/'+i.id+'/export');
        const blob=new Blob([JSON.stringify(rj.data??rj,null,2)],{type:'application/json'});
        const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='identity-'+i.id+'.json';a.click();
      },{sm:true}));
      devAct(row,btn(t('Erase'),async()=>{
        if(!(await confirmDlg(t('Erase person'),t('All personal data is erased permanently. Continue?'))))return;
        await del('/api/portal/identities/'+i.id);render(document.getElementById('kzc-pq').value);
      },{variant:'danger',sm:true}));
      list.appendChild(row);
    });
  }
}

const VIEWS={overview:vOverview,inbox:vInbox,board:vBoard,requests:vRequests,orgs:vOrgs,uploads:vUploads,holds:vHolds,forms:vForms,people:vPeople};
(async()=>{
  const d=await get('/api/portal/settings');ME_ADMIN=d.success===true;
  /* Sidebar ?view= links (check-shell nav): instant client-side switch while
   * on the index route; every other href (settings sections, transfer,
   * about, cross-app) navigates normally. */
  document.querySelectorAll('#app-navigation a.kzc-nav__link').forEach(a=>{
    let u=null,v=null;
    try{u=new URL(a.href,location.origin);v=u.searchParams.get('view');}catch(e){return;}
    if(v&&VIEWS[v]&&u.pathname===INDEX_PATH){
      a.addEventListener('click',e=>{e.preventDefault();history.pushState({},'',a.href);status(t('Loading…'));VIEWS[v]();});
    }
  });
  window.addEventListener('popstate',()=>{
    const v=new URLSearchParams(location.search).get('view');(VIEWS[v]||vOverview)();
  });
  const mount=document.getElementById('kzc-staff');
  const bootView=mount?(mount.dataset.kzcView||''):'';
  // Deep links (notification mails land ?view=inbox&org=N — see NotifyService).
  const p=new URLSearchParams(location.search);
  const v=p.get('view'),orgId=parseInt(p.get('org')||'',10);
  if(v==='inbox'&&orgId>0){
    const oRes=await get('/api/portal/orgs');
    let name=(oRes.data&&oRes.data.orgs||[]).find(x=>x.id===orgId)?.name;
    if(!name){
      // The orgs list is capped (200) — a notification deeplink into an
      // older org must still land: resolve the name via the inbox's own
      // conversation list (uncapped, and deeplinks always target a conv).
      const iRes=await get('/api/portal/inbox');
      name=(iRes.data&&iRes.data.conversations||[]).find(c=>c.orgId===orgId)?.orgName;
    }
    if(name){openConv(orgId,name);return;}
  }
  if(bootView==='settings'){vSettings((mount&&mount.dataset.kzcSection)||'');return;}
  (VIEWS[bootView]||VIEWS[v]||vOverview)();
})();
})();
