<?php
namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    public function test_loyalty_redemption_posts_to_the_existing_ledger(): void
    {
        $headers = $this->loginAs('cashier1@erp.test');
        $customer = \App\Models\Customer::where('code','CUS-003')->first();
        $customer->update(['loyalty_points'=>100]);
        $product = Product::where('sku','FG-BS-Q')->first();
        $this->postJson('/api/v1/pos/checkout', [
            'customer_id'=>$customer->id, 'redeem_points'=>100,
            'items'=>[['product_id'=>$product->id,'qty'=>1]],
            'payments'=>[['method'=>'cash','amount'=>44.90]],
        ], $headers)->assertCreated();
        $this->assertDatabaseHas('loyalty_ledger',['customer_id'=>$customer->id,'points'=>-100,'type'=>'redeem']);
        $this->assertLessThan(100,$customer->fresh()->loyalty_points);
    }

    public function test_all_module_lists_and_management_reports_remain_available(): void
    {
        $headers = $this->loginAs();
        foreach (array_keys(config('erp.resources')) as $resource) {
            $this->getJson('/api/v1/'.$resource, $headers)->assertOk();
        }
        foreach (['sales-by-day','sales-by-branch','sales-by-product','salesperson','product-profitability','branch-profitability','profit-and-loss','stock','production','receivables','payables','expenses','marketing','delivery-costs'] as $report) {
            $this->getJson('/api/v1/reports/'.$report, $headers)->assertOk();
        }
    }

    public function test_reports_obey_branch_scope_and_validate_dates(): void
    {
        $headers = $this->loginAs('manager1@erp.test');
        $warehouse = Warehouse::where('code', 'WH-SR1')->first();
        $rows = $this->getJson('/api/reports/stock', $headers)->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) $this->assertEquals($warehouse->name, $row['warehouse']);
        $this->getJson('/api/dashboard', $headers)->assertOk()->assertJsonPath('receivables', null);
        foreach (['production', 'payables', 'profit-and-loss', 'branch-profitability'] as $report) {
            $this->getJson('/api/reports/'.$report, $headers)->assertOk();
        }
        $this->getJson('/api/reports/receivables', $headers)->assertUnprocessable();
        $this->getJson('/api/reports/stock?from=not-a-date', $headers)->assertUnprocessable();
    }

    public function test_launcher_only_returns_permitted_routes_and_branches(): void
    {
        $headers = $this->loginAs('cashier1@erp.test');
        $result = $this->getJson('/api/v1/apps', $headers)->assertOk()->assertHeader('X-Request-Id')->json();
        $this->assertCount(1, $result['branches']);
        $user = User::where('email', 'cashier1@erp.test')->first();
        foreach ($result['data'] as $app) foreach ($app['routes'] as $route) $this->assertTrue($user->hasPermission($route[2]));
        $this->assertNotContains('hr', array_column($result['data'], 'id'));
        $this->getJson('/api/v1/employees', $headers)->assertForbidden();
        $this->getJson('/api/apps', $headers + ['X-Branch-Id' => Branch::where('code','SR2')->value('id')])->assertForbidden();
    }

    public function test_preferences_are_per_user_and_cannot_change_permissions(): void
    {
        $headers = $this->loginAs();
        $this->patchJson('/api/preferences', ['pinned_apps' => ['sales'], 'solid_surfaces' => true, 'role_id' => 999], $headers)->assertOk()->assertJsonPath('data.solid_surfaces', true);
        $this->assertNotEquals(999, User::where('email','admin@erp.test')->value('role_id'));
        $this->patchJson('/api/profile', ['name'=>'Workspace Admin','permissions'=>['*']], $headers)->assertOk();
        $this->getJson('/api/auth/me',$headers)->assertJsonPath('user.name','Workspace Admin');
    }

    public function test_notifications_are_scoped_and_read_independently(): void
    {
        $headers = $this->loginAs('manager1@erp.test');
        $one = Branch::where('code','SR1')->value('id');
        $two = Branch::where('code','SR2')->value('id');
        $visible = Alert::create(['type'=>'test','message'=>'Branch one','branch_id'=>$one]);
        $hidden = Alert::create(['type'=>'test','message'=>'Branch two','branch_id'=>$two]);
        $ids = array_column($this->getJson('/api/notifications',$headers)->assertOk()->json('data'),'id');
        $this->assertContains($visible->id,$ids);$this->assertNotContains($hidden->id,$ids);
        $this->postJson('/api/notifications/read',['ids'=>[$visible->id,$hidden->id]],$headers)->assertOk();
        $this->assertDatabaseMissing('notification_reads',['alert_id'=>$hidden->id]);
        $adminToken=$this->postJson('/api/auth/login',['email'=>'admin@erp.test','password'=>'Password@123'])->json('token');
        $rows=$this->getJson('/api/notifications',['Authorization'=>'Bearer '.$adminToken])->json('data');
        $row=collect($rows)->firstWhere('id',$visible->id);$this->assertNull($row['read_at']);
    }

    public function test_retrying_checkout_commits_once_and_rejects_changed_payload(): void
    {
        $headers=$this->loginAs('cashier1@erp.test')+['Idempotency-Key'=>'checkout-test-00000001'];
        $product=Product::where('sku','FG-BS-Q')->first();
        $payload=['items'=>[['product_id'=>$product->id,'qty'=>1]],'payments'=>[['method'=>'cash','amount'=>49.90]]];
        $one=$this->postJson('/api/pos/checkout',$payload,$headers)->assertCreated()->json('data.id');
        $this->postJson('/api/pos/checkout',$payload,$headers)->assertCreated()->assertHeader('Idempotency-Replayed','true')->assertJsonPath('data.id',$one);
        $this->assertDatabaseCount('invoices',1);
        $this->assertEquals(24,StockBalance::where('product_id',$product->id)->where('warehouse_id',Warehouse::where('code','WH-SR1')->value('id'))->value('qty'));
        $payload['items'][0]['qty']=2;
        $this->postJson('/api/pos/checkout',$payload,$headers)->assertConflict();
        $this->getJson('/api/operations/checkout-test-00000001',$headers)->assertOk()->assertJsonPath('data.status','completed');
    }

    public function test_branch_filters_apply_to_ledger_and_search_and_lookups_hide_sensitive_fields(): void
    {
        $headers=$this->loginAs('manager1@erp.test');
        $branch=Branch::where('code','SR1')->value('id');
        $rows=$this->getJson('/api/stock_balances',$headers)->assertOk()->json('data');
        foreach($rows as $row)$this->assertEquals($branch,$row['warehouse']['branch_id']);
        $this->getJson('/api/stock_balances?filter[warehouse_id]='.Warehouse::where('code','WH-SR2')->value('id'),$headers)->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/products?sort=untrusted_sql',$headers)->assertUnprocessable();
        $this->getJson('/api/search?q=Bed',$headers)->assertOk();
        $rows=$this->getJson('/api/lookups/employees',$headers)->assertOk()->json('data');
        foreach($rows as $row)$this->assertArrayNotHasKey('basic_salary',$row);
    }
}
