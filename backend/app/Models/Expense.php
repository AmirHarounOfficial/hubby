<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A business expense not attributable to a single order (spec 01 §3.3). */
class Expense extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORIES = ['software', 'salary', 'rent', 'marketing', 'logistics', 'packaging', 'bank', 'tax', 'other'];
    public const TYPES = ['one_off', 'recurring'];
    public const RECURRENCES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
    public const ALLOCATION_METHODS = ['none', 'revenue', 'orders', 'units'];

    protected $fillable = [
        'organization_id', 'name', 'category', 'type', 'amount', 'currency',
        'fx_rate_to_base', 'amount_base', 'recurrence', 'starts_on', 'ends_on',
        'amortize', 'allocation_method', 'store_id', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_base' => 'decimal:2',
        'fx_rate_to_base' => 'decimal:8',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'amortize' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
    }
}
