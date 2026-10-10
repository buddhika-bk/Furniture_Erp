<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('perm:sales.create') */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->attributes->get('erp_user');
        if (!$user || !$user->hasPermission($permission)) {
            return response()->json(['message' => "You do not have permission: $permission"], 403);
        }
        return $next($request);
    }
}
