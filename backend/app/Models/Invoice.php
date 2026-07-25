<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tax invoice (spec 05 §4.6).
 *
 * Milestone 0: these are proper VAT documents but NOT ZATCA-cleared — `is_fiscal` stays false until
 * an EGS unit is onboarded (Milestone 1+). Never present a non-fiscal invoice as ZATCA-compliant.
 *
 * Immutability (§5.9): only a `draft` may be edited. Anything from `issued` onward is legally an
 * invoice and any write throws — enforced in the model, not just the UI, because §3.9 prohibits
 * modification and a service-layer-only guard is one careless save() away from a compliance breach.
 */
class Invoice extends Model
{
    /** Statuses whose documents are legally issued and therefore immutable. */
    public const IMMUTABLE_STATUSES = ['issued', 'submitting', 'cleared', 'reported', 'rejected', 'failed', 'superseded', 'void'];

    /** Columns that may still change after issuance (lifecycle bookkeeping, not invoice content). */
    private const MUTABLE_AFTER_ISSUE = [
        'status', 'is_fiscal', 'icv', 'pih', 'invoice_hash', 'qr_base64', 'xml_path',
        'cleared_xml_path', 'pdf_path', 'submission_deadline_at', 'updated_at',
    ];

    protected $fillable = [
        'organization_id', 'tax_registration_id', 'store_id', 'order_id', 'parent_invoice_id',
        'invoice_number', 'uuid', 'document_type', 'type_code', 'subtype', 'transaction_code',
        'country_code', 'is_fiscal', 'icv', 'pih', 'invoice_hash', 'qr_base64', 'xml_path',
        'cleared_xml_path', 'pdf_path', 'issue_date', 'issue_time', 'issued_at', 'supply_date',
        'currency_code', 'tax_currency_code', 'exchange_rate', 'buyer_name', 'buyer_name_ar',
        'buyer_vat_number', 'buyer_identification_scheme', 'buyer_identification_value',
        'buyer_street', 'buyer_building_number', 'buyer_city', 'buyer_postal_zone',
        'buyer_country_code', 'line_extension_amount', 'allowance_total_amount',
        'charge_total_amount', 'tax_exclusive_amount', 'tax_amount', 'tax_inclusive_amount',
        'prepaid_amount', 'payable_amount', 'payment_means_code', 'note_reason', 'note_reason_ar',
        'status', 'submission_deadline_at', 'issuer', 'external_issuer_reference', 'created_by',
    ];

    protected $casts = [
        'is_fiscal' => 'boolean',
        'issue_date' => 'date',
        'supply_date' => 'date',
        'issued_at' => 'datetime',
        'submission_deadline_at' => 'datetime',
        'exchange_rate' => 'decimal:6',
        'line_extension_amount' => 'decimal:2',
        'allowance_total_amount' => 'decimal:2',
        'charge_total_amount' => 'decimal:2',
        'tax_exclusive_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_inclusive_amount' => 'decimal:2',
        'prepaid_amount' => 'decimal:2',
        'payable_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            $wasIssued = in_array($invoice->getOriginal('status'), self::IMMUTABLE_STATUSES, true);
            if (! $wasIssued) {
                return;
            }

            $illegal = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_ISSUE);
            if ($illegal !== []) {
                throw new \App\Exceptions\ImmutableInvoice($invoice->invoice_number, $illegal);
            }
        });

        // §3.9 prohibits deletion outright. Cancellation is a credit note plus status = void.
        static::deleting(function (Invoice $invoice) {
            if (in_array($invoice->status, self::IMMUTABLE_STATUSES, true)) {
                throw new \App\Exceptions\ImmutableInvoice($invoice->invoice_number, ['deleted']);
            }
        });
    }

    public function isIssued(): bool
    {
        return in_array($this->status, self::IMMUTABLE_STATUSES, true);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function taxRegistration(): BelongsTo
    {
        return $this->belongsTo(TaxRegistration::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'parent_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'parent_invoice_id');
    }
}
