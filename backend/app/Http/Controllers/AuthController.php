<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::with('role', 'branch')->where('email', $d['email'])->first();
        if (!$user || !$user->is_active || !Hash::check($d['password'], $user->password)) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }
        $token = Str::random(60);
        $user->forceFill(['api_token' => hash('sha256', $token), 'token_expires_at' => now()->addHours(12), 'last_login_at' => now()])->saveQuietly();
        return response()->json(['token' => $token, 'user' => $this->payload($user)]);
    }

    public function me(Request $r) { return response()->json(['user' => $this->payload(auth_user()->load('role', 'branch'))]); }

    public function logout()
    {
        auth_user()->forceFill(['api_token' => null, 'token_expires_at' => null])->saveQuietly();
        return response()->json(['message' => 'Signed out.']);
    }

    private function payload(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role?->name, 'permissions' => $u->role?->permissions ?? [],
            'branch_id' => $u->branchScope(), 'branch' => $u->branch?->name, 'all_branches' => (bool) $u->role?->all_branches];
    }
}
