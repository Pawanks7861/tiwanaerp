<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('project_number', 30);
            $table->string('code', 12);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('project_type', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state_code', 2)->nullable();
            Columns::geo($table);
            $table->foreignId('project_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('expected_end_date')->nullable();
            $table->date('actual_end_date')->nullable();
            Columns::money($table, 'contract_value')->default(0);
            $table->string('status', 30)->default('planning');
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'project_number']);
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('address')->nullable();
            Columns::geo($table);
            $table->unsignedInteger('geofence_radius_m')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'is_active']);
        });

        Schema::create('project_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('project_role', 30);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('project_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('type', 20);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('project_settings');
        Schema::dropIfExists('project_users');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('projects');
    }
};
