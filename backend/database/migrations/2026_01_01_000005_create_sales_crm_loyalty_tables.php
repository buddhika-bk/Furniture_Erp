<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discount_rules', function (Blueprint $t) {
            $t->id(); $t->string('name');
            $t->enum('type', ['percent', 'fixed']);
            $t->decimal('value', 14, 2);
            $t->decimal('min_total', 14, 2)->default(0);
            $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('loyalty_tiers', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique();
            $t->unsignedBigInteger('min_points')->default(0);
            $t->decimal('earn_multiplier', 6, 2)->default(1);
            $t->timestamps();
        });
        Schema::create('loyalty_rewards', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->unsignedBigInteger('points_cost');
            $t->decimal('discount_value', 14, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('channel')->nullable();
            $t->decimal('budget', 14, 2)->default(0);
            $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
            $t->enum('status', ['planned', 'running', 'finished', 'cancelled'])->default('planned');
            $t->timestamps();
        });
        Schema::create('cashier_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('branches');
            $t->foreignId('user_id')->constrained('users');
            $t->decimal('opening_cash', 14, 2)->default(0);
            $t->decimal('expected_cash', 14, 2)->default(0);
            $t->decimal('closing_cash', 14, 2)->nullable();
            $t->decimal('variance', 14, 2)->nullable();
            $t->enum('status', ['open', 'closed'])->default('open');
            $t->timestamp('opened_at')->useCurrent();
            $t->timestamp('closed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('branch_id')->constrained('branches')->index();
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->foreignId('salesperson_id')->nullable()->constrained('employees');
            $t->foreignId('cashier_session_id')->nullable()->constrained('cashier_sessions');
            $t->foreignId('campaign_id')->nullable()->constrained('campaigns');
            $t->decimal('subtotal', 14, 2);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('loyalty_discount', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->decimal('paid', 14, 2)->default(0);
            $t->decimal('credit_amount', 14, 2)->default(0);
            $t->decimal('cost_total', 14, 2)->default(0);        // cost snapshot for profitability
            $t->unsignedBigInteger('points_earned')->default(0);
            $t->unsignedBigInteger('points_redeemed')->default(0);
            $t->decimal('commission_amount', 14, 2)->default(0);
            $t->enum('status', ['completed', 'partially_returned', 'returned'])->default('completed');
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('unit_cost', 14, 4)->default(0);          // snapshot at sale time
            $t->decimal('line_total', 14, 2);
            $t->decimal('returned_qty', 14, 3)->default(0);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices');
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->foreignId('cashier_session_id')->nullable()->constrained('cashier_sessions');
            $t->foreignId('account_id')->nullable()->constrained('accounts');
            $t->enum('method', ['cash', 'card', 'bank', 'credit']);
            $t->enum('direction', ['receipt', 'refund'])->default('receipt');
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('sales_returns', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('invoice_id')->constrained('invoices');
            $t->decimal('refund_amount', 14, 2);
            $t->enum('refund_method', ['cash', 'card', 'bank', 'credit'])->default('cash');
            $t->string('reason')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('sales_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('qty', 14, 3);
            $t->decimal('amount', 14, 2);
        });
        Schema::create('loyalty_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('invoice_id')->nullable()->constrained('invoices');
            $t->bigInteger('points');                 // + earn, - redeem / reversal
            $t->string('type', 20);                   // earn, redeem, reversal, adjust
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::create('commission_rules', function (Blueprint $t) {
            $t->id(); $t->string('name');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->decimal('rate_percent', 6, 2);
            $t->decimal('min_invoice_total', 14, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->foreignId('invoice_id')->constrained('invoices');
            $t->decimal('amount', 14, 2);
            $t->enum('status', ['pending', 'approved', 'paid', 'reversed'])->default('pending');
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('payslip_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['commissions','commission_rules','loyalty_ledger','sales_return_items','sales_returns','payments','invoice_items','invoices','cashier_sessions','campaigns','loyalty_rewards','loyalty_tiers','discount_rules'] as $x) Schema::dropIfExists($x);
    }
};
