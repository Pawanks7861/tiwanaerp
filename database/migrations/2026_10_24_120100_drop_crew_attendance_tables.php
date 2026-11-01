<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('crew_attendance_workers');
        Schema::dropIfExists('crew_attendances');
    }

    public function down(): void
    {
        // Replaced by labour_attendance.sync_uuid; the crew tables are not restored.
    }
};
