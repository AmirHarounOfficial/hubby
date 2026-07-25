<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One invoice line (spec 05 §4.7). unit_price is tax-exclusive (BT-146). */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'organization_id', 'line_number', 'order_item_id', 'product_variant_id',
        'name', 'name_ar', 'sku', 'quantity', 'unit_code', 'unit_price', 'line_extension_amount',
        'allowance_amount', 'allowance_reason', 'tax_category', 'tax_percent', 'tax_amount',
        'line_amount_with_tax', 'tax_exemption_reason_code', 'tax_exemption_reason',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_extension_amount' => 'decimal:2',
        'allowance_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_amount_with_tax' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
