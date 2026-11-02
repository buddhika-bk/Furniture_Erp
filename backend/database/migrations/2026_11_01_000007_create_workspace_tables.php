<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->json('ui_preferences')->nullable());
        Schema::create('notification_reads', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->primary(['user_id', 'alert_id']);
        });
        Schema::create('api_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('key', 100);
            $t->string('fingerprint', 64);
            $t->unsignedSmallInteger('status_code');
            $t->longText('response');
            $t->timestamps();
            $t->unique(['user_id', 'key']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('api_operations');
        Schema::dropIfExists('notification_reads');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ui_preferences'));
    }
};
