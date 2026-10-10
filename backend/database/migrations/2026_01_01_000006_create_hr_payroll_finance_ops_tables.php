<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->date('work_date');
            $t->time('check_in')->nullable(); $t->time('check_out')->nullable();
            $t->decimal('worked_hours', 6, 2)->default(0);
            $t->decimal('overtime_hours', 6, 2)->default(0);
            $t->enum('status', ['present', 'absent', 'late', 'leave'])->default('present');
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
        });
        Schema::create('leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('type', 30)->default('annual');
            $t->date('from_date'); $t->date('to_date');
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->string('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('payroll_periods', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique();
            $t->date('start_date'); $t->date('end_date');
            $t->enum('status', ['draft', 'reviewed', 'approved', 'finalized'])->default('draft');
            $t->foreignId('reviewed_by')->nullable(); $t->foreignId('approved_by')->nullable(); $t->foreignId('finalized_by')->nullable();
            $t->timestamps();
        });
        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained('employees');
            $t->decimal('basic', 14, 2);
            $t->decimal('allowances', 14, 2)->default(0);
            $t->decimal('overtime_hours', 8, 2)->default(0);
            $t->decimal('overtime_amount', 14, 2)->default(0);
            $t->decimal('commission', 14, 2)->default(0);
            $t->decimal('deductions', 14, 2)->default(0);
            $t->decimal('gross', 14, 2);
            $t->decimal('net', 14, 2);
            $t->timestamps();
            $t->unique(['payroll_period_id', 'employee_id']);
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('expense_category_id')->constrained('expense_categories');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->foreignId('account_id')->nullable()->constrained('accounts');
            $t->foreignId('campaign_id')->nullable()->constrained('campaigns');
            $t->decimal('amount', 14, 2);
            $t->date('expense_date');
            $t->string('description')->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->foreignId('created_by')->nullable(); $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        // Ledger of money in/out. Every posting references its originating document.
        Schema::create('financial_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts');
            $t->foreignId('branch_id')->nullable();
            $t->enum('direction', ['in', 'out']);
            $t->decimal('amount', 14, 2);
            $t->string('category', 30)->index();    // sale, refund, expense, supplier_payment, payroll, customer_receipt, delivery
            $t->string('ref_type', 40)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('description')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['ref_type', 'ref_id']);
        });
        Schema::create('delivery_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $t->foreignId('driver_id')->nullable()->constrained('employees');
            $t->string('destination');
            $t->string('route')->nullable();
            $t->decimal('delivery_charge', 14, 2)->default(0);
            $t->decimal('fuel_cost', 14, 2)->default(0);
            $t->enum('status', ['pending', 'dispatched', 'delivered', 'cancelled'])->default('pending');
            $t->timestamp('dispatched_at')->nullable(); $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained('vehicles');
            $t->enum('type', ['fuel', 'maintenance']);
            $t->date('log_date');
            $t->decimal('amount', 14, 2);
            $t->decimal('quantity', 10, 2)->nullable();       // litres for fuel
            $t->unsignedInteger('odometer')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::create('campaign_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns');
            $t->date('spent_on'); $t->decimal('amount', 14, 2); $t->string('note')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['campaign_expenses','vehicle_logs','delivery_orders','financial_transactions','expenses','payslips','payroll_periods','leaves','attendance'] as $x) Schema::dropIfExists($x);
    }
};
