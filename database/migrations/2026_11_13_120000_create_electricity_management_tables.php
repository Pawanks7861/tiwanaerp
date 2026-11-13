<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level Utilities → Electricity Management (not project-scoped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_sites', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('state_code', 2)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('electricity_connections', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('utility_site_id')->constrained('utility_sites')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('connection_number', 80);
            $table->string('consumer_number', 80)->nullable();
            $table->string('discom_name', 160)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('tariff_category', 80)->nullable();
            $table->string('voltage_level', 40)->nullable();
            Columns::quantity($table, 'sanctioned_demand')->nullable();
            Columns::quantity($table, 'contract_demand')->nullable();
            $table->string('demand_unit', 20)->default('kVA');
            $table->string('metering_type', 40)->nullable();
            $table->boolean('tod_enabled')->default(false);
            $table->boolean('kwh_enabled')->default(true);
            $table->boolean('kvah_enabled')->default(true);
            Columns::rate($table, 'default_multiplication_factor')->default(1);
            $table->string('billing_cycle', 40)->nullable();
            $table->decimal('low_pf_threshold', 8, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'connection_number']);
            $table->index(['company_id', 'utility_site_id', 'is_active'], 'elec_conn_company_site_active');
        });

        Schema::create('electricity_meters', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('electricity_connection_id')->constrained('electricity_connections')->restrictOnDelete();
            $table->string('meter_number', 80);
            $table->string('meter_type', 40)->nullable();
            $table->string('make', 80)->nullable();
            $table->string('model', 80)->nullable();
            Columns::rate($table, 'multiplication_factor')->default(1);
            $table->date('installed_at')->nullable();
            $table->date('removed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'meter_number']);
            $table->index(['electricity_connection_id', 'is_active'], 'elec_meter_conn_active');
        });

        Schema::create('electricity_tariffs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('electricity_connection_id')->constrained('electricity_connections')->restrictOnDelete();
            $table->string('name', 160);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('tariff_category', 80)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['electricity_connection_id', 'effective_from', 'effective_to'], 'elec_tariff_conn_dates');
        });

        Schema::create('electricity_tariff_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electricity_tariff_id')->constrained('electricity_tariffs')->cascadeOnDelete();
            $table->string('component_code', 60);
            $table->string('component_name', 160);
            $table->string('component_type', 40);
            $table->string('calculation_basis', 40);
            Columns::rate($table, 'rate')->default(0);
            Columns::percent($table, 'percentage')->default(0);
            $table->json('slab_config')->nullable();
            $table->json('tod_slot_config')->nullable();
            $table->json('formula_config')->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_rebate')->default(false);
            $table->boolean('is_tax')->default(false);
            $table->boolean('is_penalty')->default(false);
            $table->boolean('is_manual_adjustment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['electricity_tariff_id', 'component_code'], 'elec_tariff_comp_unique');
        });

        Schema::create('electricity_meter_readings', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('utility_site_id')->constrained('utility_sites')->restrictOnDelete();
            $table->foreignId('electricity_connection_id')->constrained('electricity_connections')->restrictOnDelete();
            $table->foreignId('electricity_meter_id')->constrained('electricity_meters')->restrictOnDelete();
            $table->date('billing_month');
            $table->date('from_date');
            $table->date('to_date');
            Columns::quantity($table, 'opening_kwh')->default(0);
            Columns::quantity($table, 'closing_kwh')->default(0);
            Columns::quantity($table, 'opening_kvah')->nullable();
            Columns::quantity($table, 'closing_kvah')->nullable();
            Columns::quantity($table, 'mdi_reading')->nullable();
            Columns::rate($table, 'multiplication_factor')->default(1);
            $table->unsignedSmallInteger('days')->default(0);
            Columns::quantity($table, 'kwh_consumption')->default(0);
            Columns::quantity($table, 'kvah_consumption')->nullable();
            Columns::quantity($table, 'billing_demand')->nullable();
            $table->decimal('power_factor', 10, 6)->nullable();
            $table->string('status', 30);
            $table->unsignedSmallInteger('revision')->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['electricity_meter_id', 'billing_month', 'revision'], 'elec_reading_meter_month_rev');
            $table->index(['company_id', 'billing_month', 'status'], 'elec_reading_company_month_status');
        });

        Schema::create('electricity_meter_tod_readings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('electricity_meter_reading_id');
            $table->string('slot_code', 40);
            $table->string('slot_name', 80);
            Columns::quantity($table, 'opening')->nullable();
            Columns::quantity($table, 'closing')->nullable();
            Columns::quantity($table, 'units')->default(0);
            $table->timestamps();

            $table->foreign('electricity_meter_reading_id', 'elec_tod_reading_fk')
                ->references('id')->on('electricity_meter_readings')->cascadeOnDelete();
            $table->unique(['electricity_meter_reading_id', 'slot_code'], 'elec_tod_reading_slot');
        });

        Schema::create('electricity_bills', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('utility_site_id')->constrained('utility_sites')->restrictOnDelete();
            $table->foreignId('electricity_connection_id')->constrained('electricity_connections')->restrictOnDelete();
            $table->unsignedBigInteger('electricity_meter_reading_id');
            $table->unsignedBigInteger('electricity_tariff_id')->nullable();
            $table->foreign('electricity_meter_reading_id', 'elec_bill_reading_fk')
                ->references('id')->on('electricity_meter_readings')->restrictOnDelete();
            $table->foreign('electricity_tariff_id', 'elec_bill_tariff_fk')
                ->references('id')->on('electricity_tariffs')->nullOnDelete();
            $table->string('bill_number', 60);
            $table->date('billing_month');
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 30);
            Columns::money($table, 'calculated_total')->default(0);
            Columns::money($table, 'actual_bill_total')->nullable();
            Columns::money($table, 'variance_total')->nullable();
            Columns::rate($table, 'cost_per_kwh')->nullable();
            Columns::rate($table, 'cost_per_kvah')->nullable();
            Columns::percent($table, 'demand_utilization_percent')->nullable();
            Columns::quantity($table, 'excess_demand')->nullable();
            $table->json('tariff_snapshot')->nullable();
            $table->json('reading_snapshot')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'bill_number']);
            $table->unique(['electricity_meter_reading_id'], 'elec_bill_reading_unique');
            $table->index(['company_id', 'billing_month', 'status'], 'elec_bill_company_month_status');
        });

        Schema::create('electricity_bill_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('electricity_bill_id');
            $table->unsignedBigInteger('tariff_component_id')->nullable();
            $table->foreign('electricity_bill_id', 'elec_bill_comp_bill_fk')
                ->references('id')->on('electricity_bills')->cascadeOnDelete();
            $table->foreign('tariff_component_id', 'elec_bill_comp_tariff_fk')
                ->references('id')->on('electricity_tariff_components')->nullOnDelete();
            $table->string('component_code', 60);
            $table->string('component_name', 160);
            $table->string('calculation_basis', 40)->nullable();
            Columns::quantity($table, 'quantity')->nullable();
            Columns::rate($table, 'rate')->nullable();
            Columns::percent($table, 'percentage')->nullable();
            Columns::money($table, 'calculated_amount')->default(0);
            Columns::money($table, 'actual_amount')->nullable();
            Columns::money($table, 'variance_amount')->nullable();
            $table->boolean('is_rebate')->default(false);
            $table->boolean('is_tax')->default(false);
            $table->boolean('is_penalty')->default(false);
            $table->text('remarks')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['electricity_bill_id', 'sort_order'], 'elec_bill_comp_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electricity_bill_components');
        Schema::dropIfExists('electricity_bills');
        Schema::dropIfExists('electricity_meter_tod_readings');
        Schema::dropIfExists('electricity_meter_readings');
        Schema::dropIfExists('electricity_tariff_components');
        Schema::dropIfExists('electricity_tariffs');
        Schema::dropIfExists('electricity_meters');
        Schema::dropIfExists('electricity_connections');
        Schema::dropIfExists('utility_sites');
    }
};
