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

        if ($s = trim((string) $r->query('search', ''))) {
            $cols = $c['search'] ?? [];
            if ($cols) $q->where(function ($w) use ($cols, $s) { foreach ($cols as $col) $w->orWhere($col, 'like', "%$s%"); });
        }
        foreach ((array) $r->query('filter', []) as $col => $val) {
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
        $this->validateOwnership($resource, $data);
        if (!empty($c['branch']) && ($scope = auth_user()->branchScope())) $data[$c['branch']] = $scope;
        return response()->json(['data' => $c['model']::create($data)], 201);
    }

    public function update(Request $r, string $resource, int $id): JsonResponse
    {
        $c = $this->cfg($resource); $this->can($c, 'edit');
        abort_if($c['readonly'] ?? false, 405, 'Completed records cannot be edited. Use the reversal/return process.');
        $m = $this->query($c, $r)->findOrFail($id);
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
        $this->validateOwnership($resource, $data);
        if (!empty($c['branch']) && ($scope = auth_user()->branchScope())) $data[$c['branch']] = $scope;
        if ($resource === 'users' && empty($data['password'])) unset($data['password']);
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
