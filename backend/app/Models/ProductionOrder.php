<?php
namespace App\Models;

class ProductionOrder extends BaseModel
{
    protected $appends = ['progress_status'];
    public function getProgressStatusAttribute(): string {
        return match ($this->status) { 'draft' => 'ready', 'released' => 'start', 'in_progress' => 'process', default => $this->status };
    }
    public function product() { return $this->belongsTo(Product::class); }
    public function bom() { return $this->belongsTo(Bom::class); }
    public function materials() { return $this->hasMany(ProductionMaterial::class); }
    public function sourceWarehouse() { return $this->belongsTo(Warehouse::class, "source_warehouse_id"); }
    public function targetWarehouse() { return $this->belongsTo(Warehouse::class, "target_warehouse_id"); }
}
