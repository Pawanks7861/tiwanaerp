<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work Order PDF presentation fields + company document terms templates.
 * Snapshot columns freeze wording at approval so later template edits do not rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('subject', 255)->nullable()->after('wo_date');
            $table->string('template_profile', 40)->nullable()->after('subject');
            $table->boolean('pdf_hide_quantities')->default(false)->after('template_profile');
            $table->text('contractor_scope')->nullable()->after('scope');
            $table->text('company_scope')->nullable()->after('contractor_scope');
            $table->text('payment_terms')->nullable()->after('company_scope');
            $table->text('safety_terms')->nullable()->after('terms');
            $table->text('penalty_terms')->nullable()->after('safety_terms');
            $table->string('delay_penalty_per_day', 40)->nullable()->after('penalty_terms');
            $table->decimal('maximum_penalty_percent', 8, 4)->nullable()->after('delay_penalty_per_day');
            $table->foreignId('document_terms_template_id')->nullable()->after('maximum_penalty_percent');
            $table->json('terms_snapshot')->nullable()->after('document_terms_template_id');
        });

        Schema::create('document_terms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('document_type', 40); // purchase_order | work_order
            $table->string('template_profile', 40)->nullable();
            $table->text('contractor_scope')->nullable();
            $table->text('company_scope')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('safety_terms')->nullable();
            $table->text('general_terms')->nullable();
            $table->text('penalty_terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'is_active'], 'doc_terms_company_type_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_terms_templates');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'subject', 'template_profile', 'pdf_hide_quantities', 'contractor_scope', 'company_scope',
                'payment_terms', 'safety_terms', 'penalty_terms', 'delay_penalty_per_day',
                'maximum_penalty_percent', 'document_terms_template_id', 'terms_snapshot',
            ]);
        });
    }
};
