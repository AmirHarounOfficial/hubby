<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (spec 05 §4.6) — Milestone 0: proper VAT documents, NOT yet ZATCA-cleared.
 *
 * The ZATCA fiscal columns (icv, pih, invoice_hash, qr_base64, xml paths, certificate) exist from the
 * start so Milestone 1 adds cryptography without a data migration, but they stay null until an EGS
 * unit is onboarded. `is_fiscal` states plainly whether a document has been through ZATCA.
 *
 * NO HARD DELETES, EVER (§3.9) — and deliberately no softDeletes either, because a deleted_at column
 * invites a future developer to use it. Cancellation is status='void' PLUS a credit note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_registration_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('invoice_number', 64);
            $table->uuid('uuid');
            $table->enum('document_type', ['invoice', 'credit_note', 'debit_note', 'prepayment'])->default('invoice');
            $table->string('type_code', 3);                 // 388|381|383|386
            $table->enum('subtype', ['standard', 'simplified']);
            $table->char('transaction_code', 7);            // cbc:InvoiceTypeCode/@name
            $table->char('country_code', 2)->default('SA');

            // ZATCA fiscal fields — null until Milestone 1 (cryptography + sandbox).
            $table->boolean('is_fiscal')->default(false);
            $table->unsignedBigInteger('icv')->nullable();
            $table->string('pih', 88)->nullable();
            $table->string('invoice_hash', 88)->nullable();
            $table->text('qr_base64')->nullable();
            $table->string('xml_path', 512)->nullable();
            $table->string('cleared_xml_path', 512)->nullable();
            $table->string('pdf_path', 512)->nullable();

            $table->date('issue_date');
            $table->time('issue_time');
            $table->timestamp('issued_at')->nullable();
            $table->date('supply_date')->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->char('tax_currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 15, 6)->nullable();

            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_name_ar', 255)->nullable();
            $table->string('buyer_vat_number', 20)->nullable();
            $table->string('buyer_identification_scheme', 10)->nullable();
            $table->string('buyer_identification_value', 50)->nullable();
            $table->string('buyer_street', 255)->nullable();
            $table->string('buyer_building_number', 10)->nullable();
            $table->string('buyer_city', 255)->nullable();
            $table->string('buyer_postal_zone', 10)->nullable();
            $table->char('buyer_country_code', 2)->nullable();

            $table->decimal('line_extension_amount', 15, 2)->default(0);  // BT-106
            $table->decimal('allowance_total_amount', 15, 2)->default(0); // BT-107
            $table->decimal('charge_total_amount', 15, 2)->default(0);    // BT-108
            $table->decimal('tax_exclusive_amount', 15, 2)->default(0);   // BT-109
            $table->decimal('tax_amount', 15, 2)->default(0);             // BT-110
            $table->decimal('tax_inclusive_amount', 15, 2)->default(0);   // BT-112
            $table->decimal('prepaid_amount', 15, 2)->default(0);         // BT-113
            $table->decimal('payable_amount', 15, 2)->default(0);         // BT-115

            $table->string('payment_means_code', 3)->nullable();
            $table->string('note_reason', 1000)->nullable();              // KSA-10, required on notes
            $table->string('note_reason_ar', 1000)->nullable();

            $table->enum('status', ['draft', 'issued', 'submitting', 'cleared', 'reported', 'rejected', 'failed', 'superseded', 'void'])->default('draft');
            $table->timestamp('submission_deadline_at')->nullable();
            $table->enum('issuer', ['hubby', 'marketplace', 'external'])->default('hubby');
            $table->string('external_issuer_reference', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'invoice_number']);
            $table->unique('uuid');
            // The primary anti-double-invoicing guard (§7). Nullable order_id permits many NULLs,
            // which is correct for manual invoices.
            $table->unique(['organization_id', 'order_id', 'document_type'], 'invoices_org_order_type_unique');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'issue_date']);
            $table->index(['status', 'submission_deadline_at']);
            $table->index('parent_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
