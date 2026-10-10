<?php
namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use Auditable;
    protected $guarded = [];
}
