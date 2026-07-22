<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A daily FX rate (spec 01 §3.3, §4.5).
 *
 * Read as: 1 unit of `quote` = `rate` units of `base`.
 */
class FxRate extends Model
{
    use HasFactory;

    protected $fillable = ['base', 'quote', 'date', 'rate', 'source'];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:8',
    ];

    /**
     * Rate to convert `quote` into `base` on (or most recently before) a given date.
     * Same-currency conversions short-circuit to 1 so callers never need to special-case them.
     */
    public static function rateFor(string $base, string $quote, ?string $date = null): ?string
    {
        if (strtoupper($base) === strtoupper($quote)) {
            return '1';
        }

        $rate = static::query()
            ->where('base', strtoupper($base))
            ->where('quote', strtoupper($quote))
            ->when($date, fn ($q) => $q->whereDate('date', '<=', $date))
            ->orderByDesc('date')
            ->value('rate');

        return $rate !== null ? (string) $rate : null;
    }
}
