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

    public function markAlertsRead()
    {
        $alerts = Alert::query()->when(auth_user()->branchScope(), fn ($q, $scope) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $scope)))->pluck('id');
        foreach ($alerts as $id) \Illuminate\Support\Facades\DB::table('notification_reads')->updateOrInsert(['user_id' => auth_user_id(), 'alert_id' => $id], ['read_at' => now()]);
        return response()->json(['message' => 'Notifications marked as read for your account.']);
    }
}
