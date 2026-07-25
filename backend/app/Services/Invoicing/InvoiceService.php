<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invoice lifecycle (spec 05 §5.8–5.9), Milestone 0.
 *
 * Two compliance rules shape this class:
 *  - Issuing is irreversible. Once issued, the document is legally an invoice (§5.9).
 *  - An issued invoice can never be deleted or edited; the ONLY way to cancel is a credit note
 *    (§3.9). There is deliberately no delete path for an issued document anywhere in the codebase.
 */
class InvoiceService
{
    public function __construct(private readonly TaxCalculator $tax)
    {
    }

    /** draft → issued. Irreversible. */
    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->isIssued()) {
            return $invoice; // idempotent — never double-issue
        }

        $invoice->loadMissing('lines');

        if ($invoice->lines->isEmpty()) {
            throw new \RuntimeException('INVOICE_HAS_NO_LINES');
        }

        // A standard invoice legally requires buyer identification (BR-KSA-10..14).
        if ($invoice->subtype === 'standard' && ! $invoice->buyer_vat_number) {
            throw new \RuntimeException('STANDARD_INVOICE_REQUIRES_BUYER_VAT');
        }

        // A credit/debit note must carry a reason (KSA-10).
        if (in_array($invoice->document_type, ['credit_note', 'debit_note'], true) && ! $invoice->note_reason) {
            throw new \RuntimeException('NOTE_REQUIRES_REASON');
        }

        $now = now();
        $invoice->forceFill([
            'status' => 'issued',
            'issued_at' => $now,
            // Simplified invoices must reach ZATCA within 24h of issuance (§3.2). Recorded now so the
            // Milestone 1 sweeper has its deadline the moment clearance is switched on.
            'submission_deadline_at' => $invoice->subtype === 'simplified' ? $now->copy()->addDay() : null,
        ])->save();

        return $invoice->fresh('lines');
    }

    /**
     * Issue a credit note against an invoice (§5.8) — the only way to cancel or refund.
     * A full-value credit note also marks the parent void.
     *
     * @param array<int, array{invoice_line_id:int, quantity?:float}>|null $lines null ⇒ full credit
     */
    public function creditNote(Invoice $parent, string $reason, ?array $lines = null, ?int $userId = null): Invoice
    {
        if (! $parent->isIssued()) {
            throw new \RuntimeException('CANNOT_CREDIT_A_DRAFT');
        }
        if ($parent->document_type !== 'invoice') {
            throw new \RuntimeException('CAN_ONLY_CREDIT_AN_INVOICE');
        }

        $parent->loadMissing('lines');

        return DB::transaction(function () use ($parent, $reason, $lines, $userId) {
            $selected = $lines === null
                ? $parent->lines->map(fn ($l) => ['line' => $l, 'quantity' => (float) $l->quantity])->all()
                : collect($lines)->map(function ($row) use ($parent) {
                    $line = $parent->lines->firstWhere('id', $row['invoice_line_id']);
                    if (! $line) {
                        throw new \RuntimeException('CREDIT_LINE_NOT_ON_INVOICE');
                    }
                    $qty = (float) ($row['quantity'] ?? $line->quantity);
                    if ($qty <= 0 || $qty > (float) $line->quantity) {
                        throw new \RuntimeException('CREDIT_QUANTITY_EXCEEDS_LINE');
                    }

                    return ['line' => $line, 'quantity' => $qty];
                })->all();

            $prepared = [];
            $number = 1;
            foreach ($selected as ['line' => $line, 'quantity' => $qty]) {
                $net = round((float) $line->unit_price * $qty, 2);
                $split = $this->tax->splitExclusive($net, (float) $line->tax_percent);

                $prepared[] = [
                    'line_number' => $number++,
                    'order_item_id' => $line->order_item_id,
                    'name' => $line->name,
                    'sku' => $line->sku,
                    'quantity' => $qty,
                    'unit_price' => $line->unit_price,
                    'line_extension_amount' => $split['net'],
                    'tax_category' => $line->tax_category,
                    'tax_percent' => $line->tax_percent,
                    'tax_amount' => $split['vat'],
                    'line_amount_with_tax' => number_format((float) $split['net'] + (float) $split['vat'], 2, '.', ''),
                ];
            }

            $totals = $this->tax->documentTotals($prepared);
            $now = now();

            $note = Invoice::create([
                'organization_id' => $parent->organization_id,
                'tax_registration_id' => $parent->tax_registration_id,
                'store_id' => $parent->store_id,
                // order_id is deliberately NOT copied: the (org, order, document_type) unique guard
                // would otherwise block a second credit note, and partial refunds are legitimate.
                'order_id' => null,
                'parent_invoice_id' => $parent->id,
                'invoice_number' => $this->nextInvoiceNumber((int) $parent->organization_id),
                'uuid' => (string) Str::uuid(),
                'document_type' => 'credit_note',
                'type_code' => '381',
                'subtype' => $parent->subtype,
                'transaction_code' => $parent->transaction_code,
                'country_code' => $parent->country_code,
                'is_fiscal' => false,
                'issue_date' => $now->toDateString(),
                'issue_time' => $now->format('H:i:s'),
                'currency_code' => $parent->currency_code,
                'tax_currency_code' => $parent->tax_currency_code,
                'buyer_name' => $parent->buyer_name,
                'buyer_name_ar' => $parent->buyer_name_ar,
                'buyer_vat_number' => $parent->buyer_vat_number,
                'buyer_street' => $parent->buyer_street,
                'buyer_city' => $parent->buyer_city,
                'buyer_country_code' => $parent->buyer_country_code,
                'line_extension_amount' => $totals['line_extension_amount'],
                'tax_exclusive_amount' => $totals['tax_exclusive_amount'],
                'tax_amount' => $totals['tax_amount'],
                'tax_inclusive_amount' => $totals['tax_inclusive_amount'],
                'payable_amount' => $totals['payable_amount'],
                'note_reason' => $reason,
                'status' => 'draft',
                'issuer' => 'hubby',
                'created_by' => $userId,
            ]);

            foreach ($prepared as $line) {
                InvoiceLine::create(array_merge($line, [
                    'invoice_id' => $note->id,
                    'organization_id' => $parent->organization_id,
                ]));
            }

            $note = $this->issue($note->fresh('lines'));

            // A full-value credit note cancels the parent (§5.8). A partial one leaves it standing.
            if ((float) $note->tax_inclusive_amount >= (float) $parent->tax_inclusive_amount) {
                $parent->forceFill(['status' => 'void'])->save();
            }

            return $note;
        });
    }

    private function nextInvoiceNumber(int $organizationId): string
    {
        $seq = Invoice::where('organization_id', $organizationId)->count() + 1;

        return 'INV-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
