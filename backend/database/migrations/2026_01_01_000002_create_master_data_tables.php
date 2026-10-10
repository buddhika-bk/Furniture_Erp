<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $t) {
            $t->id(); $t->string('code', 12)->unique(); $t->string('name');
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique();
            $t->enum('kind', ['raw_material', 'packaging', 'finished_good'])->default('finished_good');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 40)->unique();
            $t->string('name')->index();
            $t->string('variant')->nullable();                 // size / colour etc.
            $t->enum('type', ['raw_material', 'packaging', 'finished_good']);
            $t->foreignId('category_id')->nullable()->constrained('categories');
            $t->foreignId('unit_id')->constrained('units');
            $t->decimal('cost_price', 14, 4)->default(0);       // moving average cost
            $t->decimal('sale_price', 14, 2)->default(0);
            $t->decimal('reorder_level', 14, 3)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->enum('type', ['factory_raw', 'factory_fg', 'showroom', 'head_office'])->default('showroom');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); $t->string('name');
            $t->string('contact_person')->nullable(); $t->string('phone', 40)->nullable(); $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->decimal('outstanding_balance', 14, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); $t->string('name')->index();
            $t->string('phone', 40)->nullable()->index(); $t->string('email')->nullable(); $t->string('address')->nullable();
            $t->decimal('credit_limit', 14, 2)->default(0);
            $t->decimal('credit_balance', 14, 2)->default(0);   // receivable owed by customer
            $t->unsignedBigInteger('loyalty_points')->default(0);
            $t->string('tier')->nullable();
            $t->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('departments', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->timestamps(); });
        Schema::create('positions', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->timestamps(); });
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); $t->string('name');
            $t->string('phone', 40)->nullable(); $t->string('email')->nullable();
            $t->foreignId('department_id')->nullable()->constrained('departments');
            $t->foreignId('position_id')->nullable()->constrained('positions');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->date('hire_date')->nullable();
            $t->enum('status', ['active', 'on_leave', 'resigned', 'terminated'])->default('active');
            $t->decimal('basic_salary', 14, 2)->default(0);
            $t->decimal('allowances', 14, 2)->default(0);
            $t->decimal('deductions', 14, 2)->default(0);
            $t->decimal('ot_rate', 14, 2)->default(0);          // pay per overtime hour
            $t->boolean('is_salesperson')->default(false);
            $t->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id(); $t->string('plate', 20)->unique(); $t->string('model')->nullable();
            $t->decimal('capacity_kg', 10, 2)->nullable();
            $t->enum('status', ['available', 'in_use', 'maintenance'])->default('available');
            $t->timestamps();
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique();
            $t->enum('type', ['cash', 'bank'])->default('cash');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->decimal('balance', 16, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique(); $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['expense_categories','accounts','vehicles','employees','positions','departments','customers','suppliers','warehouses','products','categories','units'] as $x) Schema::dropIfExists($x);
    }
};
