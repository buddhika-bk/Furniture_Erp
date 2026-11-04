<?php
namespace App\Models;
class ManufacturingRequest extends BaseModel {
    protected $appends = ['progress_status'];
    public function getProgressStatusAttribute(): string {
        return $this->production_order_id ? ($this->productionOrder?->progress_status ?? $this->status) : $this->status;
    }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function productionOrder() { return $this->belongsTo(ProductionOrder::class); }
    public function factoryWarehouse() { return $this->belongsTo(Warehouse::class, 'factory_warehouse_id'); }
}
