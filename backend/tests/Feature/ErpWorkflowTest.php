<?php
namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Tests\TestCase;

class ErpWorkflowTest extends TestCase
{
    public function test_production_consumes_materials_and_creates_finished_goods(): void
    {
        $h = $this->loginAs();
        $bomId = $this->getJson('/api/boms', $h)->json('data.0.id');
        $raw = Warehouse::where('code', 'FAC-RAW')->first(); $fg = Warehouse::where('code', 'FAC-FG')->first();
        $id = $this->postJson('/api/production-orders', ['bom_id' => $bomId, 'batches' => 2, 'source_warehouse_id' => $raw->id, 'target_warehouse_id' => $fg->id], $h)->assertCreated()->json('data.id');
        $this->postJson("/api/production-orders/$id/release", [], $h)->assertOk();
        $this->postJson("/api/production-orders/$id/start", [], $h)->assertOk();
        $this->postJson("/api/production-orders/$id/complete", ['qty_produced' => 20], $h)->assertOk();
        $cotton = Product::where('sku', 'RM-COT-01')->first();
        $this->assertEquals(2000 - 126, (float) StockBalance::where('product_id', $cotton->id)->where('warehouse_id', $raw->id)->value('qty')); // 60m x 1.05 x 2 batches
        $this->assertEquals(20, (float) StockBalance::where('warehouse_id', $fg->id)->sum('qty'));
        $this->postJson("/api/production-orders/$id/start", [], $h)->assertStatus(422);   // invalid transition
    }

    public function test_sale_reduces_branch_stock_and_cannot_oversell(): void
    {
        $h = $this->loginAs('cashier1@erp.test');
        $q = Product::where('sku', 'FG-BS-Q')->first();
        $res = $this->postJson('/api/pos/checkout', ['items' => [['product_id' => $q->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 99.80]]], $h)->assertCreated();
        $this->assertEquals(23, (float) StockBalance::where('product_id', $q->id)->where('warehouse_id', Warehouse::where('code', 'WH-SR1')->value('id'))->value('qty'));
        $this->postJson('/api/pos/checkout', ['items' => [['product_id' => $q->id, 'qty' => 500]], 'payments' => [['method' => 'cash', 'amount' => 24950]]], $h)->assertStatus(422);
        $this->postJson('/api/invoices/' . $res->json('data.id') . '/return', ['refund_method' => 'cash', 'items' => [['product_id' => $q->id, 'qty' => 1]]], $h)->assertCreated();
        $this->assertEquals(24, (float) StockBalance::where('product_id', $q->id)->where('warehouse_id', Warehouse::where('code', 'WH-SR1')->value('id'))->value('qty'));
    }

    public function test_credit_limit_and_permissions_are_enforced(): void
    {
        $h = $this->loginAs('cashier1@erp.test');
        $q = Product::where('sku', 'FG-BS-Q')->first();
        $c = Customer::where('code', 'CUS-003')->first();      // credit limit 500
        $this->postJson('/api/pos/checkout', ['customer_id' => $c->id, 'items' => [['product_id' => $q->id, 'qty' => 12]], 'payments' => [['method' => 'credit', 'amount' => 598.80]]], $h)->assertStatus(422);
        $this->getJson('/api/audit_logs', $h)->assertStatus(403);
        $this->getJson('/api/users', $h)->assertStatus(403);
    }

    public function test_payroll_lifecycle(): void
    {
        $h = $this->loginAs('hr@erp.test');
        $id = $this->postJson('/api/payroll-periods', ['name' => 'Oct 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'], $h)->assertCreated()->json('data.id');
        $this->postJson("/api/payroll-periods/$id/generate", [], $h)->assertOk();
        foreach (['reviewed', 'approved', 'finalized'] as $s) $this->postJson("/api/payroll-periods/$id/advance", [], $h)->assertOk()->assertJsonPath('data.status', $s);
        $this->postJson("/api/payroll-periods/$id/generate", [], $h)->assertStatus(422);
    }
}
