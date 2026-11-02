<?php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Serialize commands per actor and commit the result with the business transaction. */
class IdempotentCommand
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        if (!$key) return $next($request); // Compatibility for audited legacy clients.
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422, 'Invalid operation key.');
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->header('X-Branch-Id').'|'.$request->getContent());
        return DB::transaction(function () use ($request, $next, $key, $fingerprint) {
            User::whereKey(auth_user_id())->lockForUpdate()->firstOrFail();
            $saved = DB::table('api_operations')->where('user_id', auth_user_id())->where('key', $key)->first();
            if ($saved) {
                abort_unless(hash_equals($saved->fingerprint, $fingerprint), 409, 'This operation key was already used with different details.');
                return response($saved->response, $saved->status_code)->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
            }
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                DB::table('api_operations')->insert(['user_id' => auth_user_id(), 'key' => $key, 'fingerprint' => $fingerprint, 'status_code' => $response->getStatusCode(), 'response' => $response->getContent(), 'created_at' => now(), 'updated_at' => now()]);
            }
            return $response;
        }, 3);
    }
}
