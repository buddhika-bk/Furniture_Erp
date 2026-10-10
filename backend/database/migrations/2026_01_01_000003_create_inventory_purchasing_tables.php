<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->decimal('qty', 16, 3)->default(0);
            $t->timestamps();
            $t->unique(['product_id', 'warehouse_id']);
        });
        // Immutable stock ledger: every change to stock is a row here.
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->decimal('qty', 16, 3);                    // signed: + in, - out
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->string('type', 30)->index();              // purchase_receipt, production_issue, production_output, sale, sale_return, transfer_out, transfer_in, adjustment, opening
            $t->string('ref_type', 40)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('batch_no', 40)->nullable()->index();
            $t->foreignId('user_id')->nullable();
            $t->string('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['product_id', 'warehouse_id']);
            $t->index(['ref_type', 'ref_id']);
        });
        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('from_warehouse_id')->constrained('warehouses');
            $t->foreignId('to_warehouse_id')->constrained('warehouses');
            $t->enum('status', ['completed', 'cancelled'])->default('completed');
            $t->foreignId('user_id')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_transfer_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('qty', 16, 3);
        });
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('counted_qty', 16, 3);
            $t->decimal('system_qty', 16, 3);
            $t->decimal('difference', 16, 3);
            $t->string('reason')->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->foreignId('requested_by')->nullable();
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->date('order_date');
            $t->enum('status', ['draft', 'approved', 'partially_received', 'received', 'cancelled'])->default('draft');
            $t->decimal('total', 14, 2)->default(0);
            $t->foreignId('created_by')->nullable();
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('qty', 16, 3);
            $t->decimal('received_qty', 16, 3)->default(0);
            $t->decimal('unit_price', 14, 4);
        });
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders');
            $t->foreignId('received_by')->nullable();
            $t->decimal('total', 14, 2)->default(0);
            $t->string('batch_no', 40)->nullable();
            $t->timestamps();
        });
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts');
            $t->decimal('amount', 14, 2);
            $t->decimal('paid', 14, 2)->default(0);
            $t->date('due_date')->nullable();
            $t->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $t->timestamps();
        });
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices');
            $t->foreignId('account_id')->constrained('accounts');
            $t->decimal('amount', 14, 2);
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['supplier_payments','supplier_invoices','goods_receipts','purchase_order_items','purchase_orders','stock_adjustments','stock_transfer_items','stock_transfers','stock_movements','stock_balances'] as $x) Schema::dropIfExists($x);
    }
};
