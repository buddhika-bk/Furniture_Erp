import { $, $$, esc, fmt, badge, modal, table, unwrap } from '../../components/context.js';

export async function payrollView(r) {
  const p = unwrap(await API.get('/payroll-periods/' + r.id));
  const slips = p.payslips || [];
  const m = modal('Monthly salary breakdown — ' + p.name, `
    <div class="flex gap-3 mb-4 flex-wrap">${badge(p.status)}<span>${esc(p.start_date)} — ${esc(p.end_date)}</span></div>
    <p class="section-description">Net salary = Basic + Allowances + OT pay + Commission − Deductions. Attendance is informational; leave does not automatically reduce salary. Leave counts include calendar dates in approved requests and attendance marked leave, counted once per date.</p>
    <label class="block mb-4"><span class="lbl">Find employee</span><input class="inp" data-search placeholder="Employee name or code" type="search"></label>
    <div data-salaries></div>`, { wide: true });
  const daysButton = (s, kind, label) => `<button class="btn btn-ghost" data-slip="${s.id}" data-kind="${kind}" aria-label="${esc(label + ' for ' + s.employee?.name)}">${esc(label)}</button>`;
  const render = () => {
    const query = $('[data-search]', m).value.trim().toLowerCase();
    $('[data-salaries]', m).innerHTML = table([
      { label: 'Employee', get: s => s.employee?.name },
      { label: 'Working days', html: s => daysButton(s, 'working', String(s.attendance_details.working_days)) },
      { label: 'Leave days', html: s => daysButton(s, 'leave', String(s.attendance_details.leave_days)) },
      { label: 'OT hours', html: s => daysButton(s, 'overtime', fmt.num(s.overtime_hours)) },
      ...[['basic', 'Basic'], ['allowances', 'Allowances'], ['overtime_amount', 'OT pay'], ['commission', 'Commission'], ['gross', 'Gross'], ['deductions', 'Deductions'], ['net', 'Net salary']].map(([key, label]) => ({ label, num: true, get: s => fmt.money(s[key]) })),
      { label: 'Details', html: s => daysButton(s, 'summary', 'View calculation') }
    ], slips.filter(s => `${s.employee?.name || ''} ${s.employee?.code || ''}`.toLowerCase().includes(query)), slips.length ? 'No matching employees.' : 'No payslips yet. Close this window and choose Generate for this period.');
    $$('[data-slip]', m).forEach(b => b.onclick = () => showDetails(slips.find(s => String(s.id) === b.dataset.slip), b.dataset.kind, p));
  };
  $('[data-search]', m).oninput = render;
  render();
}

function showDetails(s, kind, period) {
  const a = s.attendance_details;
  const titles = { working: 'Working dates', leave: 'Leave dates', overtime: 'Overtime dates', summary: 'Salary calculation' };
  const source = s.attendance_source === 'snapshot' ? `Saved with payroll generation (${fmt.dt(a.captured_at)}).` : 'Historical attendance snapshot unavailable: dates below use current attendance / leave records. Saved salary amounts remain unchanged.';
  let content;
  if (kind === 'working') content = table([
    { label: 'Date', get: d => d.work_date }, { label: 'Status', get: d => d.status },
    { label: 'Check in', get: d => d.check_in || '—' }, { label: 'Check out', get: d => d.check_out || '—' },
    { label: 'Worked hours', get: d => fmt.num(d.worked_hours) }, { label: 'OT hours', get: d => fmt.num(d.overtime_hours) }
  ], a.working_dates, 'No present or late attendance recorded in this period.');
  else if (kind === 'leave') content = table([
    { label: 'Date', get: d => d.date }, { label: 'Leave type', get: d => d.type },
    { label: 'Reason', get: d => d.reason || '—' }, { label: 'Source', get: d => d.source }
  ], a.leave_dates, 'No approved leave or attendance marked leave in this period.');
  else if (kind === 'overtime') content = table([
    { label: 'Date', get: d => d.work_date }, { label: 'Attendance status', get: d => d.status }, { label: 'OT hours', get: d => fmt.num(d.overtime_hours) }
  ], a.overtime_dates, 'No overtime recorded in this period.');
  else content = `<p class="section-description">${fmt.num(a.working_days)} working days · ${fmt.num(a.worked_hours)} worked hours · ${fmt.num(a.leave_days)} leave days · ${a.absent_dates.length} recorded absent days</p>` + table([
    { label: 'Salary component', get: d => d.label }, { label: 'Amount', num: true, get: d => fmt.money(d.value) }
  ], [
    { label: 'Basic salary (fixed for the period)', value: s.basic }, { label: 'Allowances', value: s.allowances },
    { label: `OT: ${fmt.num(s.overtime_hours)} hours × ${a.ot_rate != null ? fmt.money(a.ot_rate) : (Number(s.overtime_hours) ? fmt.money(Number(s.overtime_amount) / Number(s.overtime_hours)) + ' (derived)' : 'rate unavailable')}`, value: s.overtime_amount },
    { label: 'Commission', value: s.commission }, { label: 'Gross salary', value: s.gross },
    { label: 'Less: configured deductions', value: s.deductions }, { label: 'Net salary payable', value: s.net }
  ]) + '<p class="section-description">Missing attendance is not assumed to be absence. Working days count present/late records. Pending and rejected leave requests are excluded. No automatic leave deductions or salary proration are applied.</p>';
  modal(`${titles[kind]} — ${s.employee?.name || 'Employee'}`, `<p class="section-description">${esc(period.start_date)} — ${esc(period.end_date)}<br>${esc(source)}</p>${content}`, { wide: true });
}
