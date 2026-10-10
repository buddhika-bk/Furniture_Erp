<?php
use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => ['app' => config('app.name'), 'status' => 'ok']);
Route::post('/auth/login', [C\AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth.token', 'throttle:240,1'])->group(function () {
    Route::get('/auth/me', [C\AuthController::class, 'me']);
    Route::post('/auth/logout', [C\AuthController::class, 'logout']);

    // Dashboard & reports
    Route::get('/dashboard', [C\ReportController::class, 'dashboard'])->middleware('perm:dashboard.view');
    Route::get('/reports/{name}', [C\ReportController::class, 'show'])->middleware('perm:reports.view');
    Route::get('/settings', [C\SettingsController::class, 'index'])->middleware('perm:settings.view');
    Route::put('/settings', [C\SettingsController::class, 'update'])->middleware('perm:settings.edit');
    Route::post('/alerts/read', [C\SettingsController::class, 'markAlertsRead'])->middleware('perm:dashboard.view');

    // Inventory
    Route::post('/inventory/transfers', [C\InventoryController::class, 'transfer'])->middleware('perm:inventory.create');
    Route::post('/inventory/adjustments', [C\InventoryController::class, 'requestAdjustment'])->middleware('perm:inventory.create');
    Route::post('/inventory/adjustments/{id}/{decision}', [C\InventoryController::class, 'decide'])->whereIn('decision', ['approve', 'reject'])->middleware('perm:inventory.approve');
    Route::post('/inventory/opening', [C\InventoryController::class, 'opening'])->middleware('perm:inventory.approve');

    // Purchasing
    Route::post('/purchase-orders', [C\PurchasingController::class, 'store'])->middleware('perm:purchasing.create');
    Route::post('/purchase-orders/{id}/approve', [C\PurchasingController::class, 'approve'])->middleware('perm:purchasing.approve');
    Route::post('/purchase-orders/{id}/receive', [C\PurchasingController::class, 'receive'])->middleware('perm:inventory.create');
    Route::post('/supplier-invoices/{id}/pay', [C\PurchasingController::class, 'pay'])->middleware('perm:finance.create');

    // Manufacturing & costing
    Route::post('/boms', [C\ManufacturingController::class, 'storeBom'])->middleware('perm:manufacturing.create');
    Route::put('/boms/{id}', [C\ManufacturingController::class, 'updateBom'])->middleware('perm:manufacturing.edit');
    Route::get('/boms/{id}/cost', [C\ManufacturingController::class, 'bomCost'])->middleware('perm:manufacturing.view');
    Route::post('/production-orders', [C\ManufacturingController::class, 'storeOrder'])->middleware('perm:manufacturing.create');
    Route::post('/production-orders/{id}/{action}', [C\ManufacturingController::class, 'transition'])->whereIn('action', ['release', 'start', 'complete', 'cancel'])->middleware('perm:manufacturing.edit');

    // POS & sales
    Route::get('/pos/catalog', [C\SalesController::class, 'catalog'])->middleware('perm:sales.view');
    Route::post('/pos/checkout', [C\SalesController::class, 'checkout'])->middleware('perm:sales.create');
    Route::post('/invoices/{id}/return', [C\SalesController::class, 'returnItems'])->middleware('perm:sales.cancel');
    Route::post('/cashier-sessions/open', [C\SalesController::class, 'openSession'])->middleware('perm:sales.create');
    Route::post('/cashier-sessions/{id}/close', [C\SalesController::class, 'closeSession'])->middleware('perm:sales.create');
    Route::post('/customers/{id}/payments', [C\SalesController::class, 'customerPayment'])->middleware('perm:crm.edit');
    Route::get('/customers/{id}/history', [C\SalesController::class, 'customerHistory'])->middleware('perm:crm.view');

    // Finance, HR, delivery
    Route::post('/expenses', [C\FinanceController::class, 'storeExpense'])->middleware('perm:finance.create');
    Route::post('/expenses/{id}/{decision}', [C\FinanceController::class, 'decideExpense'])->whereIn('decision', ['approve', 'reject'])->middleware('perm:finance.approve');
    Route::post('/commissions/{id}/approve', [C\FinanceController::class, 'approveCommission'])->middleware('perm:payroll.approve');
    Route::post('/leaves/{id}/{decision}', [C\HrController::class, 'decideLeave'])->whereIn('decision', ['approve', 'reject'])->middleware('perm:hr.approve');
    Route::post('/payroll-periods', [C\HrController::class, 'storePeriod'])->middleware('perm:payroll.create');
    Route::get('/payroll-periods/{id}', [C\HrController::class, 'period'])->middleware('perm:payroll.view');
    Route::post('/payroll-periods/{id}/generate', [C\HrController::class, 'generate'])->middleware('perm:payroll.edit');
    Route::post('/payroll-periods/{id}/advance', [C\HrController::class, 'advance'])->middleware('perm:payroll.approve');
    Route::post('/delivery-orders', [C\DeliveryController::class, 'store'])->middleware('perm:delivery.create');
    Route::post('/delivery-orders/{id}/status', [C\DeliveryController::class, 'status'])->middleware('perm:delivery.edit');

    // Generic master data & read-only listings (permission checked inside)
    Route::get('/{resource}', [C\ResourceController::class, 'index']);
    Route::post('/{resource}', [C\ResourceController::class, 'store']);
    Route::get('/{resource}/{id}', [C\ResourceController::class, 'show'])->whereNumber('id');
    Route::put('/{resource}/{id}', [C\ResourceController::class, 'update'])->whereNumber('id');
    Route::delete('/{resource}/{id}', [C\ResourceController::class, 'destroy'])->whereNumber('id');
});
