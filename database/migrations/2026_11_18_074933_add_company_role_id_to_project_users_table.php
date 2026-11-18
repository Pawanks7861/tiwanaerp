<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_users', function (Blueprint $table) {
            $table->foreignId('company_role_id')
                ->nullable()
                ->after('project_role')
                ->constrained('roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_role_id');
        });
    }
};
