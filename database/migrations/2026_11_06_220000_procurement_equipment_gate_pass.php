<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MR stays independent of BOQ (existing boq_item_id columns are kept).
 * GRN lines may accept more than the PO when a reason is stored.
 * Equipment gains an identifier, location history and an inventory-backed fuel link.
 * Gate passes and weighbridge receipts are new movement documents; they do not post stock by themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            $table->boolean('extra_confirmed')->default(false)->after('remarks');
        });

        Schema::table('grn_items', function (Blueprint $table) {
            Columns::quantity($table, 'po_remaining_qty')->default(0)->after('previously_received_qty');
            Columns::quantity($table, 'extra_qty')->default(0)->after('accepted_qty');
            $table->string('extra_reason', 40)->nullable()->after('extra_qty');
            $table->string('extra_remarks', 500)->nullable()->after('extra_reason');
            Columns::quantity($table, 'supplier_challan_qty')->nullable()->after('extra_remarks');
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->string('serial_no', 50)->nullable()->after('registration_no');
            $table->string('make', 80)->nullable()->after('serial_no');
            $table->string('model', 80)->nullable()->after('make');
            $table->unsignedSmallInteger('manufacture_year')->nullable()->after('model');
            $table->string('location_type', 30)->default('central_store')->after('status');
            $table->foreignId('location_project_id')->nullable()->after('location_type')->constrained('projects')->nullOnDelete();
            $table->string('location_name', 150)->nullable()->after('location_project_id');
            $table->string('location_notes', 500)->nullable()->after('location_name');
            $table->timestamp('location_updated_at')->nullable()->after('location_notes');
            $table->foreignId('location_updated_by')->nullable()->after('location_updated_at')->constrained('users')->nullOnDelete();
            $table->unique(['company_id', 'registration_no']);
            $table->unique(['company_id', 'serial_no']);
        });

        Schema::create('equipment_location_history', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->string('from_location_type', 30)->nullable();
            $table->string('from_location_name', 150)->nullable();
            $table->foreignId('from_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('to_location_type', 30);
            $table->string('to_location_name', 150)->nullable();
            $table->foreignId('to_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->timestamp('changed_at');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['equipment_id', 'changed_at']);
        });

        Schema::table('equipment_fuel_logs', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('project_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->after('material_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('unit_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->after('warehouse_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('material_issue_id')->nullable()->after('site_id')->constrained('material_issues')->nullOnDelete();
            $table->foreignId('material_issue_item_id')->nullable()->after('material_issue_id')->constrained('material_issue_items')->nullOnDelete();
            $table->string('operator_name', 120)->nullable()->after('material_issue_item_id');
            $table->decimal('meter_reading', 14, 2)->nullable()->after('operator_name');
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('pass_number', 40);
            $table->string('direction', 10);
            $table->boolean('returnable')->default(false);
            $table->dateTime('passed_at');
            $table->date('expected_return_date')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('party_name', 150)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vehicle_no', 30)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->string('driver_mobile', 20)->nullable();
            $table->string('purpose', 500);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_no', 60)->nullable();
            $table->string('status', 20);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->string('remarks', 1000)->nullable();
            Columns::blame($table, false);
            $table->timestamps();
            $table->unique(['company_id', 'pass_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('gate_pass_items', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('gate_pass_id')->constrained('gate_passes')->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('material_id')->nullable()->constrained('materials')->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->restrictOnDelete();
            $table->string('description', 300);
            Columns::quantity($table, 'quantity');
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'weight')->nullable();
            $table->string('scrap_type', 80)->nullable();
            Columns::rate($table, 'rate')->nullable();
            $table->string('destination', 150)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('weighbridge_receipts', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('weighable_type', 40);
            $table->unsignedBigInteger('weighable_id');
            $table->string('name', 120)->nullable();
            $table->string('slip_no', 60)->nullable();
            $table->dateTime('weighed_at')->nullable();
            $table->string('vehicle_no', 30)->nullable();
            Columns::quantity($table, 'gross_weight')->nullable();
            Columns::quantity($table, 'tare_weight')->nullable();
            Columns::quantity($table, 'net_weight')->nullable();
            $table->string('weight_unit', 10)->default('kg');
            $table->string('source', 20);
            $table->string('manual_reason', 300)->nullable();
            $table->string('operator_name', 120)->nullable();
            $table->string('remarks', 500)->nullable();
            Columns::blame($table, false);
            $table->timestamps();
            $table->index(['weighable_type', 'weighable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighbridge_receipts');
        Schema::dropIfExists('gate_pass_items');
        Schema::dropIfExists('gate_passes');
        Schema::table('equipment_fuel_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_issue_item_id');
            $table->dropConstrainedForeignId('material_issue_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('material_id');
            $table->dropColumn(['operator_name', 'meter_reading']);
        });
        Schema::dropIfExists('equipment_location_history');
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'registration_no']);
            $table->dropUnique(['company_id', 'serial_no']);
            $table->dropConstrainedForeignId('location_updated_by');
            $table->dropConstrainedForeignId('location_project_id');
            $table->dropColumn(['serial_no', 'make', 'model', 'manufacture_year', 'location_type', 'location_name', 'location_notes', 'location_updated_at']);
        });
        Schema::table('grn_items', function (Blueprint $table) {
            $table->dropColumn(['po_remaining_qty', 'extra_qty', 'extra_reason', 'extra_remarks', 'supplier_challan_qty']);
        });
        Schema::table('grns', function (Blueprint $table) {
            $table->dropColumn('extra_confirmed');
        });
    }
};
