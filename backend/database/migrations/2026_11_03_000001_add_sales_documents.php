<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('invoices', function (Blueprint $t) {
            $t->boolean('is_order')->default(false);
            $t->boolean('is_special')->default(false);
            $t->text('notes')->nullable();
            $t->date('due_date')->nullable();
            $t->decimal('balance_due', 14, 2)->default(0);
            $t->json('customer_snapshot')->nullable();
            $t->json('shop_snapshot')->nullable();
        });
        Schema::table('invoice_items', function (Blueprint $t) {
            $t->decimal('allocated_qty', 14, 3)->default(0);
            $t->string('description')->nullable();
        });
        DB::table('invoice_items')->update(['allocated_qty' => DB::raw('qty')]);
        Schema::table('payments', fn (Blueprint $t) => $t->string('method', 20)->change());
        Schema::create('quotations', function (Blueprint $t) {
            $t->id(); $t->string('number', 30)->unique();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->json('customer_snapshot'); $t->json('shop_snapshot');
            $t->decimal('subtotal', 14, 2); $t->decimal('total', 14, 2);
            $t->date('valid_until'); $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable(); $t->timestamps();
        });
        Schema::create('quotation_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained(); $t->string('description');
            $t->decimal('qty', 14, 3); $t->decimal('unit_price', 14, 2); $t->decimal('line_total', 14, 2);
        });
        Schema::create('manufacturing_requests', function (Blueprint $t) {
            $t->id(); $t->string('number', 30)->unique();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('invoice_item_id')->unique()->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('qty', 14, 3); $t->boolean('is_special')->default(false);
            $t->text('notes')->nullable(); $t->string('status')->default('awaiting_bom');
            $t->foreignId('factory_warehouse_id')->nullable()->constrained('warehouses');
            $t->foreignId('production_order_id')->nullable()->unique()->constrained();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('manufacturing_requests');
        Schema::dropIfExists('quotation_items'); Schema::dropIfExists('quotations');
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn(['allocated_qty', 'description']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['is_order','is_special','notes','due_date','balance_due','customer_snapshot','shop_snapshot']));
        // Keep method as a string so existing Koko/other receipts remain valid on rollback.
    }
};
