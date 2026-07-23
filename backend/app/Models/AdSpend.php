<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advertising spend per channel / campaign / day (spec 01 §3.3).
 * Table pinned to `ad_spend` — Laravel would otherwise look for `ad_spends`.
 */
class AdSpend extends Model
{
    use HasFactory;

    protected $table = 'ad_spend';

    public const CHANNELS = [
        'amazon_ads', 'noon_ads', 'salla_ads', 'trendyol_ads',
        'meta', 'google', 'tiktok', 'snapchat', 'other',
    ];

    protected $fillable = [
        'organization_id', 'store_id', 'channel', 'campaign_name', 'campaign_external_id',
        'sku', 'date', 'spend', 'currency', 'fx_rate_to_base', 'spend_base',
        'impressions', 'clicks', 'orders_attributed', 'sales_attributed', 'source',
        'spend_key', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'spend' => 'decimal:4',
        'spend_base' => 'decimal:4',
        'fx_rate_to_base' => 'decimal:8',
        'sales_attributed' => 'decimal:4',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Deterministic idempotency key, so re-importing a day's spend never duplicates. */
    public static function buildSpendKey(
        string $channel,
        ?string $campaignExternalId,
        ?string $sku,
        string $date,
        ?int $storeId,
    ): string {
        return sha1(implode('|', [$channel, $campaignExternalId ?? '', $sku ?? '', $date, $storeId ?? '']));
    }
}
