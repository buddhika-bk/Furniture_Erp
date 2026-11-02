import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../../components/context.js";
import { H, receiptHtml } from "../../services/workflows.js";
  async function settings(view) {
    const { data } = await API.get('/settings');
    view.innerHTML = `<form class="card card-pad max-w-3xl space-y-4"><p class="text-sm text-slate-400">These values drive calculations such as loyalty earning, overtime and credit rules. Changes apply to new transactions only.</p>${data.map((s) => `<label class="block"><span class="lbl">${esc(s.description || fmt.label(s.key))}</span><input class="inp" name="${esc(s.key)}" value="${esc(s.value)}" ${can('settings.edit') ? '' : 'disabled'}><span class="text-xs text-slate-500">${esc(s.key)}</span></label>`).join('')}${can('settings.edit') ? '<button class="btn btn-brand">Save settings</button>' : ''}</form>`;
    $('form', view).onsubmit = async (e) => { e.preventDefault(); const o = {}; $$('input', e.target).forEach((i) => (o[i.name] = i.value)); if (await act('put', '/settings', { settings: o }, 'Settings saved.')) UI.clearOptCache(); };
  }

export { settings };
