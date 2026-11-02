<?php
namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function lookups(Request $request, string $resource)
    {
        $config = config("erp.resources.$resource");
        abort_unless($config, 404);
        $dependencies = [
            'branches' => ['sales.view', 'reports.view', 'finance.create'],
            'customers' => ['sales.create'], 'products' => ['inventory.create', 'manufacturing.view', 'purchasing.create'],
            'warehouses' => ['manufacturing.view', 'purchasing.create', 'sales.view'],
            'employees' => ['delivery.create', 'sales.create'], 'accounts' => ['purchasing.create'],
            'campaigns' => ['finance.create'],
        ];
        $permitted = auth_user()->hasPermission($config['perm'].'.view');
        foreach ($dependencies[$resource] ?? [] as $permission) $permitted = $permitted || auth_user()->hasPermission($permission);
        abort_unless($permitted, 403);
        $query = \App\Support\ResourceScope::apply($config['model']::query(), $config);
        foreach ((array)$request->query('filter', []) as $key => $value) {
            if ($value !== '' && in_array($key, array_merge($config['filters'] ?? [], $resource === 'branches' ? ['type'] : []), true)) $query->where($key, $value);
        }
        if ($resource === 'boms') $query->with('product:id,name');
        $result = $query->orderBy('id')->paginate(200);
        $result->setCollection($result->getCollection()->map(function ($row) use ($resource) {
            $fields = array_intersect_key($row->toArray(), array_flip(['id', 'name', 'code', 'sku', 'variant', 'plate', 'number', 'version', 'type']));
            if ($resource === 'boms') $fields['product'] = ['name' => $row->product?->name];
            return $fields;
        }));
        return response()->json($result);
    }

    public function search(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:100']);
        $results = [];
        foreach (['products', 'customers', 'invoices', 'production_orders', 'purchase_orders', 'employees'] as $resource) {
            $config = config("erp.resources.$resource");
            if (!auth_user()->hasPermission($config['perm'].'.view')) continue;
            $query = \App\Support\ResourceScope::apply($config['model']::query(), $config);
            $query->where(function ($q) use ($config, $data) { foreach ($config['search'] ?? [] as $column) $q->orWhere($column, 'like', '%'.$data['q'].'%'); });
            foreach ($query->limit(5)->get() as $row) $results[] = ['id' => $row->id, 'resource' => $resource, 'title' => $row->name ?? $row->number ?? $row->code, 'subtitle' => $row->sku ?? $row->code ?? $resource];
        }
        return response()->json(['data' => $results]);
    }

    public function apps()
    {
        $apps = collect(config('workspace.apps'))->map(function ($app) {
            $app['routes'] = array_values(array_filter($app['routes'], fn ($route) => auth_user()->hasPermission($route[2])));
            return $app;
        })->filter(fn ($app) => count($app['routes']))->values();
        $branches = Branch::where('is_active', true)->when(!auth_user()->role?->all_branches, fn ($q) => $q->whereKey(auth_user()->branch_id ?? -1))->get(['id', 'name', 'type']);
        return response()->json(['data' => $apps, 'branches' => $branches, 'preferences' => auth_user()->ui_preferences ?? []]);
    }

    public function preferences(Request $request)
    {
        $data = $request->validate(['pinned_apps' => 'sometimes|array|max:20', 'pinned_apps.*' => 'string|max:40', 'solid_surfaces' => 'sometimes|boolean', 'compact_sidebar' => 'sometimes|boolean', 'timezone' => 'sometimes|timezone', 'language' => 'sometimes|in:en']);
        $preferences = array_merge(auth_user()->ui_preferences ?? [], $data);
        auth_user()->update(['ui_preferences' => $preferences]);
        return response()->json(['data' => $preferences]);
    }

    public function profile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        auth_user()->update($data);
        return response()->json(['data' => ['name' => auth_user()->name, 'email' => auth_user()->email]]);
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => 'required|string|min:12|confirmed']);
        if (!Hash::check($data['current_password'], auth_user()->password)) throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        auth_user()->update(['password' => $data['password'], 'api_token' => null, 'token_expires_at' => null]);
        return response()->json(['message' => 'Password changed. Sign in again.']);
    }

    private function alerts()
    {
        return Alert::query()->when(auth_user()->branchScope(), fn ($q, $branch) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branch)));
    }

    public function notifications()
    {
        $read = DB::table('notification_reads')->where('user_id', auth_user_id())->pluck('read_at', 'alert_id');
        $data = $this->alerts()->latest('id')->limit(50)->get()->map(function ($alert) use ($read) { $alert->read_at = $read[$alert->id] ?? null; return $alert; });
        $unread = $this->alerts()->whereNotIn('id', $read->keys())->count();
        return response()->json(['data' => $data, 'unread_count' => $unread]);
    }

    public function readNotifications(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|max:50', 'ids.*' => 'integer']);
        $ids = $this->alerts()->whereIn('id', $data['ids'])->pluck('id');
        foreach ($ids as $id) DB::table('notification_reads')->updateOrInsert(['user_id' => auth_user_id(), 'alert_id' => $id], ['read_at' => now()]);
        return $this->notifications();
    }

    public function operation(string $key)
    {
        $operation = DB::table('api_operations')->where('user_id', auth_user_id())->where('key', $key)->first();
        abort_unless($operation, 404, 'No committed result has been recorded for this operation.');
        return response()->json(['data' => ['status' => 'completed', 'status_code' => $operation->status_code, 'result' => json_decode($operation->response, true)]]);
    }
}
