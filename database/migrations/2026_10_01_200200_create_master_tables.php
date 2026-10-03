<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 50);
            $table->string('symbol', 20);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'symbol']);
        });

        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('parent_id')->nullable()->constrained('material_categories')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 50);
            Columns::percent($table, 'rate');
            Columns::percent($table, 'cgst_rate');
            Columns::percent($table, 'sgst_rate');
            Columns::percent($table, 'igst_rate');
            Columns::percent($table, 'cess_rate')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('material_category_id')->nullable()->constrained('material_categories')->restrictOnDelete();
            $table->string('item_type', 20)->default('material');
            $table->string('code', 30);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('hsn_sac', 10)->nullable();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            Columns::quantity($table, 'reorder_level')->default(0);
            Columns::rate($table, 'standard_rate')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'material_category_id']);
            $table->index(['company_id', 'name']);
        });

        foreach (['vendors', 'subcontractors'] as $partyTable) {
            Schema::create($partyTable, function (Blueprint $table) use ($partyTable) {
                $table->id();
                Columns::company($table);
                $table->string('code', 30);
                $table->string('name', 200);
                if ($partyTable === 'subcontractors') {
                    $table->string('trade', 100)->nullable();
                }
                $table->string('contact_person', 150)->nullable();
                $table->string('mobile', 20)->nullable();
                $table->string('email')->nullable();
                $table->string('gstin', 15)->nullable();
                $table->string('pan', 10)->nullable();
                $table->char('state_code', 2)->nullable();
                $table->text('address')->nullable();
                $table->string('city', 100)->nullable();
                $table->string('pincode', 10)->nullable();
                $table->string('payment_terms', 255)->nullable();
                $table->string('bank_name', 150)->nullable();
                $table->string('bank_account_no', 30)->nullable();
                $table->string('bank_ifsc', 11)->nullable();
                $table->boolean('is_active')->default(true);
                Columns::blame($table);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
                $table->index(['company_id', 'name']);
                $table->index(['company_id', 'gstin']);
            });
        }

        Schema::create('labour_trades', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 100);
            Columns::money($table, 'default_daily_wage')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('equipment_types', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 100);
            $table->string('cost_head', 20);
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('code', 30);
            $table->string('company_name', 200);
            $table->string('contact_person', 150)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'company_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('equipment_types');
        Schema::dropIfExists('labour_trades');
        Schema::dropIfExists('subcontractors');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('material_categories');
        Schema::dropIfExists('units');
    }
};
