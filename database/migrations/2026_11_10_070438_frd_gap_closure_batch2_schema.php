<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('client_id')->constrained('departments')->nullOnDelete();
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('procurement_category', 20)->default('material')->after('priority');
            $table->json('equipment_meta')->nullable()->after('remarks');
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('site_id')->constrained('departments')->nullOnDelete();
            $table->string('transporter_name', 150)->nullable()->after('driver_mobile');
            $table->string('transporter_contact', 50)->nullable()->after('transporter_name');
            $table->string('recipient_name', 150)->nullable()->after('transporter_contact');
            $table->string('recipient_contact', 50)->nullable()->after('recipient_name');
            $table->string('permanent_removal_reason', 255)->nullable()->after('purpose');
            $table->text('dispatch_notes')->nullable()->after('permanent_removal_reason');
            $table->text('closure_notes')->nullable()->after('dispatch_notes');
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('source_purchase_order_id')->nullable()->after('owner_vendor_id')->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('source_grn_id')->nullable()->after('source_purchase_order_id')->constrained('grns')->nullOnDelete();
            $table->string('capacity_rating', 100)->nullable()->after('model');
            $table->date('warranty_start')->nullable()->after('purchase_date');
            $table->date('warranty_end')->nullable()->after('warranty_start');
        });

        Schema::create('gate_pass_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('gate_pass_id')->constrained('gate_passes')->cascadeOnDelete();
            $table->date('return_date');
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['gate_pass_id', 'status']);
        });

        Schema::create('gate_pass_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_pass_return_id')->constrained('gate_pass_returns')->cascadeOnDelete();
            $table->foreignId('gate_pass_item_id')->constrained('gate_pass_items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->string('condition', 30)->default('good');
            $table->text('condition_notes')->nullable();
            $table->timestamps();
            $table->unique(['gate_pass_return_id', 'gate_pass_item_id']);
        });

        Schema::create('gate_pass_overdue_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_pass_id')->constrained('gate_passes')->cascadeOnDelete();
            $table->date('alert_date');
            $table->timestamps();
            $table->unique(['gate_pass_id', 'alert_date']);
        });

        Schema::create('scheduled_notification_dedupe', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 80);
            $table->string('document_type', 50);
            $table->unsignedBigInteger('document_id');
            $table->date('alert_date');
            $table->timestamps();
            $table->unique(['event_key', 'document_type', 'document_id', 'alert_date'], 'scheduled_notify_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_notification_dedupe');
        Schema::dropIfExists('gate_pass_overdue_alerts');
        Schema::dropIfExists('gate_pass_return_items');
        Schema::dropIfExists('gate_pass_returns');

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_purchase_order_id');
            $table->dropConstrainedForeignId('source_grn_id');
            $table->dropColumn(['capacity_rating', 'warranty_start', 'warranty_end']);
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'transporter_name', 'transporter_contact', 'recipient_name', 'recipient_contact',
                'permanent_removal_reason', 'dispatch_notes', 'closure_notes',
            ]);
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn(['procurement_category', 'equipment_meta']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
