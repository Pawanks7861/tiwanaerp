<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsShapeCode;
use App\Models\Boq\BoqBbs;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BbsImportService
{
    /**
     * @return array{imported: int, errors: list<array{row: int, message: string}>}
     */
    public function import(BoqBbs $bbs, UploadedFile $file, User $user, BbsService $service): array
    {
        if (! $bbs->isEditable()) {
            throw ValidationException::withMessages(['file' => 'Cannot import into a locked BBS.']);
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Empty spreadsheet.']);
        }

        // Skip header
        array_shift($rows);
        $lines = [];
        $errors = [];
        $rowNum = 1;

        foreach ($rows as $raw) {
            $rowNum++;
            if ($this->rowEmpty($raw)) {
                continue;
            }

            $shape = strtolower(trim((string) ($raw[2] ?? '')));
            if (! BbsShapeCode::tryFrom($shape)) {
                $errors[] = ['row' => $rowNum, 'message' => 'Unsupported shape code.'];

                continue;
            }

            $dia = (int) ($raw[3] ?? 0);
            if ($dia < 1 || $dia > BbsCalculator::MAX_DIAMETER_MM) {
                $errors[] = ['row' => $rowNum, 'message' => 'Invalid diameter.'];

                continue;
            }

            $line = [
                'structure_location' => trim((string) ($raw[0] ?? '')) ?: null,
                'bar_mark' => trim((string) ($raw[1] ?? '')),
                'shape_code' => $shape,
                'diameter_mm' => $dia,
                'number_of_members' => (int) ($raw[4] ?? 0),
                'bars_per_member' => (int) ($raw[5] ?? 0),
                'a_length' => $this->num($raw[6] ?? null),
                'b_length' => $this->num($raw[7] ?? null),
                'c_length' => $this->num($raw[8] ?? null),
                'd_length' => $this->num($raw[9] ?? null),
                'hook_length' => $this->num($raw[10] ?? '0') ?? '0',
                'bend_deduction' => $this->num($raw[11] ?? '0') ?? '0',
                'remarks' => trim((string) ($raw[12] ?? '')) ?: null,
            ];

            if ($line['bar_mark'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => 'Bar mark is required.'];

                continue;
            }

            try {
                BbsCalculator::line($line);
            } catch (ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?: 'Invalid line.';
                $errors[] = ['row' => $rowNum, 'message' => $msg];

                continue;
            }

            $lines[] = $line;
        }

        if ($errors !== []) {
            return ['imported' => 0, 'errors' => $errors];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'No data rows found.']);
        }

        // Merge with existing lines
        $existing = $bbs->lines->map(fn ($l) => [
            'structure_location' => $l->structure_location,
            'bar_mark' => $l->bar_mark,
            'description' => $l->description,
            'diameter_mm' => $l->diameter_mm,
            'number_of_members' => $l->number_of_members,
            'bars_per_member' => $l->bars_per_member,
            'shape_code' => $l->shape_code->value,
            'a_length' => $l->a_length !== null ? (string) $l->a_length : null,
            'b_length' => $l->b_length !== null ? (string) $l->b_length : null,
            'c_length' => $l->c_length !== null ? (string) $l->c_length : null,
            'd_length' => $l->d_length !== null ? (string) $l->d_length : null,
            'e_length' => $l->e_length !== null ? (string) $l->e_length : null,
            'hook_length' => (string) $l->hook_length,
            'bend_deduction' => (string) $l->bend_deduction,
            'remarks' => $l->remarks,
        ])->all();

        $maps = $bbs->steelMaps->map(fn ($m) => [
            'diameter_mm' => $m->diameter_mm,
            'material_id' => $m->material_id,
        ])->all();

        DB::transaction(function () use ($service, $bbs, $existing, $lines, $maps, $user) {
            $service->save($bbs, [
                'title' => $bbs->title,
                'description' => $bbs->description,
                'drawing_revision_id' => $bbs->drawing_revision_id,
                'wastage_percent' => (string) $bbs->wastage_percent,
            ], array_merge($existing, $lines), $maps, $user);
        });

        return ['imported' => count($lines), 'errors' => []];
    }

    private function rowEmpty(array $raw): bool
    {
        foreach ($raw as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    private function num(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }
}
