<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\TaxRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a draft invoice from an order (spec 05 §5, Milestone 0).
 *
 * Standard vs simplified (§5.4) is decided by the presence of a buyer VAT number: a registered
 * business buyer gets a standard invoice (which in Phase 2 requires clearance), a consumer gets a
 * simplified one. Getting this wrong changes the legal flow, so it is derived, never guessed.
 *
 * Milestone 0 note: the document is a correct VAT invoice but NOT ZATCA-cleared (`is_fiscal` false).
 */
class InvoiceBuilder
{
    public function __construct(private readonly TaxCalculator $tax)
    {
    }

    /**
     * @param array<string, mixed> $attrs buyer_* overrides, note_reason, document_type…
     */
    public function fromOrder(Order $order, TaxRegistration $registration, array $attrs = []): Invoice
    {
        $order->loadMissing('items', 'store');
        $organization = $registration->organization;

        $ratePercent = (float) $registration->default_tax_rate;
        $pricesIncludeTax = (bool) ($organization?->prices_include_vat ?? true);

        $buyerVat = $attrs['buyer_vat_number'] ?? null;
        $subtype = $buyerVat ? 'standard' : 'simplified';
        $documentType = $attrs['document_type'] ?? 'invoice';

        return DB::transaction(function () use ($order, $registration, $attrs, $ratePercent, $pricesIncludeTax, $buyerVat, $subtype, $documentType) {
            $lines = [];
            $lineNumber = 1;

            foreach ($order->items as $item) {
                $quantity = (float) $item->quantity;
                $gross = (float) $item->price * $quantity;

                $split = $pricesIncludeTax
                    ? $this->tax->splitInclusive($gross, $ratePercent)
                    : $this->tax->splitExclusive($gross, $ratePercent);

                $net = $split['net'];
                $vat = $split['vat'];

                $lines[] = [
                    'line_number' => $lineNumber++,
                    'order_item_id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'quantity' => $quantity,
                    'unit_price' => $quantity > 0 ? round((float) $net / $quantity, 4) : 0,
                    'line_extension_amount' => $net,
                    'tax_category' => 'S',
                    'tax_percent' => $ratePercent,
                    'tax_amount' => $vat,
                    'line_amount_with_tax' => number_format((float) $net + (float) $vat, 2, '.', ''),
                ];
            }

            $totals = $this->tax->documentTotals($lines);

            if ($this->tax->exceedsDriftTolerance($totals['drift_minor'])) {
                // Silent rounding drift is the most common cause of ZATCA rejection — refuse early.
                throw new \RuntimeException('INVOICE_ROUNDING_DRIFT');
            }

            $now = now();
            $invoice = Invoice::create(array_merge([
                'organization_id' => $registration->organization_id,
                'tax_registration_id' => $registration->id,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'invoice_number' => $this->nextInvoiceNumber((int) $registration->organization_id),
                'uuid' => (string) Str::uuid(),
                'document_type' => $documentType,
                'type_code' => $this->typeCodeFor($documentType),
                'subtype' => $subtype,
                'transaction_code' => $subtype === 'standard' ? '0100000' : '0200000',
                'country_code' => $registration->country_code,
                'is_fiscal' => false, // Milestone 0 — not ZATCA-cleared
                'issue_date' => $now->toDateString(),
                'issue_time' => $now->format('H:i:s'),
                'currency_code' => $order->currency ?? 'SAR',
                'tax_currency_code' => 'SAR',
                'buyer_name' => $order->customer_name,
                'buyer_vat_number' => $buyerVat,
                'line_extension_amount' => $totals['line_extension_amount'],
                'tax_exclusive_amount' => $totals['tax_exclusive_amount'],
                'tax_amount' => $totals['tax_amount'],
                'tax_inclusive_amount' => $totals['tax_inclusive_amount'],
                'payable_amount' => $totals['payable_amount'],
                'status' => 'draft',
                'issuer' => 'hubby',
            ], array_intersect_key($attrs, array_flip([
                'buyer_name', 'buyer_name_ar', 'buyer_street', 'buyer_building_number', 'buyer_city',
                'buyer_postal_zone', 'buyer_country_code', 'buyer_identification_scheme',
                'buyer_identification_value', 'note_reason', 'note_reason_ar', 'supply_date',
                'parent_invoice_id', 'issuer', 'external_issuer_reference', 'created_by',
            ]))));

            foreach ($lines as $line) {
                InvoiceLine::create(array_merge($line, [
                    'invoice_id' => $invoice->id,
                    'organization_id' => $registration->organization_id,
                ]));
            }

            return $invoice->fresh('lines');
        });
    }

    private function typeCodeFor(string $documentType): string
    {
        return match ($documentType) {
            'credit_note' => '381',
            'debit_note' => '383',
            'prepayment' => '386',
            default => '388',
        };
    }

    /** INV-YYYY-NNNNNN, sequential per organization (BT-1). */
    private function nextInvoiceNumber(int $organizationId): string
    {
        $seq = Invoice::where('organization_id', $organizationId)->count() + 1;

        return 'INV-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
