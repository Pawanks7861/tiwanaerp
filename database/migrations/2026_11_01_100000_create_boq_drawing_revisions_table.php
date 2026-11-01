<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source drawings of a BOQ. New BOQs must be prepared from currently approved revisions; historical
 * BOQs keep whatever was linked at create/revise time and are never rewritten when a later
 * revision is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_drawing_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('drawing_revision_id')->constrained('drawing_revisions')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['boq_id', 'drawing_revision_id']);
            $table->index('drawing_revision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_drawing_revisions');
    }
};
