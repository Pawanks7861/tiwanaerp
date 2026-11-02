<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tutorial_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tutorial_key', 80);
            $table->string('tutorial_version', 40);
            $table->string('current_step', 80)->nullable();
            $table->json('completed_steps')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20)->default('not_started'); // not_started|in_progress|completed|exempt
            $table->timestamps();

            $table->unique(['user_id', 'tutorial_key', 'tutorial_version'], 'user_tutorial_unique');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tutorial_progress');
    }
};
