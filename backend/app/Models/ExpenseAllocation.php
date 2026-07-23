<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A materialized daily slice of an expense (spec 01 §3.3). Reporting sums these, never the rules. */
class ExpenseAllocation extends Model
{
    protected $fillable = [
        'organization_id', 'expense_id', 'date', 'amount_base', 'store_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount_base' => 'decimal:4',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
