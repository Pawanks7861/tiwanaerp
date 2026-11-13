<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Civil PO document fields: references, dispatch/delivery, consignee overrides,
 * item brand/make/due date, and an approval-time document snapshot for PDF fidelity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('reference_number', 100)->nullable()->after('delivery_date');
            $table->date('reference_date')->nullable()->after('reference_number');
            $table->text('other_references')->nullable()->after('remarks');
            $table->string('dispatched_through', 150)->nullable()->after('other_references');
            $table->string('destination', 255)->nullable()->after('dispatched_through');
            $table->string('delivery_terms', 255)->nullable()->after('destination');
            $table->string('consignee_name', 255)->nullable()->after('shipping_address');
            $table->string('consignee_email', 150)->nullable()->after('consignee_name');
            $table->string('consignee_gstin', 20)->nullable()->after('consignee_email');
            $table->foreignId('document_terms_template_id')->nullable()->after('terms')
                ->constrained('document_terms_templates')->nullOnDelete();
            $table->json('document_snapshot')->nullable()->after('document_terms_template_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('hsn_sac');
            $table->string('make', 100)->nullable()->after('brand');
            $table->date('due_date')->nullable()->after('make');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_terms_template_id');
            $table->dropColumn([
                'reference_number', 'reference_date', 'other_references', 'dispatched_through',
                'destination', 'delivery_terms', 'consignee_name', 'consignee_email', 'consignee_gstin',
                'document_snapshot',
            ]);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['brand', 'make', 'due_date']);
        });
    }
};
