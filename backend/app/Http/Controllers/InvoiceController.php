<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TaxRegistration;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Invoices (spec 05 §6.5), Milestone 0 — proper VAT documents, not yet ZATCA-cleared.
 *
 * Note what is absent: there is no destroy(). An issued invoice can never be deleted (§3.9); the
 * only cancellation path is a credit note. A draft can be discarded, and that is the sole delete.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceBuilder $builder,
        private readonly InvoiceService $service,
    ) {
    }

    public function index(Request $request)
    {
        $invoices = Invoice::where('organization_id', $request->header('X-Organization-Id'))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('document_type'), fn ($q, $d) => $q->where('document_type', $d))
            ->when($request->get('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('invoice_number', 'like', "%{$s}%")
                ->orWhere('buyer_name', 'like', "%{$s}%")))
            ->withCount('lines')
            ->latest('id')
            ->paginate(20);

        return response()->json($invoices);
    }

    public function show(Request $request, int $id)
    {
        $invoice = $this->find($request, $id);

        return response()->json($invoice->load([
            'lines' => fn ($q) => $q->orderBy('line_number'),
            'taxRegistration',
            'parent:id,invoice_number',
            'creditNotes:id,parent_invoice_id,invoice_number,tax_inclusive_amount,status',
        ]));
    }

    /** Build a draft invoice from an order. */
    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'buyer_vat_number' => ['nullable', 'string', 'max:20'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_street' => ['nullable', 'string', 'max:255'],
            'buyer_city' => ['nullable', 'string', 'max:255'],
            'buyer_country_code' => ['nullable', 'string', 'size:2'],
            'issue' => ['nullable', 'boolean'],
        ]);

        $registration = TaxRegistration::where('organization_id', $orgId)->where('is_active', true)->first();
        if (! $registration) {
            return response()->json([
                'message' => 'Add your tax registration before invoicing.', 'code' => 'NO_TAX_REGISTRATION',
            ], 422);
        }

        $order = Order::whereHas('store', fn ($q) => $q->where('organization_id', $orgId))
            ->with('items', 'store')->findOrFail($data['order_id']);

        if (Invoice::where('organization_id', $orgId)->where('order_id', $order->id)->where('document_type', 'invoice')->exists()) {
            return response()->json([
                'message' => 'This order already has an invoice.', 'code' => 'INVOICE_ALREADY_EXISTS',
            ], 422);
        }

        try {
            $invoice = $this->builder->fromOrder($order, $registration, array_merge($data, ['created_by' => $request->user()?->id]));
            if ($data['issue'] ?? false) {
                $invoice = $this->service->issue($invoice);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($invoice->load('lines'), 201);
    }

    /** draft → issued. Irreversible. */
    public function issue(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $invoice = $this->find($request, $id);

        try {
            return response()->json($this->service->issue($invoice)->load('lines'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    /** The only way to cancel or refund an issued invoice (§5.8). */
    public function creditNote(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $parent = $this->find($request, $id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        try {
            $note = $this->service->creditNote($parent, $data['reason'], $data['lines'] ?? null, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($note->load('lines'), 201);
    }

    /** Discard a DRAFT only. An issued invoice has no delete path anywhere (§3.9). */
    public function destroy(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $invoice = $this->find($request, $id);

        if ($invoice->isIssued()) {
            return response()->json([
                'message' => 'Issued invoices cannot be deleted. Issue a credit note instead.',
                'code' => 'INVOICE_IMMUTABLE',
            ], 422);
        }

        $invoice->lines()->delete();
        $invoice->delete();

        return response()->json(null, 204);
    }

    // --- Tax registration (the legal seller identity printed on every invoice) ---

    public function taxRegistration(Request $request)
    {
        return response()->json(
            TaxRegistration::where('organization_id', $request->header('X-Organization-Id'))->first()
        );
    }

    public function saveTaxRegistration(Request $request)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'country_code' => ['nullable', 'string', 'size:2'],
            'legal_name' => ['required', 'string', 'max:255'],
            'legal_name_ar' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'identification_scheme' => ['nullable', Rule::in(['CRN', 'MOM', 'MLS', 'SAG', 'OTH'])],
            'identification_value' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:10'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_zone' => ['nullable', 'string', 'max:10'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $country = strtoupper($data['country_code'] ?? 'SA');

        // A malformed KSA VAT number is rejected at capture, not at clearance time when the invoice
        // is already legally issued (BR-KSA-39/40).
        if ($country === 'SA' && ! empty($data['vat_number']) && ! TaxRegistration::isValidKsaVatNumber($data['vat_number'])) {
            return response()->json([
                'message' => 'A Saudi VAT number must be 15 digits starting and ending with 3.',
                'code' => 'INVALID_VAT_NUMBER',
            ], 422);
        }

        $registration = TaxRegistration::updateOrCreate(
            ['organization_id' => $orgId, 'country_code' => $country],
            array_merge($data, ['country_code' => $country, 'is_active' => true]),
        );

        return response()->json($registration);
    }

    private function find(Request $request, int $id): Invoice
    {
        return Invoice::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can manage invoices.');
    }
}
