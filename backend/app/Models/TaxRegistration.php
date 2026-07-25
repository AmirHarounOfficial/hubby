<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant's tax registration / legal seller identity (spec 05 §4.3). */
class TaxRegistration extends Model
{
    protected $fillable = [
        'organization_id', 'country_code', 'legal_name', 'legal_name_ar', 'vat_number',
        'identification_scheme', 'identification_value', 'street', 'building_number',
        'additional_street', 'district', 'city', 'postal_zone', 'province', 'default_tax_rate',
        'is_active',
    ];

    protected $casts = [
        'default_tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * KSA VAT numbers are 15 digits, beginning and ending with 3 (ZATCA BR-KSA-39/40). Validating
     * this at capture time avoids discovering it at clearance time, when an invoice is already
     * legally issued.
     */
    public static function isValidKsaVatNumber(?string $vat): bool
    {
        return (bool) preg_match('/^3\d{13}3$/', (string) $vat);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
