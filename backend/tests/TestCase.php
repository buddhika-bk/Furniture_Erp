<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function loginAs(string $email = 'admin@erp.test'): array
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $res = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'Password@123'])->assertOk();
        return ['Authorization' => 'Bearer ' . $res->json('token')];
    }
}
