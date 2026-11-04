<?php
namespace Tests\Feature;
use App\Models\{CashierSession, Invoice, Payment, Product, User};
use Tests\TestCase;

class CashierClosingTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp(); $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['app.timezone'=>'UTC']);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 06:00:00','UTC'));
    }
    public function test_closing_snapshots_cashier_daily_activity_and_downloads_pdf(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $sid=$this->postJson('/api/cashier-sessions/open',['opening_cash'=>100],$h)->assertCreated()->json('data.id');
        $this->get('/api/cashier-sessions/'.$sid.'/report.pdf',$h)->assertStatus(422);
        $p=Product::where('sku','FG-BS-Q')->first();
        $sale=$this->postJson('/api/pos/checkout',['items'=>[['product_id'=>$p->id,'qty'=>2]],'payments'=>[['method'=>'cash','amount'=>49.8],['method'=>'card','amount'=>50]]],$h)->assertCreated()->json('data');
        $order=$this->postJson('/api/sales/invoices',['customer_name'=>'Daily report customer','customer_id'=>\App\Models\Customer::where('is_walk_in',false)->first()->id,'items'=>[['product_id'=>$p->id,'qty'=>10]],'payments'=>[['method'=>'bank','amount'=>20],['method'=>'koko','amount'=>30],['method'=>'other','amount'=>40]]],$h)->assertCreated()->json('data');
        // Same cashier before the Colombo midnight boundary, plus another user's transaction, must be excluded.
        $user=User::where('email','cashier1@erp.test')->first();
        Payment::create(['branch_id'=>$user->branch_id,'user_id'=>$user->id,'method'=>'cash','amount'=>999,'direction'=>'receipt','created_at'=>'2026-11-03 18:29:59']);
        Payment::create(['branch_id'=>$user->branch_id,'user_id'=>User::where('email','manager1@erp.test')->value('id'),'method'=>'cash','amount'=>888,'direction'=>'receipt']);
        $res=$this->postJson("/api/cashier-sessions/$sid/close",['closing_cash'=>145,'notes'=>'Count checked by cashier.'],$h)->assertOk()->assertJsonPath('data.report_available',true);
        $report=CashierSession::find($sid)->closing_report;
        $this->assertEquals('2026-11-04',$report['date']);$this->assertEquals($user->name,$report['cashier']);
        $this->assertCount(2,$report['invoices']);$this->assertCount(5,$report['payments']);
        $this->assertEquals(189.8,$report['net_collected']);$this->assertEquals(149.8,$report['expected_cash']);$this->assertEquals(-4.8,$report['variance']);
        $methods=collect($report['methods'])->keyBy('method');
        foreach(['cash'=>49.8,'card'=>50,'bank'=>20,'koko'=>30,'other'=>40] as $method=>$amount)$this->assertEquals($amount,$methods[$method]['receipts']);
        $pdf=$this->get("/api/cashier-sessions/$sid/report.pdf",$h)->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-',$pdf->getContent());
        if(getenv('DOCUMENT_QA_OUTPUT'))file_put_contents(getenv('DOCUMENT_QA_OUTPUT').'/daily-closing.pdf',$pdf->getContent());
        $this->postJson('/api/invoices/'.$order['id'].'/payments',['method'=>'cash','amount'=>10],$h)->assertOk();
        $this->assertEquals($report,CashierSession::find($sid)->closing_report);
        $this->postJson("/api/cashier-sessions/$sid/close",['closing_cash'=>145],$h)->assertOk();
        $this->postJson("/api/cashier-sessions/$sid/close",['closing_cash'=>150],$h)->assertStatus(422);
        $other=$this->postJson('/api/auth/login',['email'=>'cashier2@erp.test','password'=>'Password@123'])->json('token');
        $this->get("/api/cashier-sessions/$sid/report.pdf",['Authorization'=>'Bearer '.$other])->assertNotFound();
        $sameBranch=User::where('email','cashier2@erp.test')->first();$sameBranch->update(['branch_id'=>$user->branch_id]);
        $this->get("/api/cashier-sessions/$sid/report.pdf",['Authorization'=>'Bearer '.$other])->assertForbidden();
        $manager=$this->postJson('/api/auth/login',['email'=>'manager1@erp.test','password'=>'Password@123'])->json('token');
        $this->get("/api/cashier-sessions/$sid/report.pdf",['Authorization'=>'Bearer '.$manager])->assertOk();
    }
    public function test_cash_refunds_and_credit_collections_reconcile_the_open_session(): void {
        $h=$this->loginAs('manager1@erp.test');
        $sid=$this->postJson('/api/cashier-sessions/open',['opening_cash'=>100],$h)->json('data.id');
        $product=Product::where('sku','FG-BS-Q')->first();
        $id=$this->postJson('/api/pos/checkout',['items'=>[['product_id'=>$product->id,'qty'=>2]],'payments'=>[['method'=>'cash','amount'=>99.8]]],$h)->assertCreated()->json('data.id');
        $this->postJson("/api/invoices/$id/return",['items'=>[['product_id'=>$product->id,'qty'=>1]],'refund_method'=>'cash','reason'=>'Returned one sheet'],$h)->assertCreated();
        $customer=\App\Models\Customer::first();$customer->update(['credit_balance'=>100]);
        $this->postJson('/api/customers/'.$customer->id.'/payments',['method'=>'cash','amount'=>25],$h)->assertCreated();
        $this->postJson("/api/cashier-sessions/$sid/close",['closing_cash'=>174.9],$h)->assertOk();
        $r=CashierSession::find($sid)->closing_report;
        $this->assertEquals(174.9,$r['expected_cash']);$this->assertEquals(0,$r['variance']);
        $this->assertCount(1,$r['returns']);$this->assertCount(3,$r['payments']);
        $cash=collect($r['methods'])->firstWhere('method','cash');
        $this->assertEquals(124.8,$cash['receipts']);$this->assertEquals(49.9,$cash['refunds']);$this->assertEquals(74.9,$cash['net']);
    }
    public function test_empty_closing_and_multiple_sessions_keep_daily_totals_separate_from_drawer(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $sid=$this->postJson('/api/cashier-sessions/open',['opening_cash'=>0],$h)->json('data.id');
        $this->postJson("/api/cashier-sessions/$sid/close",['closing_cash'=>0],$h)->assertOk();
        $this->get("/api/cashier-sessions/$sid/report.pdf",$h)->assertOk();
        $user=User::where('email','cashier1@erp.test')->first();
        Payment::create(['user_id'=>$user->id,'branch_id'=>$user->branch_id,'method'=>'bank','amount'=>50,'direction'=>'receipt','created_at'=>'2026-11-03 18:30:00']);
        $second=$this->postJson('/api/cashier-sessions/open',['opening_cash'=>20],$h)->json('data.id');
        $this->postJson("/api/cashier-sessions/$second/close",['closing_cash'=>20],$h)->assertOk();
        $r=CashierSession::find($second)->closing_report;
        $this->assertEquals(50,$r['net_collected']);$this->assertEquals(20,$r['expected_cash']);$this->assertEquals(0,$r['variance']);
        $this->assertCount(0,CashierSession::find($sid)->closing_report['payments']);
    }
}
