<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct mislabeled mathematical_pi rows that actually snapshotted 22/7.
 * Quantities / lines are NOT recalculated — only the mode label is aligned to the stored coefficient.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('boq_measurement_sheets')
            ->where('circle_constant_mode', 'mathematical_pi')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $snapshot = is_string($row->calculation_profile_snapshot)
                        ? json_decode($row->calculation_profile_snapshot, true)
                        : (array) ($row->calculation_profile_snapshot ?? []);
                    $coeff = (string) ($snapshot['circle_constant_value'] ?? '');
                    // Prior Stage 9A constant for 22/7 practice.
                    if ($coeff === '' || ! str_starts_with($coeff, '3.1428571429')) {
                        continue;
                    }
                    $snapshot['circle_constant_mode'] = 'fraction_22_7';
                    DB::table('boq_measurement_sheets')->where('id', $row->id)->update([
                        'circle_constant_mode' => 'fraction_22_7',
                        'calculation_profile_snapshot' => json_encode($snapshot),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible label correction — quantities were never changed.
    }
};
