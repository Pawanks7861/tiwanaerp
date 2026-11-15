<?php

namespace App\Services\Boq;

use App\Enums\Boq\MeasurementSheetStatus;
use App\Enums\Boq\QuantitySource;
use App\Models\Boq\Boq;
use App\Models\Imports\DataImport;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Blocks Advanced BOQ technical submission when engineering/costing is incomplete.
 */
class AdvancedBoqTechnicalApprovalValidator
{
    /**
     * @return list<string>
     */
    public function errors(Boq $boq): array
    {
        if (! BoqCompanySettings::enabled()) {
            return [];
        }

        $errors = [];

        $items = $boq->items()->with(['measurementSheets', 'bbs'])->orderBy('id')->get();
        if ($items->isEmpty()) {
            return ['Add at least one BOQ line before submitting for technical approval.'];
        }

        foreach ($items as $item) {
            $label = $item->item_code ? "{$item->item_code} — {$item->name}" : $item->name;

            if (Decimal::of((string) $item->quantity)->isNegative()) {
                $errors[] = "{$label}: quantity cannot be negative.";
            }

            if ($item->quantity_source === QuantitySource::Measurement) {
                $approved = $item->measurementSheets
                    ->first(fn ($sheet) => $sheet->status === MeasurementSheetStatus::Approved);
                if ($approved === null) {
                    $errors[] = "{$label}: measurement quantity requires an approved measurement sheet.";
                } elseif (Decimal::of((string) $item->quantity)->isZero()) {
                    $errors[] = "{$label}: approved measurement quantity is zero.";
                }
            } elseif (Decimal::of((string) $item->quantity)->isZero()) {
                $errors[] = "{$label}: quantity must be greater than zero.";
            }

            if ($item->bbs_applicable) {
                $bbs = $item->bbs;
                if ($bbs === null) {
                    $errors[] = "{$label}: BBS is required but missing.";
                }
            }
        }

        if ($boq->source_import_id) {
            $import = DataImport::query()->find($boq->source_import_id);
            $summaryErrors = $import?->summary['errors'] ?? [];
            if (is_array($summaryErrors) && $summaryErrors !== []) {
                $errors[] = 'Resolve blocking workbook import errors before technical approval.';
            }
        }

        return array_values(array_unique($errors));
    }

    public function assertReady(Boq $boq): void
    {
        $errors = $this->errors($boq);
        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages([
            'boq' => $errors[0],
            'technical_approval' => $errors,
        ]);
    }
}
