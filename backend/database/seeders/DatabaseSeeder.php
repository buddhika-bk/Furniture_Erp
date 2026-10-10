<?php
namespace Database\Seeders;

use App\Models\{Account, Bom, Branch, Campaign, Category, CommissionRule, Customer, Department, DiscountRule, Employee, ExpenseCategory, LoyaltyReward, LoyaltyTier, Position, Product, Role, Supplier, Unit, User, Vehicle, Warehouse};
use App\Services\CostingService;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $inv = app(InventoryService::class);

        // ---- roles (least privilege; branch roles are bound to one branch via the user's branch_id)
        $view = fn (array $m) => array_map(fn ($x) => "$x.view", $m);
        $roles = [
            'Administrator' => [['*'], true],
            'Branch Manager' => [array_merge($view(['dashboard', 'reports', 'products', 'inventory', 'branches', 'marketing', 'hr', 'purchasing']), ['inventory.create', 'sales.*', 'crm.*', 'delivery.*', 'finance.create', 'finance.view']), false],
            'Cashier' => [['sales.view', 'sales.create', 'crm.view', 'crm.create', 'crm.edit', 'products.view'], false],
            'Storekeeper' => [array_merge($view(['products', 'dashboard']), ['inventory.*', 'purchasing.view', 'purchasing.create']), true],
            'Factory Manager' => [array_merge($view(['dashboard', 'reports', 'products']), ['manufacturing.*', 'inventory.*', 'purchasing.view', 'purchasing.create']), true],
            'HR Manager' => [['dashboard.view', 'reports.view', 'hr.*', 'payroll.*'], true],
            'Accountant' => [['dashboard.view', 'reports.view', 'finance.*', 'purchasing.view', 'purchasing.approve', 'payroll.view', 'sales.view', 'crm.view', 'marketing.*', 'delivery.view', 'audit.view'], true],
        ];
        $role = [];
        foreach ($roles as $name => [$perms, $all]) $role[$name] = Role::create(['name' => $name, 'permissions' => $perms, 'all_branches' => $all]);

        // ---- branches & stock locations (head office + factory + five showrooms)
        $ho = Branch::create(['code' => 'HO', 'name' => 'Head Office', 'type' => 'head_office']);
        $fac = Branch::create(['code' => 'FAC', 'name' => 'Factory', 'type' => 'factory']);
        $sr = [];
        foreach (['City Centre', 'Riverside', 'Northgate', 'Harbour Mall', 'Westfield'] as $i => $n) {
            $sr[$i + 1] = Branch::create(['code' => 'SR' . ($i + 1), 'name' => "Showroom " . ($i + 1) . " - $n", 'type' => 'showroom']);
        }
        $raw = Warehouse::create(['code' => 'FAC-RAW', 'name' => 'Factory Raw Materials', 'type' => 'factory_raw', 'branch_id' => $fac->id]);
        $fg = Warehouse::create(['code' => 'FAC-FG', 'name' => 'Factory Finished Goods', 'type' => 'factory_fg', 'branch_id' => $fac->id]);
        $wh = [];
        foreach ($sr as $i => $b) $wh[$i] = Warehouse::create(['code' => "WH-SR$i", 'name' => "{$b->name} Store", 'type' => 'showroom', 'branch_id' => $b->id]);
        Warehouse::create(['code' => 'HO-STORE', 'name' => 'Head Office Store', 'type' => 'head_office', 'branch_id' => $ho->id]);

        // ---- users (password for all demo accounts: Password@123)
        $mk = fn ($n, $e, $r, $b = null) => User::create(['name' => $n, 'email' => $e, 'password' => 'Password@123', 'role_id' => $role[$r]->id, 'branch_id' => $b?->id]);
        $mk('System Administrator', 'admin@erp.test', 'Administrator', $ho);
        $mk('Showroom 1 Manager', 'manager1@erp.test', 'Branch Manager', $sr[1]);
        $mk('Showroom 1 Cashier', 'cashier1@erp.test', 'Cashier', $sr[1]);
        $mk('Showroom 2 Cashier', 'cashier2@erp.test', 'Cashier', $sr[2]);
        $mk('Store Keeper', 'store@erp.test', 'Storekeeper', $fac);
        $mk('Factory Manager', 'factory@erp.test', 'Factory Manager', $fac);
        $mk('HR Manager', 'hr@erp.test', 'HR Manager', $ho);
        $mk('Chief Accountant', 'accounts@erp.test', 'Accountant', $ho);

        // ---- master data
        $m = Unit::create(['code' => 'm', 'name' => 'Metre']); $pcs = Unit::create(['code' => 'pcs', 'name' => 'Pieces']); Unit::create(['code' => 'kg', 'name' => 'Kilogram']);
        $cRaw = Category::create(['name' => 'Fabrics & Yarn', 'kind' => 'raw_material']);
        $cPack = Category::create(['name' => 'Packaging', 'kind' => 'packaging']);
        $cBed = Category::create(['name' => 'Bed Sheets', 'kind' => 'finished_good']);
        $cotton = Product::create(['sku' => 'RM-COT-01', 'name' => 'Cotton Fabric 240TC', 'type' => 'raw_material', 'category_id' => $cRaw->id, 'unit_id' => $m->id, 'reorder_level' => 300]);
        $thread = Product::create(['sku' => 'RM-THR-01', 'name' => 'Sewing Thread Cone', 'type' => 'raw_material', 'category_id' => $cRaw->id, 'unit_id' => $pcs->id, 'reorder_level' => 50]);
        $bag = Product::create(['sku' => 'PK-BAG-01', 'name' => 'Poly Bag', 'type' => 'packaging', 'category_id' => $cPack->id, 'unit_id' => $pcs->id, 'reorder_level' => 100]);
        $box = Product::create(['sku' => 'PK-BOX-01', 'name' => 'Gift Box', 'type' => 'packaging', 'category_id' => $cPack->id, 'unit_id' => $pcs->id, 'reorder_level' => 100]);
        $queen = Product::create(['sku' => 'FG-BS-Q', 'name' => 'Bed Sheet Set', 'variant' => 'Queen', 'type' => 'finished_good', 'category_id' => $cBed->id, 'unit_id' => $pcs->id, 'sale_price' => 49.90, 'reorder_level' => 10]);
        $king = Product::create(['sku' => 'FG-BS-K', 'name' => 'Bed Sheet Set', 'variant' => 'King', 'type' => 'finished_good', 'category_id' => $cBed->id, 'unit_id' => $pcs->id, 'sale_price' => 59.90, 'reorder_level' => 10]);

        foreach ([['code' => 'SUP-001', 'name' => 'Lakeside Textiles'], ['code' => 'SUP-002', 'name' => 'PackWell Supplies']] as $s) Supplier::create($s + ['phone' => '000-000-0000']);
        foreach ([['CUS-001', 'Walk-in Customer', 0], ['CUS-002', 'Hotel Sunrise', 5000], ['CUS-003', 'Amelia Brooks', 500]] as [$c, $n, $l]) Customer::create(['code' => $c, 'name' => $n, 'credit_limit' => $l, 'tier' => 'Bronze']);
        LoyaltyTier::create(['name' => 'Bronze', 'min_points' => 0, 'earn_multiplier' => 1]);
        LoyaltyTier::create(['name' => 'Silver', 'min_points' => 500, 'earn_multiplier' => 1.25]);
        LoyaltyTier::create(['name' => 'Gold', 'min_points' => 2000, 'earn_multiplier' => 1.5]);
        LoyaltyReward::create(['name' => 'Free pillow case', 'points_cost' => 300, 'discount_value' => 8]);
        DiscountRule::create(['name' => 'Seasonal 10% (min 100)', 'type' => 'percent', 'value' => 10, 'min_total' => 100]);
        DiscountRule::create(['name' => 'Flat 5 off', 'type' => 'fixed', 'value' => 5, 'min_total' => 40]);
        CommissionRule::create(['name' => 'Standard showroom commission', 'rate_percent' => 2, 'min_invoice_total' => 0]);
        Campaign::create(['name' => 'Festive Season Launch', 'channel' => 'Social & print', 'budget' => 5000, 'status' => 'running']);

        foreach (['Rent & utilities', 'Salaries & wages', 'Marketing', 'Delivery & fuel', 'Maintenance', 'Office supplies'] as $n) ExpenseCategory::create(['name' => $n]);
        Account::create(['name' => 'Main Bank Account', 'type' => 'bank']);
        Account::create(['name' => 'Head Office Cash', 'type' => 'cash']);
        foreach ($sr as $i => $b) Account::create(['name' => "Showroom $i Cash Drawer", 'type' => 'cash', 'branch_id' => $b->id]);

        // ---- HR
        $dSales = Department::create(['name' => 'Sales']); $dFac = Department::create(['name' => 'Production']); Department::create(['name' => 'Finance']); Department::create(['name' => 'Logistics']);
        $pSales = Position::create(['name' => 'Sales Associate']); $pOp = Position::create(['name' => 'Machine Operator']); $pDrv = Position::create(['name' => 'Driver']);
        foreach ($sr as $i => $b) {
            foreach ([1, 2] as $k) Employee::create(['code' => "E-S{$i}{$k}", 'name' => "Sales Associate S{$i}-{$k}", 'department_id' => $dSales->id, 'position_id' => $pSales->id, 'branch_id' => $b->id, 'hire_date' => '2025-01-15', 'basic_salary' => 900, 'allowances' => 100, 'deductions' => 50, 'ot_rate' => 6, 'is_salesperson' => true]);
        }
        Employee::create(['code' => 'E-F01', 'name' => 'Factory Operator 1', 'department_id' => $dFac->id, 'position_id' => $pOp->id, 'branch_id' => $fac->id, 'hire_date' => '2024-06-01', 'basic_salary' => 1100, 'allowances' => 80, 'deductions' => 60, 'ot_rate' => 8]);
        Employee::create(['code' => 'E-D01', 'name' => 'Delivery Driver 1', 'position_id' => $pDrv->id, 'branch_id' => $ho->id, 'hire_date' => '2024-03-01', 'basic_salary' => 950, 'ot_rate' => 7]);
        Vehicle::create(['plate' => 'VAN-1001', 'model' => 'Delivery Van', 'capacity_kg' => 1200]);

        DB::table('settings')->insert([
            ['key' => 'company_name', 'value' => 'Aurum Home Textiles', 'description' => 'Company name on documents'],
            ['key' => 'currency', 'value' => 'USD', 'description' => 'Display currency'],
            ['key' => 'loyalty_currency_per_point', 'value' => '10', 'description' => 'Amount spent to earn 1 loyalty point'],
            ['key' => 'loyalty_point_value', 'value' => '0.05', 'description' => 'Discount value of 1 point when redeemed'],
        ]);

        // ---- opening stock (agreed opening balances) + recipe
        $inv->move($cotton->id, $raw->id, 2000, 'opening', ['unit_cost' => 4.50, 'note' => 'Opening balance']);
        $inv->move($thread->id, $raw->id, 300, 'opening', ['unit_cost' => 1.20, 'note' => 'Opening balance']);
        $inv->move($bag->id, $raw->id, 500, 'opening', ['unit_cost' => 0.30, 'note' => 'Opening balance']);
        $inv->move($box->id, $raw->id, 500, 'opening', ['unit_cost' => 0.80, 'note' => 'Opening balance']);
        foreach ($wh as $w) {
            $inv->move($queen->id, $w->id, 25, 'opening', ['unit_cost' => 27.00, 'note' => 'Opening balance']);
            $inv->move($king->id, $w->id, 20, 'opening', ['unit_cost' => 32.00, 'note' => 'Opening balance']);
        }
        foreach ([[$queen, 6.0], [$king, 7.0]] as [$p, $metres]) {
            $b = Bom::create(['product_id' => $p->id, 'version' => 1, 'output_qty' => 10, 'labour_cost' => 25, 'overhead_cost' => 15]);
            $b->items()->create(['product_id' => $cotton->id, 'qty' => $metres * 10, 'wastage_percent' => 5]);
            $b->items()->create(['product_id' => $thread->id, 'qty' => 10]);
            $b->items()->create(['product_id' => $bag->id, 'qty' => 10]);
            $b->items()->create(['product_id' => $box->id, 'qty' => 10]);
            app(CostingService::class)->recordVersion($b->load('items.product'));
        }
    }
}
