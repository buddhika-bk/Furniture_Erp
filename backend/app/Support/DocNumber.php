<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Gap-safe document numbers, e.g. INV-2026-000012. */
class DocNumber
{
    public static function next(string $prefix): string
    {
        $n = DB::transaction(function () use ($prefix) {
            $row = DB::table('sequences')->where('key', $prefix)->lockForUpdate()->first();
            if (!$row) {
                DB::table('sequences')->insert(['key' => $prefix, 'value' => 1]);
                return 1;
            }
            DB::table('sequences')->where('key', $prefix)->update(['value' => $row->value + 1]);
            return $row->value + 1;
        });
        return sprintf('%s-%s-%06d', $prefix, date('Y'), $n);
    }
}
