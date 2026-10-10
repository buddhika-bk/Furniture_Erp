<?php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/** Bearer-token API authentication. Only the SHA-256 hash of the token is stored. */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $user = $token ? User::with('role')->where('api_token', hash('sha256', $token))->first() : null;
        if (!$user || !$user->is_active || ($user->token_expires_at && $user->token_expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set('erp_user', $user);
        return $next($request);
    }
}
