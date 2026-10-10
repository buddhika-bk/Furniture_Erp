<?php
namespace App\Models;

class Expense extends BaseModel
{
    public function category() { return $this->belongsTo(ExpenseCategory::class, "expense_category_id"); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function account() { return $this->belongsTo(Account::class); }
}
