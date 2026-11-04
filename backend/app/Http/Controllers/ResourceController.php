<?php
namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Config-driven CRUD with permission checks, branch scoping and audit (via model trait). */
class ResourceController extends Controller
{
    private function cfg(string $resource): array
    {
        $c = config("erp.resources.$resource");
        abort_if(!$c, 404, 'Unknown resource.');
        return $c;
    }

    private function can(array $c, string $action): void
    {
        $perm = $c['perm'] . '.' . $action;
        abort_unless(auth_user()?->hasPermission($perm), 403, "You do not have permission: $perm");
    }

    private function query(array $c, Request $r)
    {
        $q = $c['model']::query();
        if (!empty($c['with'])) $q->with($c['with']);
        \App\Support\ResourceScope::apply($q, $c);
        if ($q->getModel() instanceof \App\Models\Product) {
            $branch=auth_user()->branchScope();
            $q->with(['stockBalances'=>fn($b)=>$b->where('qty','>',0)->when($branch,fn($w)=>$w->whereHas('warehouse',fn($x)=>$x->where('branch_id',$branch)))->with('warehouse')]);
            if ($location=$r->input('filter.stock_location')) {
                $this->scoped('warehouses',(int)$location);
                $q->whereHas('stockBalances',fn($b)=>$b->where('warehouse_id',$location)->where('qty','>',0));
            }
        }

        if ($s = trim((string) $r->query('search', ''))) {
            $cols = $c['search'] ?? [];
            if ($cols) $q->where(function ($w) use ($cols, $s) { foreach ($cols as $col) $w->orWhere($col, 'like', "%$s%"); });
        }
        foreach ((array) $r->query('filter', []) as $col => $val) {
            if ($q->getModel() instanceof \App\Models\Invoice && $col === 'payment_status' && $val !== '') {
                abort_unless(in_array($val, ['pending', 'completed'], true), 422, 'Invalid payment status.');
                $q->whereRaw('(CASE WHEN is_order = 1 THEN balance_due ELSE credit_amount END) '.($val === 'pending' ? '> 0' : '<= 0'));
                continue;
            }
            if (in_array($col, $c['filters'] ?? [], true) && $val !== '' && $val !== null) $q->where($col, $val);
        }
        $dateCol = $c['date'] ?? 'created_at';
        if ($r->filled('from')) $q->where($dateCol, '>=', $r->query('from'));
        if ($r->filled('to')) $q->where($dateCol, '<=', $r->query('to') . (strlen($r->query('to')) <= 10 ? ' 23:59:59' : ''));
        return $q;
    }

    private function validateOwnership(string $resource, array $data): void
    {
        foreach (['employee_id'=>'employees','warehouse_id'=>'warehouses','account_id'=>'accounts','invoice_id'=>'invoices'] as $field=>$related) {
            if (!empty($data[$field])) $this->scoped($related, (int) $data[$field]);
        }
        if ($resource==='products') {
            if (!empty($data['warehouse_id'])) abort_unless(\App\Models\Warehouse::whereKey($data['warehouse_id'])->where('is_active',true)->exists(),422,'Choose an active stock location.');
            if (!empty($data['warranty_id'])) abort_unless(\App\Models\Warranty::whereKey($data['warranty_id'])->where('is_active',true)->exists(),422,'Choose an active warranty category.');
        }
        if ($resource === 'leaves' && isset($data['status']) && $data['status'] !== 'pending') {
            abort_unless(auth_user()->hasPermission('hr.approve'), 403, 'Leave decisions require approval permission.');
        }
    }

    public function index(Request $r, string $resource): JsonResponse
    {
        $c = $this->cfg($resource); $this->can($c, 'view');
        $per = min(max((int) $r->query('per_page', 20), 1), 200);
        $allowed = array_unique(array_merge(['id', 'created_at'], $c['search'] ?? [], $c['filters'] ?? []));
        $sort = $r->query('sort', 'id');
        abort_unless(in_array($sort, $allowed, true), 422, 'Unsupported sort field.');
        $direction = $r->query('direction', 'desc');
        abort_unless(in_array($direction, ['asc', 'desc'], true), 422, 'Unsupported sort direction.');
        $q = $this->query($c, $r)->orderBy($sort, $direction);
        if ($sort !== 'id') $q->orderBy('id');
        return response()->json($q->paginate($per));
    }

    public function show(Request $r, string $resource, int $id): JsonResponse
    {
        $c = $this->cfg($resource); $this->can($c, 'view');
        return response()->json(['data' => $this->query($c, $r)->findOrFail($id)]);
    }

    public function store(Request $r, string $resource): JsonResponse
    {
        $c = $this->cfg($resource); $this->can($c, 'create');
        abort_if($c['readonly'] ?? false, 405, 'Use the workflow action for this record.');
        $data = $r->validate($c['rules']);
        if($resource==='products')$data['loyalty_points']=(int)($data['loyalty_points']??0);
        if($resource==='loyalty_rules' && isset($data['max_purchase']) && $data['max_purchase']<($data['min_purchase']??0)) abort(422,'Maximum purchase must be at least the minimum purchase.');
        $this->validateOwnership($resource, $data);
        if (!empty($c['branch']) && ($scope = auth_user()->branchScope())) $data[$c['branch']] = $scope;
        $row=\Illuminate\Support\Facades\DB::transaction(function() use ($resource,$c,$data) {
            if ($resource==='customers') $data['code']=\App\Support\DocNumber::next('CUS');
            if ($resource==='products') $data['sku']=\App\Support\DocNumber::next('SKU');
            $opening=(float)($data['opening_qty']??0);unset($data['opening_qty']);
            if ($opening>0) {
                abort_unless(auth_user()->hasPermission('inventory.approve'),403,'Opening stock requires inventory approval permission.');
                abort_unless(!empty($data['warehouse_id']),422,'Select a stock location.');
            }
            $row=$c['model']::create($data);
            if (in_array($resource, ['vehicle_logs', 'campaign_expenses'], true) && (float)$row->amount > 0) {
                $finance = app(\App\Services\FinanceService::class);
                $branch = auth_user()->branchScope() ?: auth_user()->branch_id;
                $account = $finance->accountFor($branch, 'cash');
                $vehicle = $resource === 'vehicle_logs';
                $finance->post($account->id, 'out', (float)$row->amount, $vehicle ? 'vehicle_cost' : 'marketing', $vehicle ? 'vehicle_log' : 'campaign_expense', $row->id, $branch, $vehicle ? ucfirst($row->type).' for vehicle #'.$row->vehicle_id : 'Campaign spend #'.$row->campaign_id);
            }
            if ($resource==='products' && $opening>0) app(\App\Services\InventoryService::class)->move($row->id,$data['warehouse_id'],$opening,'opening',['unit_cost'=>(float)($data['cost_price']??0),'note'=>'Opening stock on product creation']);
            return $row;
        });
        return response()->json(['data' => $row], 201);
    }

    public function update(Request $r, string $resource, int $id): JsonResponse
    {
        $c = $this->cfg($resource); $this->can($c, 'edit');
        abort_if($c['readonly'] ?? false, 405, 'Completed records cannot be edited. Use the reversal/return process.');
        $m = $this->query($c, $r)->findOrFail($id);
        if (in_array($resource, ['vehicle_logs', 'campaign_expenses'], true) && \App\Models\FinancialTransaction::where('ref_type', $resource === 'vehicle_logs' ? 'vehicle_log' : 'campaign_expense')->where('ref_id', $m->id)->exists()) abort(422, 'This cost has been posted to Finance and cannot be edited.');
        $rules = [];
        foreach ($c['rules'] as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', $rule);
            $parts = array_map(function ($p) use ($m) {
                if (is_string($p) && str_starts_with($p, 'unique:')) {
                    [$table, $col] = explode(',', substr($p, 7));
                    return Rule::unique($table, $col)->ignore($m->getKey());
                }
                return $p;
            }, array_values(array_filter($parts, fn ($p) => $p !== 'required')));
            array_unshift($parts, 'sometimes');
            $rules[$field] = $parts;
        }
        $data = $r->validate($rules);
        if($resource==='products' && array_key_exists('loyalty_points',$data))$data['loyalty_points']=(int)$data['loyalty_points'];
        if ($resource==='products') unset($data['opening_qty'],$data['sku']);
        if ($resource==='customers') unset($data['code']);
        $this->validateOwnership($resource, $data);
        if (!empty($c['branch']) && ($scope = auth_user()->branchScope())) $data[$c['branch']] = $scope;
        if ($resource === 'users' && empty($data['password'])) unset($data['password']);
        if($resource==='loyalty_rules'){ $limits=array_replace($m->toArray(),$data);if($limits['max_purchase']!==null && $limits['max_purchase']<$limits['min_purchase'])abort(422,'Maximum purchase must be at least the minimum purchase.'); }
        $m->update($data);
        return response()->json(['data' => $m->fresh($c['with'] ?? [])]);
    }

    public function destroy(Request $r, string $resource, int $id): JsonResponse
    {
        $c = $this->cfg($resource); $this->can($c, 'delete');
        abort_unless($c['deletable'] ?? false, 405, 'This record cannot be deleted. Deactivate it instead.');
        try {
            $this->query($c, $r)->findOrFail($id)->delete();
        } catch (QueryException $e) {
            abort(422, 'This record is in use and cannot be deleted.');
        }
        return response()->json(['message' => 'Deleted.']);
    }
}
