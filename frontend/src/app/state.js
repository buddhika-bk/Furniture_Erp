export const workspace = { apps: [], branches: [], preferences: {}, activeApp: null, recent: [] };
export const allowedRoutes = () => workspace.apps.flatMap(app => app.routes.map(route => ({ key: route[0], title: route[1], permission: route[2], app })));
export function savePreferences(patch) {
  return API.patch('/preferences', patch).then(result => { workspace.preferences = result.data; document.documentElement.classList.toggle('solid-surfaces', !!result.data.solid_surfaces); return result.data; });
}
