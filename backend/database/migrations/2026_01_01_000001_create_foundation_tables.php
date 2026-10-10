<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->json('permissions');            // ["*"] or ["sales.view","sales.create","inventory.*"]
            $t->boolean('all_branches')->default(false);
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->enum('type', ['head_office', 'factory', 'showroom'])->default('showroom');
            $t->string('address')->nullable();
            $t->string('phone', 40)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->foreignId('role_id')->constrained('roles');
            $t->foreignId('branch_id')->nullable()->constrained('branches');
            $t->string('api_token', 64)->nullable()->index();   // sha256 of issued token
            $t->timestamp('token_expires_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('action', 40);
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['auditable_type', 'auditable_id']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->string('description')->nullable();
        });
        Schema::create('sequences', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->string('type', 40)->index();
            $t->string('level', 12)->default('info');
            $t->string('message');
            $t->foreignId('branch_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }
    public function down(): void
    {
        foreach (['failed_jobs','jobs','alerts','sequences','settings','audit_logs','users','branches','roles'] as $x) Schema::dropIfExists($x);
    }
};
