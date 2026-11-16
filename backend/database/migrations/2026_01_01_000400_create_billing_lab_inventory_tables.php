<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('appointment_id')->nullable()->constrained();
            $t->foreignUlid('consultation_id')->nullable()->constrained();
            $t->string('invoice_number', 32)->nullable();
            $t->date('invoice_date');
            $t->string('status', 16)->default('draft'); // draft|issued|partially_paid|paid|void
            $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('discount_total')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->bigInteger('round_off')->default(0);
            $t->unsignedBigInteger('grand_total')->default(0);
            $t->unsignedBigInteger('amount_paid')->default(0);
            $t->bigInteger('balance_due')->default(0);
            $t->string('discount_status', 16)->default('none'); // none|pending|approved|rejected
            $t->foreignUlid('discount_approved_by')->nullable()->constrained('users');
            $t->string('void_reason')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['organization_id', 'invoice_number']);
            $t->index(['organization_id', 'branch_id', 'invoice_date']);
            $t->index(['organization_id', 'status', 'balance_due']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('item_type', 16)->default('custom'); // service|inventory_item|lab_test|consultation|custom
            $t->ulid('item_id')->nullable();
            $t->string('description');
            $t->string('hsn_sac', 16)->nullable();
            $t->decimal('quantity', 10, 2)->default(1);
            $t->unsignedBigInteger('unit_price');
            $t->string('discount_type', 8)->nullable(); // flat|percent
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->unsignedBigInteger('discount_amount')->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->unsignedBigInteger('tax_amount')->default(0);
            $t->unsignedBigInteger('line_total')->default(0);
            $t->ulid('doctor_profile_id')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'item_type', 'item_id']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->ulid('patient_id');
            $t->string('receipt_number', 32);
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('refunded_amount')->default(0);
            $t->string('method', 16); // cash|upi|card|bank_transfer|wallet|online
            $t->string('reference_no')->nullable();
            $t->string('status', 16)->default('success');
            $t->foreignUlid('received_by')->nullable()->constrained('users');
            $t->timestamp('received_at');
            $t->string('notes')->nullable();
            $t->string('idempotency_key', 64)->nullable()->unique();
            $t->timestamps();
            $t->unique(['organization_id', 'receipt_number']);
            $t->index(['organization_id', 'branch_id', 'received_at']);
            $t->index(['received_by', 'received_at']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('payment_id')->constrained();
            $t->foreignUlid('invoice_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->timestamps();
        });

        Schema::create('refunds', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('payment_id')->constrained();
            $t->foreignUlid('invoice_id')->nullable()->constrained();
            $t->unsignedBigInteger('amount');
            $t->string('reason');
            $t->string('method', 16)->nullable();
            $t->string('status', 16)->default('requested'); // requested|approved|rejected|processed
            $t->foreignUlid('requested_by')->constrained('users');
            $t->foreignUlid('approved_by')->nullable()->constrained('users');
            $t->timestamp('processed_at')->nullable();
            $t->string('credit_note_number', 32)->nullable();
            $t->timestamps();
        });

        Schema::create('cash_closings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->date('date');
            $t->unsignedBigInteger('expected_cash');
            $t->unsignedBigInteger('counted_cash');
            $t->bigInteger('difference');
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'user_id', 'date']);
        });

        Schema::create('lab_tests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->string('loinc_code', 16)->nullable();
            $t->string('sample_type', 32)->nullable();
            $t->unsignedSmallInteger('turnaround_hours')->default(24);
            $t->unsignedBigInteger('price')->default(0);
            $t->json('parameters')->nullable(); // [{key,name,unit,low,high}]
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('lab_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('consultation_id')->nullable()->constrained();
            $t->foreignUlid('doctor_profile_id')->nullable()->constrained();
            $t->string('order_number', 32);
            $t->string('priority', 16)->default('routine');
            $t->string('status', 32)->default('ordered'); // ordered|sample_collected|in_progress|report_ready|released|cancelled
            $t->foreignUlid('invoice_id')->nullable()->constrained();
            $t->foreignUlid('report_document_id')->nullable()->constrained('patient_documents');
            $t->foreignUlid('verified_by')->nullable()->constrained('users');
            $t->timestamp('released_at')->nullable();
            $t->timestamp('shared_with_patient_at')->nullable();
            $t->string('notes')->nullable();
            $t->foreignUlid('ordered_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['organization_id', 'order_number']);
            $t->index(['organization_id', 'branch_id', 'status']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('lab_order_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('lab_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('lab_test_id')->constrained();
            $t->string('status', 32)->default('ordered');
            $t->string('sample_barcode', 32)->nullable();
            $t->timestamp('sample_collected_at')->nullable();
            $t->json('results')->nullable(); // [{key,name,value,unit,flag}]
            $t->foreignUlid('result_entered_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('inventory_categories', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('category_id')->nullable()->constrained('inventory_categories');
            $t->foreignUlid('medicine_id')->nullable()->constrained();
            $t->string('sku', 32);
            $t->string('name');
            $t->string('unit', 16)->default('unit');
            $t->string('hsn', 16)->nullable();
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->unsignedBigInteger('selling_price')->default(0);
            $t->unsignedInteger('reorder_level')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'sku']);
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('name');
            $t->string('gstin', 15)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->json('address')->nullable();
            $t->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('supplier_id')->constrained();
            $t->string('number', 32);
            $t->string('supplier_invoice_ref')->nullable();
            $t->date('received_on');
            $t->unsignedBigInteger('total')->default(0);
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
        });

        Schema::create('stock_batches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('item_id')->constrained('inventory_items');
            $t->string('batch_no', 32);
            $t->date('expiry_date')->nullable();
            $t->integer('qty_on_hand')->default(0);
            $t->unsignedBigInteger('cost_price')->default(0);
            $t->unsignedBigInteger('mrp')->default(0);
            $t->timestamps();
            $t->unique(['branch_id', 'item_id', 'batch_no']);
            $t->index(['organization_id', 'expiry_date']);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('item_id')->constrained('inventory_items');
            $t->foreignUlid('batch_id')->constrained('stock_batches');
            $t->string('type', 16); // purchase|dispense|adjustment|transfer_in|transfer_out|expired
            $t->integer('quantity'); // signed
            $t->string('reference_type')->nullable();
            $t->ulid('reference_id')->nullable();
            $t->string('reason')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['branch_id', 'item_id', 'created_at']);
        });

        Schema::create('notification_templates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained();
            $t->string('event_key', 64);
            $t->string('channel', 16); // whatsapp|sms|email
            $t->string('locale', 8)->default('en');
            $t->string('provider_template_name')->nullable();
            $t->string('dlt_template_id')->nullable();
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('status', 16)->default('approved');
            $t->timestamps();
            $t->unique(['organization_id', 'event_key', 'channel', 'locale'], 'notification_templates_unique');
        });

        Schema::create('notification_messages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id')->nullable();
            $t->string('event_key', 64);
            $t->string('channel', 16);
            $t->string('to_address');
            $t->text('body');
            $t->json('payload')->nullable();
            $t->string('status', 32)->default('queued'); // queued|sent|delivered|read|failed|skipped_no_consent
            $t->string('provider_message_id')->nullable()->index();
            $t->string('error')->nullable();
            $t->string('idempotency_key', 128)->unique();
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
            $t->index(['status', 'scheduled_for']);
        });

        Schema::create('ai_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->string('feature', 64);
            $t->string('provider', 32);
            $t->string('model', 64)->nullable();
            $t->boolean('contains_phi')->default(false);
            $t->text('prompt'); // encrypted
            $t->text('response')->nullable(); // encrypted
            $t->unsignedInteger('tokens_in')->default(0);
            $t->unsignedInteger('tokens_out')->default(0);
            $t->string('status', 16)->default('completed');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
        });

        Schema::create('exports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->string('report', 64);
            $t->string('format', 8);
            $t->json('filters')->nullable();
            $t->string('status', 16)->default('queued');
            $t->string('file_path')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['exports', 'ai_requests', 'notification_messages', 'notification_templates', 'stock_movements', 'stock_batches', 'goods_receipts', 'suppliers', 'inventory_items', 'inventory_categories', 'lab_order_items', 'lab_orders', 'lab_tests', 'cash_closings', 'refunds', 'payment_allocations', 'payments', 'invoice_items', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
