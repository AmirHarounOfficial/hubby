<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A return reason (spec 03 §3.1). organization_id null = a global seeded reason. */
class ReturnReason extends Model
{
    protected $fillable = [
        'organization_id', 'code', 'group', 'label_en', 'label_ar', 'description_en', 'description_ar',
        'requires_note', 'requires_photo', 'is_defect', 'is_customer_fault', 'default_disposition',
        'visible_in_portal', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'requires_note' => 'boolean',
        'requires_photo' => 'boolean',
        'is_defect' => 'boolean',
        'is_customer_fault' => 'boolean',
        'visible_in_portal' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The five logistics codes carriers report — the RTO mapping targets (spec §4.2). */
    public const RTO_CODES = [
        'delivery_failed', 'address_incorrect', 'customer_unreachable', 'customer_refused', 'cod_payment_refused',
    ];
}
