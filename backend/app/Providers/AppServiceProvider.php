<?php
namespace App\Providers;

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Scheduled jobs (spec section 22)
        Schedule::command('erp:alerts')->dailyAt('07:00');
    }
}
