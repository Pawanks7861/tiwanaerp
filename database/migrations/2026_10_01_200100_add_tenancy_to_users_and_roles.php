<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 20)->nullable()->after('email');
            $table->foreignId('current_company_id')->nullable()->after('mobile')
                ->constrained('companies')->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_super_admin');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            Columns::blame($table, withDeletedBy: false);
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('user_type', 20)->default('staff');
            $table->nullableMorphs('party');
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('guard_name');
            $table->boolean('is_system')->default(false)->after('description');
            $table->foreign('team_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn(['description', 'is_system']);
        });

        Schema::dropIfExists('company_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_company_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'mobile', 'current_company_id', 'is_super_admin', 'is_active',
                'last_login_at', 'created_by', 'updated_by',
            ]);
        });
    }
};
