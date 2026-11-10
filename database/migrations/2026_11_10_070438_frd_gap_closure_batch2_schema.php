<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departments')) {
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
        }

        if (! Schema::hasColumn('projects', 'department_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('client_id')->constrained('departments')->nullOnDelete();
            });
        }

        Schema::table('material_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('material_requests', 'procurement_category')) {
                $table->string('procurement_category', 20)->default('material')->after('priority');
            }
            if (! Schema::hasColumn('material_requests', 'equipment_meta')) {
                $table->json('equipment_meta')->nullable()->after('remarks');
            }
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            if (! Schema::hasColumn('gate_passes', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('site_id')->constrained('departments')->nullOnDelete();
            }
            if (! Schema::hasColumn('gate_passes', 'transporter_name')) {
                $table->string('transporter_name', 150)->nullable()->after('driver_mobile');
            }
            if (! Schema::hasColumn('gate_passes', 'transporter_contact')) {
                $table->string('transporter_contact', 50)->nullable()->after('transporter_name');
            }
            if (! Schema::hasColumn('gate_passes', 'recipient_name')) {
                $table->string('recipient_name', 150)->nullable()->after('transporter_contact');
            }
            if (! Schema::hasColumn('gate_passes', 'recipient_contact')) {
                $table->string('recipient_contact', 50)->nullable()->after('recipient_name');
            }
            if (! Schema::hasColumn('gate_passes', 'permanent_removal_reason')) {
                $table->string('permanent_removal_reason', 255)->nullable()->after('purpose');
            }
            if (! Schema::hasColumn('gate_passes', 'dispatch_notes')) {
                $table->text('dispatch_notes')->nullable()->after('permanent_removal_reason');
            }
            if (! Schema::hasColumn('gate_passes', 'closure_notes')) {
                $table->text('closure_notes')->nullable()->after('dispatch_notes');
            }
        });

        Schema::table('equipment', function (Blueprint $table) {
            if (! Schema::hasColumn('equipment', 'source_purchase_order_id')) {
                $table->foreignId('source_purchase_order_id')->nullable()->after('owner_vendor_id')->constrained('purchase_orders')->nullOnDelete();
            }
            if (! Schema::hasColumn('equipment', 'source_grn_id')) {
                $table->foreignId('source_grn_id')->nullable()->after('source_purchase_order_id')->constrained('grns')->nullOnDelete();
            }
            if (! Schema::hasColumn('equipment', 'capacity_rating')) {
                $table->string('capacity_rating', 100)->nullable()->after('model');
            }
            if (! Schema::hasColumn('equipment', 'warranty_start')) {
                $table->date('warranty_start')->nullable()->after('purchase_date');
            }
            if (! Schema::hasColumn('equipment', 'warranty_end')) {
                $table->date('warranty_end')->nullable()->after('warranty_start');
            }
        });

        if (! Schema::hasTable('gate_pass_returns')) {
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
        }

        if (! Schema::hasTable('gate_pass_return_items')) {
            Schema::create('gate_pass_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gate_pass_return_id')->constrained('gate_pass_returns')->cascadeOnDelete();
                $table->foreignId('gate_pass_item_id')->constrained('gate_pass_items')->restrictOnDelete();
                $table->decimal('quantity', 18, 4);
                $table->string('condition', 30)->default('good');
                $table->text('condition_notes')->nullable();
                $table->timestamps();
                // MySQL identifier limit is 64 chars; default unique name exceeds it.
                $table->unique(
                    ['gate_pass_return_id', 'gate_pass_item_id'],
                    'gp_return_items_line_unique'
                );
            });
        }

        if (! Schema::hasTable('gate_pass_overdue_alerts')) {
            Schema::create('gate_pass_overdue_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gate_pass_id')->constrained('gate_passes')->cascadeOnDelete();
                $table->date('alert_date');
                $table->timestamps();
                $table->unique(['gate_pass_id', 'alert_date'], 'gp_overdue_alert_unique');
            });
        }

        if (! Schema::hasTable('scheduled_notification_dedupe')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_notification_dedupe');
        Schema::dropIfExists('gate_pass_overdue_alerts');
        Schema::dropIfExists('gate_pass_return_items');
        Schema::dropIfExists('gate_pass_returns');

        Schema::table('equipment', function (Blueprint $table) {
            if (Schema::hasColumn('equipment', 'source_purchase_order_id')) {
                $table->dropConstrainedForeignId('source_purchase_order_id');
            }
            if (Schema::hasColumn('equipment', 'source_grn_id')) {
                $table->dropConstrainedForeignId('source_grn_id');
            }
            $drop = array_values(array_filter([
                Schema::hasColumn('equipment', 'capacity_rating') ? 'capacity_rating' : null,
                Schema::hasColumn('equipment', 'warranty_start') ? 'warranty_start' : null,
                Schema::hasColumn('equipment', 'warranty_end') ? 'warranty_end' : null,
            ]));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            if (Schema::hasColumn('gate_passes', 'department_id')) {
                $table->dropConstrainedForeignId('department_id');
            }
            $drop = array_values(array_filter([
                Schema::hasColumn('gate_passes', 'transporter_name') ? 'transporter_name' : null,
                Schema::hasColumn('gate_passes', 'transporter_contact') ? 'transporter_contact' : null,
                Schema::hasColumn('gate_passes', 'recipient_name') ? 'recipient_name' : null,
                Schema::hasColumn('gate_passes', 'recipient_contact') ? 'recipient_contact' : null,
                Schema::hasColumn('gate_passes', 'permanent_removal_reason') ? 'permanent_removal_reason' : null,
                Schema::hasColumn('gate_passes', 'dispatch_notes') ? 'dispatch_notes' : null,
                Schema::hasColumn('gate_passes', 'closure_notes') ? 'closure_notes' : null,
            ]));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $drop = array_values(array_filter([
                Schema::hasColumn('material_requests', 'procurement_category') ? 'procurement_category' : null,
                Schema::hasColumn('material_requests', 'equipment_meta') ? 'equipment_meta' : null,
            ]));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        if (Schema::hasColumn('projects', 'department_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropConstrainedForeignId('department_id');
            });
        }

        Schema::dropIfExists('departments');
    }
};
