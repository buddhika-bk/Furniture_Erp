# Employee salary breakdown

Open **HR & Payroll → Payroll → Salary breakdown** for the relevant monthly period. Create a period and Generate first if no payslips exist. Search by employee name/code. Click Working days, Leave days or OT hours to open dated details; View calculation shows each salary component.

- Fixed basic + allowances + recorded overtime hours × employee OT rate + approved commission − configured employee deductions = net salary. This change does not introduce automatic absence deductions or proration.
- Working days are unique present/late attendance dates; unrecorded dates are not assumed absent. Check-in/out and recorded hours are shown without recalculating them.
- Leave dates combine approved requests and attendance marked leave, clipped to the period and deduplicated. Requests count calendar days, including weekends; pending/rejected requests are excluded. Overlapping work and leave remain visible as entered so HR can correct source records before generation.
- Newly generated payslips store attendance/leave and OT rate snapshots. Regenerate draft periods after corrections. Existing payslips without snapshots clearly show live attendance data while retaining saved salary amounts. Finalized payslips are not regenerated.
- Active and on-leave employees are included in generation. Historical slips remain visible for employees who later leave. Branch-scoped viewers see only their branch's employees; organization access is required to generate/advance payroll.
- Apply migration `2026_11_04_000006_payroll_attendance_snapshot.php` before deployment. No existing salary records are recalculated by the migration.
