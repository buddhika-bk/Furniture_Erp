<?php
namespace App\Models;

class VehicleLog extends BaseModel
{
    public function vehicle() { return $this->belongsTo(Vehicle::class); }
}
