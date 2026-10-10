<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * External CAD preview tokens are no longer used. Drawings stay on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('external_preview_tokens');
        Schema::dropIfExists('file_external_access');
    }

    public function down(): void
    {
        // The external preview tables are not recreated. Browser viewing replaced that path.
    }
};
