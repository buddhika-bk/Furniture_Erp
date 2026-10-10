<?php
namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Auditable;
    protected $guarded = [];
    protected $hidden = ['password', 'api_token'];
    protected $casts = ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime', 'token_expires_at' => 'datetime'];

    public function role() { return $this->belongsTo(Role::class); }
    public function branch() { return $this->belongsTo(Branch::class); }

    /** Permission format "module.action"; supports "*" and "module.*". Least privilege: no match = denied. */
    public function hasPermission(string $perm): bool
    {
        $granted = $this->role?->permissions ?? [];
        [$module] = explode('.', $perm);
        return in_array('*', $granted, true) || in_array($perm, $granted, true) || in_array("$module.*", $granted, true);
    }

    /** Branch-restricted users can only see their own branch (spec: branch-level access restrictions). */
    public function branchScope(): ?int
    {
        if ($this->role?->all_branches || !$this->branch_id) return null;
        return $this->branch_id;
    }
}
