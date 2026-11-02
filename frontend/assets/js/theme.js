/* Apply before styles paint; keep the preference local to this browser. */
(function () {
  let saved;
  try { saved = localStorage.getItem('webpoint_theme'); } catch {}
  const system = matchMedia('(prefers-color-scheme: dark)');
  let choice = ['light','dark'].includes(saved) ? saved : null;
  const icons = {
    light:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>',
    dark:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.5 13.2A8.7 8.7 0 0 1 10.8 3.5 8.7 8.7 0 1 0 20.5 13.2Z"/></svg>'
  };
  function apply(mode) {
    document.documentElement.dataset.theme=mode;
    document.documentElement.style.colorScheme=mode;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content',mode==='dark'?'#080f20':'#edf2fa');
    document.querySelectorAll('[data-theme-toggle]').forEach(button=>{
      const next=mode==='dark'?'light':'dark';
      button.innerHTML=icons[next]+`<span>${next==='dark'?'Dark mode':'Light mode'}</span>`;
      button.setAttribute('aria-label',`Switch to ${next} mode`);
      button.setAttribute('title',`Switch to ${next} mode`);
      button.setAttribute('aria-pressed',String(mode==='dark'));
    });
  }
  apply(choice || (system.matches?'dark':'light'));
  document.addEventListener('DOMContentLoaded',()=>{
    apply(document.documentElement.dataset.theme);
    document.querySelectorAll('[data-theme-toggle]').forEach(button=>button.addEventListener('click',()=>{
      choice=document.documentElement.dataset.theme==='dark'?'light':'dark';
      try { localStorage.setItem('webpoint_theme',choice); } catch {}
      apply(choice);
    }));
  });
  system.addEventListener('change',e=>{if(!choice)apply(e.matches?'dark':'light');});
  window.addEventListener('storage',e=>{if(e.key==='webpoint_theme'){choice=['light','dark'].includes(e.newValue)?e.newValue:null;apply(choice||(system.matches?'dark':'light'));}});
})();
