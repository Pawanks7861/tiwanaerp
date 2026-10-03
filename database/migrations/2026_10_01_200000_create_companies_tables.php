<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('code', 20)->unique();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->unsignedTinyInteger('fy_start_month')->default(4);
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 10);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_years');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('companies');
    }
};
