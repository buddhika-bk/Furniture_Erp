<?php
namespace App\Models;

class Customer extends BaseModel
{
    protected $casts=['is_walk_in'=>'boolean'];
}

