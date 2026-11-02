<?php
namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;

class WorkspaceContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth_user();
        if ($request->hasHeader('X-Branch-Id')) {
            $branch = filter_var($request->header('X-Branch-Id'), FILTER_VALIDATE_INT);
            abort_unless($branch && Branch::whereKey($branch)->where('is_active', true)->exists(), 422, 'Choose an active branch.');
            abort_unless($user->role?->all_branches || (int) $user->branch_id === $branch, 403, 'This branch is not available to your account.');
            $request->attributes->set('workspace_branch_id', $branch);
        }
        return $next($request);
    }
}
