<?php
namespace Tests\Feature;

use App\Models\{Employee,Attendance,Leave,PayrollPeriod};
use Tests\TestCase;

class PayrollDetailsTest extends TestCase
{
    public function test_salary_details_clip_and_deduplicate_leave_and_preserve_snapshot(): void
    {
        $h = $this->loginAs();
        $e = Employee::first();
        $e->update(['status' => 'on_leave', 'basic_salary' => 50000, 'allowances' => 5000, 'ot_rate' => 200, 'deductions' => 1000]);
        $p = PayrollPeriod::create(['name' => 'Detail test', 'start_date' => '2030-02-01', 'end_date' => '2030-02-28']);
        foreach ([['2030-02-01','present',2],['2030-02-02','late',1],['2030-02-03','leave',0],['2030-02-04','absent',0],['2030-03-01','present',10]] as [$date,$status,$ot]) {
            Attendance::create(['employee_id'=>$e->id,'work_date'=>$date,'status'=>$status,'worked_hours'=>8,'overtime_hours'=>$ot]);
        }
        foreach ([['2030-01-31','2030-02-03','approved'],['2030-02-03','2030-02-05','approved'],['2030-02-10','2030-02-12','pending'],['2030-02-20','2030-02-21','rejected']] as [$from,$to,$status]) {
            Leave::create(['employee_id'=>$e->id,'from_date'=>$from,'to_date'=>$to,'status'=>$status,'type'=>'annual','reason'=>'Family']);
        }
        $this->postJson('/api/payroll-periods/'.$p->id.'/generate', [], $h)->assertOk();
        $slip = $p->payslips()->where('employee_id',$e->id)->firstOrFail();
        $this->assertEquals(3, $slip->overtime_hours);
        $this->assertEquals(54600 + (float)$slip->commission, $slip->net);
        $this->assertEquals(2, $slip->attendance_snapshot['working_days']);
        $this->assertEquals(5, $slip->attendance_snapshot['leave_days']);
        $this->assertCount(2, $slip->attendance_snapshot['overtime_dates']);
        Attendance::where('employee_id',$e->id)->where('work_date','2030-02-01')->update(['status'=>'absent']);
        $result=$this->getJson('/api/payroll-periods/'.$p->id,$h)->assertOk()->json('data.payslips');
        $saved=collect($result)->firstWhere('employee_id',$e->id);
        $this->assertEquals('snapshot',$saved['attendance_source']);
        $this->assertEquals(2,$saved['attendance_details']['working_days']);
        $slip->update(['attendance_snapshot'=>null]);
        $result=$this->getJson('/api/payroll-periods/'.$p->id,$h)->assertOk()->json('data.payslips');
        $legacy=collect($result)->firstWhere('employee_id',$e->id);
        $this->assertEquals('live',$legacy['attendance_source']);
        $this->assertEquals(1,$legacy['attendance_details']['working_days']);
        $this->assertEquals($saved['net'],$legacy['net']);
        $branch = \App\Models\Branch::where('is_active', true)->where('id', '!=', $e->branch_id)->firstOrFail();
        $scoped = $this->getJson('/api/payroll-periods/'.$p->id, $h + ['X-Branch-Id' => (string)$branch->id])->assertOk()->json('data.payslips');
        $this->assertNull(collect($scoped)->firstWhere('employee_id', $e->id));
        $p->update(['status'=>'finalized']);
        $this->postJson('/api/payroll-periods/'.$p->id.'/generate', [], $h)->assertStatus(422);
    }
}
