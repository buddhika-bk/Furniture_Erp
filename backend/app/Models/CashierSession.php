<?php
namespace App\Models;

class CashierSession extends BaseModel
{
    protected $casts = ['closing_report' => 'array'];
    protected $hidden = ['closing_report'];
    protected $appends = ['report_available'];
    public function getReportAvailableAttribute(): bool { return $this->closing_report !== null; }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
}
