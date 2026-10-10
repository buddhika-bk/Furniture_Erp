<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Base for append-only / high-volume tables that are their own audit trail (no audit_logs rows). */
abstract class LedgerModel extends Model
{
    protected $guarded = [];
}
