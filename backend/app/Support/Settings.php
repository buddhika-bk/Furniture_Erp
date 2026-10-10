<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

class Settings
{
    public static function get(string $key, $default = null)
    {
        $v = DB::table('settings')->where('key', $key)->value('value');
        return $v === null ? $default : $v;
    }
}
