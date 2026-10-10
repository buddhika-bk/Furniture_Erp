<?php
namespace App\Models;

class DeliveryOrder extends BaseModel
{
    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function driver() { return $this->belongsTo(Employee::class, "driver_id"); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
