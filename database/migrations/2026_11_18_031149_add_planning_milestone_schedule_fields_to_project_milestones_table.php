<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_milestones', function (Blueprint $table) {
            $table->date('planned_start')->nullable()->after('name');
            $table->date('planned_end')->nullable()->after('planned_start');
            $table->foreignId('responsible_user_id')->nullable()->after('billing_percent')->constrained('users')->nullOnDelete();
            $table->string('remarks', 1000)->nullable()->after('responsible_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_milestones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn(['planned_start', 'planned_end', 'remarks']);
        });
    }
};
