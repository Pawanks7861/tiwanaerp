<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 finance (architecture H.14): expenses, petty cash (append-only ledger), client RA bills,
 * vendor bills (3-way match with PO / GRN), unified receipts and payments with allocations, and
 * retention releases. Only approved expenses and approved direct (non-stock) vendor bills write
 * the project cost ledger; payments, petty cash funding and retention releases never do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_accounts', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('holder_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            Columns::money($table, 'limit_amount')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index('holder_user_id');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('expense_number', 40);
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('cost_head', 20);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->string('payee_name', 150)->nullable();
            $table->date('expense_date');
            Columns::money($table, 'amount');
            Columns::money($table, 'tax_amount')->default(0);
            Columns::money($table, 'total_amount');
            $table->string('payment_mode', 20);
            $table->foreignId('petty_cash_account_id')->nullable()->constrained('petty_cash_accounts')->restrictOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->string('description', 1000);
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'expense_number']);
            $table->index(['project_id', 'expense_date']);
            $table->index(['project_id', 'status']);
            $table->index('petty_cash_account_id');
        });

        // Append-only. Signed amounts: postings are positive, a reversal negates the row it cancels.
        // posting_ref is the idempotency key ("expense:12:r0:out", "reversal:44", "fund:<uuid>").
        Schema::create('petty_cash_transactions', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('petty_cash_account_id')->constrained('petty_cash_accounts')->restrictOnDelete();
            $table->date('txn_date');
            $table->string('type', 20);
            Columns::money($table, 'amount');
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('petty_cash_transactions')->restrictOnDelete();
            $table->string('posting_ref', 100)->unique();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['petty_cash_account_id', 'txn_date']);
            $table->index('expense_id');
        });

        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->restrictOnDelete();
            $table->string('invoice_number', 40)->nullable();
            $table->unsignedInteger('ra_sequence');
            $table->date('invoice_date');
            $table->date('period_from');
            $table->date('period_to');
            $table->char('supplier_state', 2);
            $table->char('place_of_supply_state', 2);
            $table->string('tax_type', 10);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            Columns::money($table, 'gross_amount')->default(0);
            Columns::money($table, 'cgst_amount')->default(0);
            Columns::money($table, 'sgst_amount')->default(0);
            Columns::money($table, 'igst_amount')->default(0);
            Columns::money($table, 'tax_amount')->default(0);
            Columns::money($table, 'invoice_total')->default(0);
            Columns::percent($table, 'retention_percent')->default(0);
            Columns::money($table, 'retention_amount')->default(0);
            Columns::money($table, 'advance_recovery')->default(0);
            Columns::percent($table, 'tds_percent')->default(0);
            Columns::money($table, 'tds_amount')->default(0);
            Columns::money($table, 'other_deductions')->default(0);
            Columns::money($table, 'net_payable')->default(0);
            Columns::money($table, 'received_amount')->default(0);
            $table->string('remarks', 1000)->nullable();
            $table->string('status', 20);
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'invoice_number']);
            $table->unique(['project_id', 'ra_sequence']);
            $table->index(['project_id', 'status']);
            $table->index('client_id');
        });

        Schema::create('client_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_invoice_id')->constrained('client_invoices')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->uuid('boq_line_uid');
            $table->string('item_code', 30)->nullable();
            $table->string('description', 500);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'boq_qty');
            Columns::quantity($table, 'executed_qty')->default(0);
            Columns::quantity($table, 'previous_qty')->default(0);
            Columns::quantity($table, 'current_qty')->default(0);
            Columns::quantity($table, 'cumulative_qty')->default(0);
            Columns::rate($table, 'rate');
            Columns::money($table, 'current_amount')->default(0);
            $table->boolean('is_override')->default(false);
            $table->string('override_reason', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['client_invoice_id', 'boq_line_uid'], 'client_invoice_items_line_unique');
            $table->index('boq_line_uid');
        });

        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->string('bill_type', 20);
            $table->string('cost_head', 20)->nullable();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->string('bill_number', 40);
            $table->string('vendor_invoice_no', 60);
            $table->date('vendor_invoice_date');
            $table->date('due_date')->nullable();
            $table->char('place_of_supply_state', 2);
            $table->string('tax_type', 10);
            Columns::money($table, 'subtotal')->default(0);
            Columns::money($table, 'cgst_amount')->default(0);
            Columns::money($table, 'sgst_amount')->default(0);
            Columns::money($table, 'igst_amount')->default(0);
            Columns::money($table, 'total_amount')->default(0);
            Columns::percent($table, 'tds_percent')->default(0);
            Columns::money($table, 'tds_amount')->default(0);
            Columns::money($table, 'net_payable')->default(0);
            Columns::money($table, 'paid_amount')->default(0);
            $table->string('remarks', 1000)->nullable();
            $table->string('status', 20);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();
            // 1 for live rows, NULL once soft deleted, so a deleted draft frees its invoice number.
            $table->unsignedTinyInteger('live_key')->nullable()->storedAs('CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END');

            $table->unique(['company_id', 'bill_number']);
            $table->unique(['vendor_id', 'vendor_invoice_no', 'live_key'], 'vendor_bills_invoice_unique');
            $table->index(['project_id', 'status']);
            $table->index('purchase_order_id');
        });

        Schema::create('vendor_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained('vendor_bills')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('grn_item_id')->nullable()->constrained('grn_items')->restrictOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->restrictOnDelete();
            $table->string('description', 255);
            $table->string('hsn_sac', 10)->nullable();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::rate($table, 'rate');
            Columns::percent($table, 'discount_percent')->default(0);
            Columns::money($table, 'base_amount')->default(0);
            Columns::money($table, 'discount_amount')->default(0);
            Columns::money($table, 'taxable_amount')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            Columns::percent($table, 'cgst_rate')->default(0);
            Columns::money($table, 'cgst_amount')->default(0);
            Columns::percent($table, 'sgst_rate')->default(0);
            Columns::money($table, 'sgst_amount')->default(0);
            Columns::percent($table, 'igst_rate')->default(0);
            Columns::money($table, 'igst_amount')->default(0);
            Columns::money($table, 'amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['vendor_bill_id', 'grn_item_id'], 'vendor_bill_items_grn_unique');
            $table->index('grn_item_id');
            $table->index('purchase_order_item_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('payment_number', 40);
            $table->string('direction', 10);
            $table->string('party_type', 30);
            $table->unsignedBigInteger('party_id');
            $table->date('payment_date');
            $table->string('mode', 20);
            $table->string('bank_reference', 100)->nullable();
            Columns::money($table, 'amount');
            Columns::money($table, 'tds_amount')->default(0);
            $table->string('remarks', 1000)->nullable();
            $table->string('status', 20);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'payment_number']);
            $table->index(['project_id', 'payment_date']);
            $table->index(['party_type', 'party_id']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('payable_type', 30);
            $table->unsignedBigInteger('payable_id');
            Columns::money($table, 'amount');
            $table->timestamps();

            $table->unique(['payment_id', 'payable_type', 'payable_id'], 'payment_allocations_unique');
            $table->index(['payable_type', 'payable_id']);
        });

        Schema::create('retention_releases', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('release_number', 40);
            $table->string('releasable_type', 30);
            $table->unsignedBigInteger('releasable_id');
            $table->date('release_date');
            Columns::money($table, 'amount');
            $table->string('remarks', 1000)->nullable();
            $table->string('status', 20);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'release_number']);
            $table->index(['releasable_type', 'releasable_id']);
            $table->index(['project_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE petty_cash_accounts ADD CONSTRAINT petty_cash_accounts_check CHECK (limit_amount >= 0)');
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_check CHECK (amount > 0 AND tax_amount >= 0 AND total_amount = amount + tax_amount AND (payment_mode <> 'petty_cash' OR petty_cash_account_id IS NOT NULL))");
            DB::statement("ALTER TABLE petty_cash_transactions ADD CONSTRAINT petty_cash_transactions_check CHECK (type IN ('fund_in', 'expense_out', 'return_out') AND ((reverses_id IS NULL AND amount > 0) OR (reverses_id IS NOT NULL AND amount < 0)) AND (type <> 'expense_out' OR expense_id IS NOT NULL))");
            DB::statement('ALTER TABLE client_invoices ADD CONSTRAINT client_invoices_check CHECK (period_to >= period_from AND ra_sequence > 0 AND gross_amount >= 0 AND tax_amount = cgst_amount + sgst_amount + igst_amount AND retention_percent >= 0 AND retention_percent <= 100 AND tds_percent >= 0 AND tds_percent <= 100 AND retention_amount >= 0 AND advance_recovery >= 0 AND tds_amount >= 0 AND other_deductions >= 0 AND net_payable >= 0 AND received_amount >= 0 AND received_amount <= net_payable + retention_amount)');
            DB::statement('ALTER TABLE client_invoice_items ADD CONSTRAINT client_invoice_items_check CHECK (boq_qty >= 0 AND previous_qty >= 0 AND current_qty >= 0 AND cumulative_qty = previous_qty + current_qty AND rate >= 0 AND current_amount >= 0 AND (is_override = 0 OR override_reason IS NOT NULL))');
            DB::statement("ALTER TABLE vendor_bills ADD CONSTRAINT vendor_bills_check CHECK (bill_type IN ('purchase_order', 'direct') AND (bill_type <> 'purchase_order' OR purchase_order_id IS NOT NULL) AND (bill_type <> 'direct' OR cost_head IS NOT NULL) AND subtotal >= 0 AND tds_percent >= 0 AND tds_percent <= 100 AND tds_amount >= 0 AND net_payable >= 0 AND paid_amount >= 0 AND paid_amount <= net_payable AND total_amount = subtotal + cgst_amount + sgst_amount + igst_amount)");
            DB::statement('ALTER TABLE vendor_bill_items ADD CONSTRAINT vendor_bill_items_check CHECK (quantity > 0 AND rate >= 0 AND taxable_amount >= 0 AND amount >= 0 AND ((grn_item_id IS NULL AND purchase_order_item_id IS NULL) OR (grn_item_id IS NOT NULL AND purchase_order_item_id IS NOT NULL)))');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_check CHECK (amount > 0 AND tds_amount >= 0 AND ((direction = 'receipt' AND party_type = 'client') OR (direction = 'payment' AND party_type IN ('vendor', 'subcontractor', 'labour_payment'))) AND status IN ('draft', 'approved', 'cancelled'))");
            DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_check CHECK (amount > 0 AND payable_type IN ('client_invoice', 'vendor_bill', 'subcontractor_bill', 'labour_payment'))");
            DB::statement("ALTER TABLE retention_releases ADD CONSTRAINT retention_releases_check CHECK (amount > 0 AND releasable_type IN ('client_invoice', 'subcontractor_bill'))");
        }
    }

    public function down(): void
    {
        // Polymorphic rows that would point at the dropped documents.
        $requestIds = DB::table('approval_requests')
            ->whereIn('approvable_type', ['expense', 'client_invoice', 'vendor_bill', 'retention_release'])->pluck('id');
        DB::table('approval_actions')->whereIn('approval_request_id', $requestIds)->delete();
        DB::table('approval_requests')->whereIn('id', $requestIds)->delete();
        // Reversal rows reference the rows they cancel, so they go first.
        DB::table('project_cost_ledger')->whereIn('source_type', ['expense', 'vendor_bill_item'])->orderByDesc('id')->delete();

        Schema::dropIfExists('retention_releases');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('vendor_bill_items');
        Schema::dropIfExists('vendor_bills');
        Schema::dropIfExists('client_invoice_items');
        Schema::dropIfExists('client_invoices');
        Schema::dropIfExists('petty_cash_transactions');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('petty_cash_accounts');
    }
};
