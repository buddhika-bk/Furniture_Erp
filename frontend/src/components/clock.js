const ZONE = 'Asia/Colombo';
let offset = 0;
export const workspaceNow = () => new Date(Date.now() + offset);
export function sriLankaDate(date = workspaceNow()) {
  const parts = new Intl.DateTimeFormat('en-GB', {timeZone:ZONE, year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(date);
  const get = type => parts.find(p => p.type === type).value;
  return `${get('year')}-${get('month')}-${get('day')}`;
}
export function clockText(date = workspaceNow()) {
  return new Intl.DateTimeFormat('en-GB', {timeZone:ZONE,hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).format(date);
}
export function mountClock(element) {
  if (!element) return;
  let syncing = false;
  const tick = () => {
    const now = workspaceNow();
    element.querySelector('time').textContent = clockText(now);
    element.querySelector('time').dateTime = now.toISOString();
    element.querySelector('[data-clock-date]').textContent = sriLankaDate(now) + ' · Sri Lanka';
  };
  const sync = async () => {
    if (syncing) return;
    syncing = true;
    const start = Date.now();
    try {
      const response = await fetch(`${API.base}/ping`, {cache:'no-store',signal:AbortSignal.timeout(5000)});
      if (!response.ok) return;
      const data = await response.json(), server = Date.parse(data.server_time);
      if (Number.isFinite(server)) { offset = server - (start + Date.now()) / 2; element.title = 'Sri Lanka time · Asia/Colombo · synchronized with server'; tick(); }
    } catch { element.title = 'Sri Lanka time · device clock (server unavailable)'; }
    finally { syncing = false; }
  };
  tick(); sync();
  const timer = setInterval(tick, 1000), syncTimer = setInterval(sync, 60000);
  const resume = () => { if (!document.hidden) {tick();sync();} };
  document.addEventListener('visibilitychange', resume);
  return () => { clearInterval(timer);clearInterval(syncTimer);document.removeEventListener('visibilitychange',resume); };
}
