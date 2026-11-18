<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->foreignId('concrete_mix_profile_id')->nullable()->after('simple_materials_initialized')
                ->constrained('concrete_mix_profiles')->nullOnDelete();
            $table->json('concrete_mix_profile_snapshot')->nullable()->after('concrete_mix_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concrete_mix_profile_id');
            $table->dropColumn('concrete_mix_profile_snapshot');
        });
    }
};
