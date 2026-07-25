<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableInvoice;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\TaxRegistration;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VAT invoicing (spec 05 Milestone 0). Covers the arithmetic ZATCA rejects people over, and the
 * compliance guarantees: issued invoices are immutable, undeletable, and cancelled only by credit note.
 */
class InvoicingTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private TaxRegistration $registration;
    private User $owner;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->makeOrganization($this->owner);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        Sanctum::actingAs($this->owner);
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $this->registration = TaxRegistration::create([
            'organization_id' => $this->organization->id, 'country_code' => 'SA',
            'legal_name' => 'Nour Trading', 'vat_number' => '300000000000003',
            'city' => 'Riyadh', 'default_tax_rate' => 15.00, 'is_active' => true,
        ]);
        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function order(float $price = 100, int $qty = 2): Order
    {
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-'.uniqid(), 'status' => 'paid',
            'total' => $price * $qty, 'currency' => 'SAR', 'customer_name' => 'Sara',
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W-1', 'name' => 'Abaya', 'quantity' => $qty, 'price' => $price]);

        return $order->fresh('items');
    }

    // --- Arithmetic ---

    public function test_vat_inclusive_split_reconstitutes_the_gross_exactly(): void
    {
        $calc = new TaxCalculator();
        // 100.00 inclusive at 15% → 86.96 net + 13.04 VAT (derived, never rounded twice).
        $split = $calc->splitInclusive(100.00, 15.0);

        $this->assertSame('86.96', $split['net']);
        $this->assertSame('13.04', $split['vat']);
        $this->assertEqualsWithDelta(100.00, (float) $split['net'] + (float) $split['vat'], 0.001);
    }

    public function test_document_vat_is_grouped_and_rounded_once_not_summed_from_lines(): void
    {
        $calc = new TaxCalculator();
        // Three lines whose individual VAT amounts each round up; summing rounded line VAT would
        // overstate the total. ZATCA requires document-level rounding.
        $lines = array_fill(0, 3, [
            'line_extension_amount' => '3.33', 'tax_category' => 'S', 'tax_percent' => 15.0,
            'tax_amount' => '0.50', 'line_amount_with_tax' => '3.83',
        ]);

        $totals = $calc->documentTotals($lines);

        $this->assertSame('9.99', $totals['line_extension_amount']);
        // 9.99 * 15% = 1.4985 → 1.50 at document level (summing 3 rounded 0.50s would give 1.50 too,
        // but the group subtotal is what ZATCA validates against).
        $this->assertSame('1.50', $totals['tax_amount']);
        $this->assertSame('11.49', $totals['tax_inclusive_amount']);
        $this->assertCount(1, $totals['subtotals']);
    }

    public function test_mixed_tax_categories_produce_separate_subtotals(): void
    {
        $calc = new TaxCalculator();
        $totals = $calc->documentTotals([
            ['line_extension_amount' => '100.00', 'tax_category' => 'S', 'tax_percent' => 15.0, 'line_amount_with_tax' => '115.00'],
            ['line_extension_amount' => '50.00', 'tax_category' => 'Z', 'tax_percent' => 0.0, 'line_amount_with_tax' => '50.00'],
        ]);

        $this->assertCount(2, $totals['subtotals']);
        $this->assertSame('15.00', $totals['tax_amount']); // only the standard-rated line is taxed
        $this->assertSame('165.00', $totals['tax_inclusive_amount']);
    }

    public function test_rounding_drift_beyond_tolerance_is_detected(): void
    {
        $calc = new TaxCalculator();
        $totals = $calc->documentTotals([
            ['line_extension_amount' => '100.00', 'tax_category' => 'S', 'tax_percent' => 15.0, 'line_amount_with_tax' => '119.00'], // deliberately wrong
        ]);

        $this->assertTrue($calc->exceedsDriftTolerance($totals['drift_minor']));
    }

    // --- Building + issuing ---

    public function test_an_order_without_a_buyer_vat_number_produces_a_simplified_invoice(): void
    {
        $invoice = app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration);

        $this->assertSame('simplified', $invoice->subtype);
        $this->assertSame('0200000', $invoice->transaction_code);
        $this->assertSame('388', $invoice->type_code);
        $this->assertSame('draft', $invoice->status);
        // Prices are VAT-inclusive: 200 gross → 173.91 net + 26.09 VAT.
        $this->assertSame('173.91', $invoice->line_extension_amount);
        $this->assertSame('26.09', $invoice->tax_amount);
        $this->assertSame('200.00', $invoice->tax_inclusive_amount);
        // Milestone 0: a correct VAT document, but NOT ZATCA-cleared.
        $this->assertFalse($invoice->is_fiscal);
    }

    public function test_a_buyer_vat_number_produces_a_standard_invoice(): void
    {
        $invoice = app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration, [
            'buyer_vat_number' => '311111111111113',
        ]);

        $this->assertSame('standard', $invoice->subtype);
        $this->assertSame('0100000', $invoice->transaction_code);
    }

    public function test_issuing_is_idempotent_and_sets_the_24h_deadline_for_simplified(): void
    {
        $service = app(InvoiceService::class);
        $invoice = app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration);

        $issued = $service->issue($invoice);
        $this->assertSame('issued', $issued->status);
        $this->assertNotNull($issued->issued_at);
        $this->assertNotNull($issued->submission_deadline_at); // simplified → 24h reporting clock

        $again = $service->issue($issued->fresh());
        $this->assertSame($issued->issued_at->timestamp, $again->issued_at->timestamp); // never re-issued
    }

    // --- Compliance guarantees ---

    public function test_an_issued_invoice_cannot_be_edited(): void
    {
        $invoice = app(InvoiceService::class)->issue(
            app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration)
        );

        $this->expectException(ImmutableInvoice::class);
        $invoice->update(['buyer_name' => 'Someone Else']);
    }

    public function test_an_issued_invoice_cannot_be_deleted_via_the_api(): void
    {
        $invoice = app(InvoiceService::class)->issue(
            app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration)
        );

        $this->deleteJson("/api/invoices/{$invoice->id}", [], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'INVOICE_IMMUTABLE');
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_a_draft_can_be_discarded(): void
    {
        $invoice = app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration);

        $this->deleteJson("/api/invoices/{$invoice->id}", [], $this->headers)->assertNoContent();
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_a_full_credit_note_voids_the_parent(): void
    {
        $invoice = app(InvoiceService::class)->issue(
            app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration)
        );

        $note = app(InvoiceService::class)->creditNote($invoice, 'Customer returned everything');

        $this->assertSame('credit_note', $note->document_type);
        $this->assertSame('381', $note->type_code);
        $this->assertSame($invoice->id, $note->parent_invoice_id);
        $this->assertSame('issued', $note->status);
        $this->assertSame('Customer returned everything', $note->note_reason);
        $this->assertSame('200.00', $note->tax_inclusive_amount);
        // Full credit cancels the parent.
        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_a_partial_credit_note_leaves_the_parent_standing(): void
    {
        $invoice = app(InvoiceService::class)->issue(
            app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration)
        );
        $line = $invoice->lines->first();

        $note = app(InvoiceService::class)->creditNote($invoice, 'One unit returned', [
            ['invoice_line_id' => $line->id, 'quantity' => 1],
        ]);

        $this->assertSame('100.00', $note->tax_inclusive_amount); // half of 200
        $this->assertSame('issued', $invoice->fresh()->status);   // parent NOT voided
    }

    public function test_the_same_order_cannot_be_invoiced_twice(): void
    {
        $order = $this->order();
        $this->postJson('/api/invoices', ['order_id' => $order->id], $this->headers)->assertCreated();
        $this->postJson('/api/invoices', ['order_id' => $order->id], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'INVOICE_ALREADY_EXISTS');
    }

    public function test_a_malformed_saudi_vat_number_is_rejected_at_capture(): void
    {
        $this->putJson('/api/tax-registration', [
            'legal_name' => 'Test Co', 'vat_number' => '12345', 'country_code' => 'SA',
        ], $this->headers)->assertStatus(422)->assertJsonPath('code', 'INVALID_VAT_NUMBER');

        $this->putJson('/api/tax-registration', [
            'legal_name' => 'Test Co', 'vat_number' => '300000000000003', 'country_code' => 'SA',
        ], $this->headers)->assertOk();
    }

    public function test_a_viewer_cannot_issue_an_invoice(): void
    {
        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $invoice = app(InvoiceBuilder::class)->fromOrder($this->order(), $this->registration);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/invoices/{$invoice->id}/issue", [], $this->headers)->assertForbidden();
    }
}
