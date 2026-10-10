<?php
namespace App\Models;

class Setting extends LedgerModel
{
    protected $primaryKey = "key"; public $incrementing = false; protected $keyType = "string"; public $timestamps = false;

}
