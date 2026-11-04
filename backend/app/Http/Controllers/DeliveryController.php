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
        if (!empty($d['driver_id'])) $this->scoped('employees', $d['driver_id']);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($d) {
            if (!empty($d['invoice_id'])) {
                $invoice = $this->scoped('invoices', $d['invoice_id']);
                $invoice = \App\Models\Invoice::lockForUpdate()->findOrFail($invoice->id);
                if (DeliveryOrder::where('invoice_id', $invoice->id)->where('status', '!=', 'cancelled')->exists()) throw new BusinessRuleException('This invoice already has a delivery. Use Plan delivery to assign its destination, vehicle and driver.');
                $d['branch_id'] = $invoice->branch_id;
                $d['delivery_charge'] = $invoice->delivery_charge;
            }
            $d['number'] = DocNumber::next('DLV');
            return response()->json(['data' => DeliveryOrder::create($d)], 201);
        });
    }

    public function update(Request $r, int $id)
    {
        $this->scoped('delivery_orders', $id);
        $d = $r->validate(['destination'=>'required|string|max:255', 'route'=>'nullable|string|max:255', 'vehicle_id'=>'nullable|exists:vehicles,id', 'driver_id'=>'nullable|exists:employees,id', 'fuel_cost'=>'nullable|numeric|min:0']);
        if (!empty($d['driver_id'])) $this->scoped('employees', $d['driver_id']);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id, $d) {
            $o = DeliveryOrder::lockForUpdate()->findOrFail($id);
            if ($o->status !== 'pending') throw new BusinessRuleException('Only pending deliveries can be planned.');
            $o->update($d);
            return response()->json(['data'=>$o->fresh(['invoice','vehicle','driver'])]);
        });
    }

    public function status(Request $r, int $id, FinanceService $finance)
    {
        $to = $r->validate(['status' => 'required|in:dispatched,delivered,cancelled'])['status'];
        $this->scoped('delivery_orders', $id);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id, $to, $finance) {
        $o = DeliveryOrder::lockForUpdate()->findOrFail($id);
        if (!in_array($to, self::NEXT[$o->status], true)) throw new BusinessRuleException("A {$o->status} delivery cannot become {$to}.");
        $upd = ['status' => $to];
        if ($to === 'dispatched') { $upd['dispatched_at'] = now(); if ($o->vehicle_id) Vehicle::where('id', $o->vehicle_id)->update(['status' => 'in_use']); }
        if ($to === 'delivered') {
            $upd['delivered_at'] = now();
            if ($o->vehicle_id) Vehicle::where('id', $o->vehicle_id)->update(['status' => 'available']);
            if ((float) $o->fuel_cost > 0) {
                if ($o->vehicle_id) {
                $log = VehicleLog::create(['vehicle_id' => $o->vehicle_id, 'type' => 'fuel', 'log_date' => now()->toDateString(), 'amount' => $o->fuel_cost, 'note' => "Trip {$o->number}"]);
                }
                $account = $finance->accountFor($o->branch_id, 'cash');
                $finance->post($account->id, 'out', (float)$o->fuel_cost, 'delivery_fuel', isset($log) ? 'vehicle_log' : 'delivery_order', $log->id ?? $o->id, $o->branch_id, "Fuel for {$o->number}");
            }
        }
        $o->update($upd);
        return response()->json(['data' => $o->fresh(['vehicle', 'driver'])]);
        });
    }
}
