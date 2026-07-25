<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * An attempt to modify or delete an issued invoice (spec 05 §3.9/§5.9). Issued documents are legally
 * invoices; the only remedy is a credit note.
 */
class ImmutableInvoice extends \RuntimeException
{
    /** @param array<int,string> $fields */
    public function __construct(public readonly string $invoiceNumber, public readonly array $fields = [])
    {
        parent::__construct("Invoice {$invoiceNumber} is issued and cannot be modified. Issue a credit note instead.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'INVOICE_IMMUTABLE',
            'invoice_number' => $this->invoiceNumber,
            'fields' => $this->fields,
        ], 422);
    }
}
