<?php
namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\DeliveryOrder;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Services\FinanceService;
use App\Support\DocNumber;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    private const NEXT = ['pending' => ['dispatched', 'cancelled'], 'dispatched' => ['delivered'], 'delivered' => [], 'cancelled' => []];

    public function store(Request $r)
    {
        $d = $r->validate(['invoice_id' => 'nullable|exists:invoices,id', 'branch_id' => 'nullable|exists:branches,id', 'vehicle_id' => 'nullable|exists:vehicles,id', 'driver_id' => 'nullable|exists:employees,id',
            'destination' => 'required|string|max:255', 'route' => 'nullable|string|max:255', 'delivery_charge' => 'nullable|numeric|min:0', 'fuel_cost' => 'nullable|numeric|min:0']);
        if ($s = auth_user()->branchScope()) $d['branch_id'] = $s;
        $d['number'] = DocNumber::next('DLV');
        return response()->json(['data' => DeliveryOrder::create($d)], 201);
    }

    public function status(Request $r, int $id, FinanceService $finance)
    {
        $to = $r->validate(['status' => 'required|in:dispatched,delivered,cancelled'])['status'];
        $o = DeliveryOrder::findOrFail($id);
        if (!in_array($to, self::NEXT[$o->status], true)) throw new BusinessRuleException("A {$o->status} delivery cannot become {$to}.");
        $upd = ['status' => $to];
        if ($to === 'dispatched') { $upd['dispatched_at'] = now(); if ($o->vehicle_id) Vehicle::where('id', $o->vehicle_id)->update(['status' => 'in_use']); }
        if ($to === 'delivered') {
            $upd['delivered_at'] = now();
            if ($o->vehicle_id) Vehicle::where('id', $o->vehicle_id)->update(['status' => 'available']);
            if ((float) $o->fuel_cost > 0 && $o->vehicle_id) {
                VehicleLog::create(['vehicle_id' => $o->vehicle_id, 'type' => 'fuel', 'log_date' => now()->toDateString(), 'amount' => $o->fuel_cost, 'note' => "Trip {$o->number}"]);
            }
        }
        $o->update($upd);
        return response()->json(['data' => $o->fresh(['vehicle', 'driver'])]);
    }
}
