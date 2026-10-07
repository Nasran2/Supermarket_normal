<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Expense extends Model
{
    protected $fillable = ['expense_category_id', 'user_id', 'sale_id', 'sale_payment_id', 'payment_method_id', 'register_id', 'type', 'status', 'expense_date', 'reference', 'description', 'amount'];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:2'];

    protected function expenseDate(): Attribute
    {
        return Attribute::make(set: fn ($value) => Carbon::parse($value)->toDateString());
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment()
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
