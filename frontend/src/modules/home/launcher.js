import { workspace, savePreferences } from '../../app/state.js';
import { icon } from '../../components/icons.js';
const { esc, $, $$ } = UI;
export async function launcher(view) {
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
  view.innerHTML = `<section class="welcome"><div><div class="eyebrow">YOUR BUSINESS, CONNECTED</div><h1>${greeting}, ${esc(API.user.name.split(' ')[0])}<span>.</span></h1><p>Everything you need. One workspace.</p></div><div class="workspace-date">${new Intl.DateTimeFormat('en', { weekday:'long', month:'long', day:'numeric' }).format(new Date())}<span>${esc(API.user.branch || 'Across your organization')}</span></div></section>
    <div class="launcher-toolbar"><div class="launcher-tabs" role="group" aria-label="Application filter"><button class="selected" data-tab="all">All applications <span>${workspace.apps.length}</span></button><button data-tab="pinned">Pinned</button><button data-tab="recent">Recent</button></div><label class="launcher-search">${icon('search',18)}<input id="appSearch" type="search" placeholder="Find an application..." aria-label="Find an application"><kbd>/</kbd></label></div>
    <div class="app-grid" id="appGrid"></div><section class="workspace-bottom"><div class="workspace-note"><span class="note-icon">${icon('box',25)}</span><div><h2>A connected workflow</h2><p>Manufacturing <span>→</span> Inventory <span>→</span> Showroom <span>→</span> Finance</p></div></div><a href="#/help" class="workspace-help">New to your workspace? ${icon('arrow',18)}</a></section>`;
  let tab = 'all';
  const draw = () => {
    const q = $('#appSearch',view).value.toLowerCase().trim();
    const pins = workspace.preferences.pinned_apps || [];
    const apps = workspace.apps.filter(a => (a.name+' '+a.description).toLowerCase().includes(q) && (tab==='all' || (tab==='pinned' ? pins.includes(a.id) : workspace.recent.includes(a.id))));
    $('#appGrid',view).innerHTML = apps.length ? apps.map(a => `<article class="application-tile" style="--app-color:${a.color}"><button class="pin-button ${pins.includes(a.id)?'is-pinned':''}" data-pin="${a.id}" aria-label="${pins.includes(a.id)?'Unpin':'Pin'} ${esc(a.name)}" aria-pressed="${pins.includes(a.id)}">${icon('pin',15)}</button><a href="#/module/${a.id}"><span class="application-icon">${icon(a.icon,30)}</span><h2>${esc(a.name)}</h2><p>${esc(a.description)}</p><span class="tile-footer">${a.routes.length} workspaces ${icon('arrow',16)}</span></a></article>`).join('') : '<div class="empty-state"><h2>No applications found</h2><p>Try another search or select All applications.</p></div>';
    $$('[data-pin]',view).forEach(button => button.onclick = async () => {
      button.disabled = true;
      try { await savePreferences({pinned_apps:pins.includes(button.dataset.pin) ? pins.filter(p=>p!==button.dataset.pin) : [...pins,button.dataset.pin]}); draw(); }
      catch(error) { UI.toast(error.message,true); button.disabled=false; }
    });
  };
  $$('[data-tab]',view).forEach(b=>b.onclick=()=>{tab=b.dataset.tab;$$('[data-tab]',view).forEach(x=>x.classList.toggle('selected',x===b));draw();});
  $('#appSearch',view).oninput=draw;
  draw();
}
export function moduleHome(view, app) {
  view.innerHTML=`<section class="module-welcome" style="--app-color:${app.color}"><span class="application-icon">${icon(app.icon,32)}</span><div><div class="eyebrow">YOUR APPLICATION</div><h1>${esc(app.name)}</h1><p>${esc(app.description)}</p></div></section><div class="module-grid">${app.routes.map(([key,title])=>`<a href="#/${key}" class="module-link"><span>${icon(app.icon,22)}</span><div><h2>${esc(title)}</h2><p>Open ${esc(title.toLowerCase())}</p></div>${icon('arrow',18)}</a>`).join('')}</div>`;
}
