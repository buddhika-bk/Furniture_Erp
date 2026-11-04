import { mountClock } from './components/clock.js';
import { mountMotion } from './components/motion.js';
import { workspace, allowedRoutes } from './app/state.js';
import { icon } from './components/icons.js';
import { launcher, moduleHome } from './modules/home/launcher.js';
const { $, $$, esc, modal, toast } = UI;
mountMotion();
mountClock(document.getElementById('workspaceClock'));
let generation=0, notificationTimer, dirty=false, lastHash=location.hash;
const loaders = {
  new_invoice: () => import('./modules/sales/documents.js').then(m=>m.newInvoice),
  new_quotation: () => import('./modules/sales/documents.js').then(m=>m.newQuotation),
  manufacturing_requests: () => import('./modules/sales/documents.js').then(m=>m.manufacturingRequests),
  dashboard: () => import('./modules/home/pages.js').then(m=>m.dashboard),
  inventory: () => import('./modules/inventory/pages.js').then(m=>m.inventory),
  reports: () => import('./modules/reports/pages.js').then(m=>m.reports),
  settings: () => import('./modules/admin/pages.js').then(m=>m.settings),
  pos: () => import('./modules/sales/pages.js').then(m=>m.pos),
  profile: () => import('./modules/admin/profile.js').then(m=>m.profile),
};
export const loading = () => '<div class="loading-state" role="status" aria-label="Loading"><div></div><div></div><div></div></div>';
function errorState(view,error) {
  view.innerHTML=`<section class="empty-state" role="alert">${icon('help',32)}<h2>${error.status===403?'Access unavailable':'We could not load this page'}</h2><p>${esc(error.message)}</p><div><button class="btn btn-brand" id="retryPage">Try again</button> <a class="btn btn-ghost" href="#/home">Back to applications</a></div></section>`;
  $('#retryPage',view).onclick=route;
}
function nav(app,key) {
  $('#nav').innerHTML=`<a class="nav-link ${key==='home'?'active':''}" href="#/home">${icon('grid')}<span>Applications</span></a>${API.can('dashboard.view')?`<a class="nav-link ${key==='dashboard'?'active':''}" href="#/dashboard">${icon('chart')}<span>Overview</span></a>`:''}<div class="nav-separator"></div><div class="nav-group-title">${app?esc(app.name):'YOUR WORKSPACE'}</div>${app?app.routes.map(([k,t])=>`<a class="nav-link ${key===k?'active':''}" href="#/${k}" ${key===k?'aria-current="page"':''}><span class="nav-dot"></span><span>${esc(t)}</span></a>`).join(''):workspace.apps.slice(0,6).map(a=>`<a class="nav-link" href="#/module/${a.id}">${icon(a.icon)}<span>${esc(a.name)}</span></a>`).join('')}<div class="nav-separator"></div><a href="#/help" class="nav-link">${icon('help')}<span>Help and shortcuts</span></a>`;
}
function closeNav(){document.body.classList.remove('nav-open');$('#openNav').setAttribute('aria-expanded','false');}
async function route() {
  if(!API.user)return;
  if(dirty && location.hash !== lastHash) {
    if(!(await UI.confirmBox('Discard your unsaved changes?','Leave page'))) {
      history.replaceState(null,'',location.pathname+location.search+lastHash);return;
    }
  }
  lastHash=location.hash;
  const id=++generation;
  const key=(location.hash.replace(/^#\//,'')||'home').split('?')[0];
  const record=allowedRoutes().find(r=>r.key===key);
  const app=key.startsWith('module/')?workspace.apps.find(a=>a.id===key.split('/')[1]):record?.app;
  const old=$('#view'),view=old.cloneNode(false);old.replaceWith(view);view.dataset.page=key;
  closeNav();dirty=false;nav(app,key);workspace.activeApp=app;
  $('#pageTitle').textContent=record?.title||app?.name||({home:'Workspace',profile:'Your profile',help:'Help and shortcuts'}[key]||'Page unavailable');
  $('#breadcrumbs').innerHTML=`<a href="#/home">Workspace</a>${app?`<span>/</span><a href="#/module/${app.id}">${esc(app.name)}</a>`:''}${record?`<span>/</span><span>${esc(record.title)}</span>`:''}`;
  document.title=$('#pageTitle').textContent+' · Webpoint One';
  if(app){workspace.recent=[app.id,...workspace.recent.filter(x=>x!==app.id)].slice(0,8);sessionStorage.setItem('erp_recent_'+API.user.id,JSON.stringify(workspace.recent));}
  view.innerHTML=loading();
  try {
    if(key==='home')await launcher(view);
    else if(key.startsWith('module/')&&app)moduleHome(view,app);
    else if(key==='help') view.innerHTML='<section class="card card-pad help-content"><h1>Make yourself at home</h1><p>Choose an application, then use its sidebar to move between records and workflows. The four-square button always returns to your application launcher.</p><h2>Keyboard shortcuts</h2><p><kbd>Ctrl / ⌘ K</kbd> Search workspaces · <kbd>/</kbd> Find an application · <kbd>Esc</kbd> Close a dialog or menu</p><h2>Working with records</h2><p>Use search and filters to narrow a list. Save draft changes before switching branches. Posted stock, invoices and payroll use approved returns or reversals rather than direct editing.</p><h2>Need access?</h2><p>Your administrator manages application and branch permissions. Profile preferences change your display only.</p></section>';
    else if(key==='profile'||record){
      if(loaders[key]) {const render=await loaders[key]();if(id!==generation)return;await render(view);}
      else if(SCHEMA.RES[key]){const m=await import('./modules/shared/pages.js');if(id!==generation)return;await m.listPage(key,view);}
      else throw new Error('This workspace is not configured.');
    }else throw Object.assign(new Error('This page does not exist or is not included in your permissions.'),{status:403});
  }catch(error){if(id===generation)errorState(view,error);}
}
function userDisplay(){ $('#whoName').textContent=API.user.name;$('#whoRole').textContent=API.user.role;$('#whoInitial').textContent=API.user.name.trim().slice(0,1).toUpperCase();$('#profileInitial').textContent=API.user.name.trim().slice(0,1).toUpperCase(); }
async function refreshNotifications(){
  if(!API.user||!API.can('dashboard.view'))return;
  try{const r=await API.get('/notifications');workspace.notifications=r.data;$('#bellDot').hidden=!r.unread_count;$('#bell').setAttribute('aria-label',`Notifications, ${r.unread_count} unread`);}catch{ /* Next poll retries; opening the center exposes errors. */ }
}
async function showNotifications(){
  const m=modal('Notifications',loading());
  try { const r=await API.get('/notifications');
    m.body.innerHTML=r.data.length?`<p class="section-description">${r.unread_count} unread notifications</p><ul class="notification-list">${r.data.map(a=>`<li class="${a.read_at?'':'unread'}">${UI.badge(a.level)}<div><p>${esc(a.message)}</p><small>${esc(UI.fmt.dt(a.created_at))}</small></div></li>`).join('')}</ul><button class="btn btn-brand mt-4" id="markRead">Mark displayed as read</button>`:'<div class="empty-state"><h2>You’re all caught up</h2><p>Operational updates and approval reminders will appear here.</p></div>';
    const button=$('#markRead',m);if(button)button.onclick=async()=>{button.disabled=true;try{await API.post('/notifications/read',{ids:r.data.map(a=>a.id)});m.close();await refreshNotifications();}catch(e){toast(e.message,true);button.disabled=false;}};
  }catch(error){m.body.innerHTML=`<p role="alert">${esc(error.message)}</p>`;}
}
function search(){
  const m=modal('Find a workspace',`<label class="lbl" for="globalQuery">Search applications, pages and records</label><input class="inp" id="globalQuery" type="search" placeholder="Try invoices, stock or payroll"><div id="searchResults" class="search-results"></div><div id="recordResults" class="search-results" aria-live="polite"></div>`);
  const input=$('#globalQuery',m),results=$('#searchResults',m);
  const draw=()=>{const q=input.value.trim().toLowerCase();const rows=allowedRoutes().filter(r=>(r.title+' '+r.app.name).toLowerCase().includes(q)).slice(0,15);results.innerHTML=rows.map(r=>`<a href="#/${r.key}">${icon(r.app.icon)}<span>${esc(r.title)}<small>${esc(r.app.name)}</small>${icon('arrow',16)}</a>`).join('')||'<p>No matching workspaces.</p>';$$('a',results).forEach(a=>a.onclick=m.close);};
  let timer,sequence=0;
  input.oninput=()=>{draw();clearTimeout(timer);const token=++sequence;const q=input.value.trim();$('#recordResults',m).replaceChildren();if(q.length<2)return;timer=setTimeout(async()=>{try{const r=await API.get('/search',{q});if(token!==sequence||!m.isConnected)return;$('#recordResults',m).innerHTML='<div class="eyebrow">RECORDS</div>'+r.data.map(row=>`<a href="#/${row.resource}?search=${encodeURIComponent(row.title)}"><span>${esc(row.title)}<small>${esc(row.subtitle)}</small></span>${icon('arrow',16)}</a>`).join('');$$('a',$('#recordResults',m)).forEach(a=>a.onclick=m.close);}catch(error){if(token===sequence&&m.isConnected)$('#recordResults',m).textContent=error.message;}},300);};draw();input.focus();
}
async function enter(){
  const config=await API.get('/apps');workspace.apps=config.data;workspace.branches=config.branches;workspace.preferences=config.preferences;
  if(config.currency)window.ERP_CONFIG.CURRENCY=config.currency+' ';
  try{workspace.recent=JSON.parse(sessionStorage.getItem('erp_recent_'+API.user.id)||'[]');}catch{workspace.recent=[];}
  document.documentElement.classList.toggle('solid-surfaces',!!workspace.preferences.solid_surfaces);
  document.documentElement.classList.toggle('compact-sidebar',!!workspace.preferences.compact_sidebar);
  $('#login').classList.add('hidden');$('#app').classList.remove('hidden');userDisplay();
  $('#branchSelect').innerHTML=(API.user.all_branches?'<option value="">All branches</option>':'')+workspace.branches.map(b=>`<option value="${b.id}">${esc(b.name)}</option>`).join('');
  if(API.branch&&!workspace.branches.some(b=>String(b.id)===API.branch))API.branch='';
  if(!API.user.all_branches)API.branch=API.user.branch_id||'';
  $('#branchSelect').value=API.branch;$('#branchSelect').disabled=!API.user.all_branches;
  $('#bell').hidden=!API.can('dashboard.view');
  clearInterval(notificationTimer);notificationTimer=setInterval(refreshNotifications,60000);refreshNotifications();await route();
}
function leave(message=''){generation++;clearInterval(notificationTimer);$('#app').classList.add('hidden');$('#login').classList.remove('hidden');$('#modalRoot').replaceChildren();$('#loginError').textContent=message;$('#loginError').classList.toggle('hidden',!message);}
$$('[data-icon]').forEach(el=>el.innerHTML=icon(el.dataset.icon));
$('#loginForm').onsubmit=async e=>{e.preventDefault();const button=e.target.querySelector('button[type=submit]');button.disabled=true;$('#loginError').classList.add('hidden');try{const base=$('#apiBase').value.trim();if(base)API.base=base;API.branch='';const r=await API.post('/auth/login',{email:e.target.elements.email.value,password:e.target.elements.password.value});API.token=r.token;API.setUser(r.user);await enter();e.target.elements.password.value='';}catch(error){$('#loginError').textContent=error.message;$('#loginError').classList.remove('hidden');}finally{button.disabled=false;}};
$('#logout').onclick=async()=>{if(dirty&&!(await UI.confirmBox('Leave without saving your changes?','Sign out')))return;try{await API.post('/auth/logout');}catch{}API.clear();leave();};
$('#openNav').onclick=()=>{document.body.classList.add('nav-open');$('#openNav').setAttribute('aria-expanded','true');$('#closeNav').focus();};
$('#closeNav').onclick=()=>{closeNav();$('#openNav').focus();};$('#scrim').onclick=closeNav;
$('#globalSearch').onclick=search;$('#bell').onclick=showNotifications;
$('#branchSelect').onchange=async e=>{if(dirty&&!(await UI.confirmBox('Discard unsaved work and change branch?','Change branch'))){e.target.value=API.branch;return;}API.branch=e.target.value;UI.clearOptCache();await route();refreshNotifications();};
$('#apiBase').value=localStorage.getItem('erp_api_base')||'';
window.addEventListener('hashchange',route);
window.addEventListener('session-expired',()=>leave('Your session ended. Sign in to continue.'));
window.addEventListener('profile-updated',userDisplay);
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
document.addEventListener('input',e=>{if(e.target.closest('#view form,#modalRoot form'))dirty=true;});
document.addEventListener('workspace-saved',()=>dirty=false);
document.addEventListener('workspace-dirty',()=>dirty=true);
document.addEventListener('click',async e=>{const a=e.target.closest('a[href^="#/"]');if(a&&dirty){e.preventDefault();if(await UI.confirmBox('Discard your unsaved changes?','Leave page')){dirty=false;location.hash=a.getAttribute('href');}}});
document.addEventListener('keydown',e=>{if(!API.user)return;if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();search();}if(e.key==='/'&&!e.target.matches('input,textarea,select')){e.preventDefault();$('#appSearch')?.focus();}if(e.key==='Escape')closeNav();});
if(API.token){try{const r=await API.get('/auth/me');API.setUser(r.user);await enter();}catch(error){leave(error.message);}}
