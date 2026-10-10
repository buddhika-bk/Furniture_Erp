<?php
namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index() { return response()->json(['data' => Setting::all()]); }

    public function update(Request $r)
    {
        $d = $r->validate(['settings' => 'required|array']);
        foreach ($d['settings'] as $k => $v) {
            Setting::where('key', $k)->exists() ? Setting::where('key', $k)->update(['value' => $v]) : Setting::create(['key' => $k, 'value' => $v]);
        }
        return $this->index();
    }

    public function markAlertsRead() { Alert::whereNull('read_at')->update(['read_at' => now()]); return response()->json(['message' => 'Done.']); }
}
