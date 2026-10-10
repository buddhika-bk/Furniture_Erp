<?php
namespace App\Models;

class Role extends BaseModel
{
    protected $casts = ['permissions' => 'array', 'all_branches' => 'boolean'];
    public function users() { return $this->hasMany(User::class); }
}
