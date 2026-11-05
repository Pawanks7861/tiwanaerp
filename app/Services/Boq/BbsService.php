<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsShapeCode;
use App\Enums\Boq\BbsStatus;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqBbsLine;
use App\Models\Boq\BoqBbsSteelMap;
use App\Models\Boq\BoqItem;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\Material;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Advanced BOQ Stage 5 — BBS lifecycle (planned steel only; no stock/ledger/Tally).
 */
class BbsService
{
    public function enable(BoqItem $item, User $user): BoqBbs
    {
        $item->loadMissing('boq');
        $boq = $item->boq;
        $boq->assertEditable();

        if ($existing = BoqBbs::query()->where('boq_item_id', $item->id)->first()) {
            return $existing->load(['lines', 'steelMaps.material', 'drawingRevision.drawing']);
        }

        return DB::transaction(function () use ($item, $boq, $user) {
            $item->forceFill(['bbs_applicable' => true])->save();

            $bbs = new BoqBbs;
            $bbs->forceFill([
                'company_id' => $boq->company_id,
                'project_id' => $boq->project_id,
                'boq_id' => $boq->id,
                'boq_item_id' => $item->id,
                'boq_line_uid' => $item->line_uid,
                'title' => 'BBS — '.($item->item_code ?: $item->name),
                'status' => BbsStatus::Draft,
                'wastage_percent' => '0.0000',
                'total_theoretical_weight' => '0.0000',
                'wastage_weight' => '0.0000',
                'required_weight' => '0.0000',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();

            return $bbs->fresh(['lines', 'steelMaps.material', 'drawingRevision.drawing']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{diameter_mm: int, material_id: int|null}>  $maps
     */
    public function save(BoqBbs $bbs, array $header, array $lines, array $maps, User $user): BoqBbs
    {
        return DB::transaction(function () use ($bbs, $header, $lines, $maps, $user) {
            $locked = BoqBbs::query()->whereKey($bbs->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['bbs' => 'Approved or submitted BBS cannot be edited.']);
            }

            $boq = Boq::query()->whereKey($locked->boq_id)->lockForUpdate()->firstOrFail();
            $boq->assertEditable();

            $drawingId = $header['drawing_revision_id'] ?? null;
            if ($drawingId) {
                $this->assertDrawing($locked, (int) $drawingId);
            }

            $wastage = Decimal::of((string) ($header['wastage_percent'] ?? '0'))->round(Decimal::PERCENT_SCALE)->toString();

            $built = [];
            $seq = 0;
            foreach ($lines as $row) {
                $calc = BbsCalculator::line($row);
                $built[] = [
                    'sequence' => $seq++,
                    'structure_location' => $row['structure_location'] ?? null,
                    'bar_mark' => (string) ($row['bar_mark'] ?? ''),
                    'description' => $row['description'] ?? null,
                    'diameter_mm' => (int) $row['diameter_mm'],
                    'number_of_members' => $calc['number_of_members'],
                    'bars_per_member' => $calc['bars_per_member'],
                    'total_bars' => $calc['total_bars'],
                    'shape_code' => $row['shape_code'] instanceof BbsShapeCode
                        ? $row['shape_code']->value
                        : (string) $row['shape_code'],
                    'a_length' => $row['a_length'] ?? null,
                    'b_length' => $row['b_length'] ?? null,
                    'c_length' => $row['c_length'] ?? null,
                    'd_length' => $row['d_length'] ?? null,
                    'e_length' => $row['e_length'] ?? null,
                    'hook_length' => (string) ($row['hook_length'] ?? '0'),
                    'bend_deduction' => (string) ($row['bend_deduction'] ?? '0'),
                    'cutting_length' => $calc['cutting_length'],
                    'total_length' => $calc['total_length'],
                    'unit_weight' => $calc['unit_weight'],
                    'theoretical_weight' => $calc['theoretical_weight'],
                    'remarks' => $row['remarks'] ?? null,
                ];
                if (trim($built[array_key_last($built)]['bar_mark']) === '') {
                    throw ValidationException::withMessages(['bar_mark' => 'Bar mark is required on every line.']);
                }
            }

            $theoByDia = [];
            $lenByDia = [];
            foreach ($built as $b) {
                $d = $b['diameter_mm'];
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus($b['theoretical_weight'])->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus($b['total_length'])->toQuantity();
            }
            $totals = BbsCalculator::headerTotals(array_values($theoByDia), $wastage);

            if ($locked->status === BbsStatus::Rejected) {
                $locked->forceFill([
                    'status' => BbsStatus::Draft,
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);
            }

            $locked->forceFill([
                'title' => $header['title'] ?? $locked->title,
                'description' => $header['description'] ?? $locked->description,
                'drawing_revision_id' => $drawingId,
                'wastage_percent' => $totals['wastage_percent'],
                'total_theoretical_weight' => $totals['total_theoretical_weight'],
                'wastage_weight' => $totals['wastage_weight'],
                'required_weight' => $totals['required_weight'],
                'updated_by' => $user->id,
            ])->save();

            $locked->lines()->delete();
            foreach ($built as $b) {
                $line = new BoqBbsLine;
                $line->forceFill(array_merge($b, ['bbs_id' => $locked->id]))->save();
            }

            $this->syncMaps($locked, $maps, $user, array_keys($theoByDia));

            return $locked->fresh(['lines', 'steelMaps.material', 'drawingRevision.drawing', 'approver', 'submitter']);
        });
    }

    public function submit(BoqBbs $bbs, User $user): BoqBbs
    {
        return DB::transaction(function () use ($bbs, $user) {
            $locked = BoqBbs::query()->whereKey($bbs->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['bbs' => 'Only draft or rejected BBS can be submitted.']);
            }
            if ($locked->lines()->count() === 0) {
                throw ValidationException::withMessages(['lines' => 'Add at least one BBS line before submitting.']);
            }
            Boq::query()->findOrFail($locked->boq_id)->assertEditable();

            $locked->forceFill([
                'status' => BbsStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['lines', 'steelMaps.material']);
        });
    }

    public function approve(BoqBbs $bbs, User $user): BoqBbs
    {
        return DB::transaction(function () use ($bbs, $user) {
            $locked = BoqBbs::query()->whereKey($bbs->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BbsStatus::Submitted) {
                throw ValidationException::withMessages(['bbs' => 'Only submitted BBS can be approved.']);
            }
            if (! config('approvals.allow_self_approval') && (int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['bbs' => 'You cannot approve a BBS you submitted.']);
            }
            Boq::query()->findOrFail($locked->boq_id)->assertEditable();

            // Unmapped diameters do not block approval, but will not contribute to Material Requirement.
            $locked->forceFill([
                'status' => BbsStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['lines', 'steelMaps.material']);
        });
    }

    public function reject(BoqBbs $bbs, User $user, string $reason): BoqBbs
    {
        return DB::transaction(function () use ($bbs, $user, $reason) {
            $locked = BoqBbs::query()->whereKey($bbs->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BbsStatus::Submitted) {
                throw ValidationException::withMessages(['bbs' => 'Only submitted BBS can be rejected.']);
            }
            if (! config('approvals.allow_self_approval') && (int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['bbs' => 'You cannot reject a BBS you submitted.']);
            }

            $locked->forceFill([
                'status' => BbsStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['lines', 'steelMaps.material']);
        });
    }

    /**
     * Map diameters after approval is locked for lines — mapping allowed while editable OR while approved? Spec: approved locked for lines/wastage. Mapping for planning should work before/after? "If no material mapped: Material mapping required" and requirement only when mapped. Allow mapping while draft/rejected; also allow while approved for planning? Spec says approved locked. I'll allow mapping only while editable; require maps before/at submit ideally. Purchase needs maps on approved — allow mapping on approved BBS for material link only (not lines/wastage).
     *
     * @param  list<array{diameter_mm: int, material_id: int|null}>  $maps
     */
    public function saveMaps(BoqBbs $bbs, array $maps, User $user): BoqBbs
    {
        return DB::transaction(function () use ($bbs, $maps, $user) {
            $locked = BoqBbs::query()->whereKey($bbs->id)->lockForUpdate()->firstOrFail();
            $dias = $locked->lines()->pluck('diameter_mm')->unique()->map(fn ($d) => (int) $d)->all();
            $this->syncMaps($locked, $maps, $user, $dias);
            $locked->forceFill(['updated_by' => $user->id])->saveQuietly();

            return $locked->fresh(['lines', 'steelMaps.material']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function workspacePayload(BoqItem $item): array
    {
        $item->loadMissing('boq');
        if (! $item->bbs_applicable) {
            return [
                'enabled' => false,
                'bbs' => null,
                'shape_codes' => BbsShapeCode::options(),
                'formula_note' => $this->formulaNote(),
            ];
        }

        $bbs = BoqBbs::query()
            ->where('boq_item_id', $item->id)
            ->with(['lines', 'steelMaps.material:id,code,name', 'drawingRevision.drawing:id,drawing_number,title', 'approver:id,name', 'submitter:id,name'])
            ->first();

        if (! $bbs) {
            return [
                'enabled' => true,
                'bbs' => null,
                'shape_codes' => BbsShapeCode::options(),
                'formula_note' => $this->formulaNote(),
            ];
        }

        return [
            'enabled' => true,
            'bbs' => $this->bbsPayload($bbs),
            'shape_codes' => BbsShapeCode::options(),
            'formula_note' => $this->formulaNote(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bbsPayload(BoqBbs $bbs): array
    {
        $theoByDia = [];
        $lenByDia = [];
        $totalBars = 0;
        $totalLength = Decimal::zero();
        foreach ($bbs->lines as $line) {
            $d = (int) $line->diameter_mm;
            $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
            $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            $totalBars += (int) $line->total_bars;
            $totalLength = $totalLength->plus((string) $line->total_length);
        }
        $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
        $mapByDia = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

        foreach ($summary as &$row) {
            $map = $mapByDia->get($row['diameter_mm']);
            $row['material_id'] = $map?->material_id;
            $row['material'] = $map?->material
                ? trim(($map->material->code ?? '').' '.($map->material->name ?? ''))
                : null;
            $row['mapping_status'] = $map ? 'mapped' : 'Material mapping required';
            $row['theoretical_weight_mt'] = Decimal::of($row['theoretical_weight'])->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity();
            $row['required_weight_mt'] = Decimal::of($row['required_weight'])->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity();
        }
        unset($row);

        $rev = $bbs->drawingRevision;

        return [
            'id' => $bbs->id,
            'title' => $bbs->title,
            'description' => $bbs->description,
            'status' => $bbs->status->value,
            'status_label' => $bbs->status->label(),
            'wastage_percent' => (string) $bbs->wastage_percent,
            'total_theoretical_weight' => (string) $bbs->total_theoretical_weight,
            'wastage_weight' => (string) $bbs->wastage_weight,
            'required_weight' => (string) $bbs->required_weight,
            'total_theoretical_weight_mt' => Decimal::of((string) $bbs->total_theoretical_weight)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
            'required_weight_mt' => Decimal::of((string) $bbs->required_weight)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
            'total_bars' => $totalBars,
            'total_length' => $totalLength->toQuantity(),
            'drawing_revision_id' => $bbs->drawing_revision_id,
            'drawing_label' => $rev
                ? trim(($rev->drawing?->drawing_number ?? '').' Rev '.$rev->revision_code)
                : null,
            'drawing_viewer' => $bbs->drawing_revision_id
                ? [
                    'source' => 'drawing_revision',
                    'id' => $bbs->drawing_revision_id,
                    'preview_url' => route('files.preview', ['drawing_revision', $bbs->drawing_revision_id]),
                    'stream_url' => route('files.stream', ['drawing_revision', $bbs->drawing_revision_id]),
                ]
                : null,
            'approved_at' => optional($bbs->approved_at)?->toIso8601String(),
            'approver' => $bbs->approver?->only(['id', 'name']),
            'submitter' => $bbs->submitter?->only(['id', 'name']),
            'rejection_reason' => $bbs->rejection_reason,
            'diameter_summary' => $summary,
            'lines' => $bbs->lines->map(fn (BoqBbsLine $line) => [
                'id' => $line->id,
                'structure_location' => $line->structure_location,
                'bar_mark' => $line->bar_mark,
                'description' => $line->description,
                'diameter_mm' => $line->diameter_mm,
                'number_of_members' => $line->number_of_members,
                'bars_per_member' => $line->bars_per_member,
                'total_bars' => $line->total_bars,
                'shape_code' => $line->shape_code->value,
                'a_length' => $line->a_length !== null ? (string) $line->a_length : null,
                'b_length' => $line->b_length !== null ? (string) $line->b_length : null,
                'c_length' => $line->c_length !== null ? (string) $line->c_length : null,
                'd_length' => $line->d_length !== null ? (string) $line->d_length : null,
                'e_length' => $line->e_length !== null ? (string) $line->e_length : null,
                'hook_length' => (string) $line->hook_length,
                'bend_deduction' => (string) $line->bend_deduction,
                'cutting_length' => (string) $line->cutting_length,
                'total_length' => (string) $line->total_length,
                'unit_weight' => (string) $line->unit_weight,
                'theoretical_weight' => (string) $line->theoretical_weight,
                'remarks' => $line->remarks,
            ])->all(),
        ];
    }

    public function formulaNote(): string
    {
        return 'Unit weight kg/m = d²/162 (d mm). Total bars = members × bars/member. '
            .'Cutting length by shape (m) − bend deduction + hook. '
            .'Theoretical kg = total length × unit weight. Wastage applied once at BBS header. '
            .'Approved BBS is authoritative for mapped steel in Material Requirement (replaces Material Norm for those materials).';
    }

    private function assertDrawing(BoqBbs $bbs, int $drawingRevisionId): void
    {
        $rev = DrawingRevision::query()->whereKey($drawingRevisionId)->with('drawing')->first();
        if (! $rev || (int) $rev->company_id !== (int) $bbs->company_id) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Drawing revision not found.']);
        }
        if ((int) $rev->drawing?->project_id !== (int) $bbs->project_id) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Drawing must belong to the same project.']);
        }
        if (! in_array($rev->status, [DrawingRevisionStatus::Approved, DrawingRevisionStatus::Superseded], true)) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Link an approved (or historically approved/superseded) drawing revision.']);
        }
    }

    /**
     * @param  list<array{diameter_mm: int, material_id: int|null}>  $maps
     * @param  list<int>  $diameters
     */
    private function syncMaps(BoqBbs $bbs, array $maps, User $user, array $diameters): void
    {
        $byDia = [];
        foreach ($maps as $m) {
            $d = (int) ($m['diameter_mm'] ?? 0);
            if ($d < 1) {
                continue;
            }
            $byDia[$d] = $m['material_id'] ?? null;
        }

        BoqBbsSteelMap::query()->where('bbs_id', $bbs->id)->delete();

        foreach ($diameters as $d) {
            $mid = $byDia[$d] ?? null;
            if (! $mid) {
                continue;
            }
            $material = Material::query()->whereKey((int) $mid)->first();
            if (! $material || (int) $material->company_id !== (int) $bbs->company_id) {
                throw ValidationException::withMessages(['material_id' => "Material for {$d} mm must belong to this company."]);
            }
            $map = new BoqBbsSteelMap;
            $map->forceFill([
                'bbs_id' => $bbs->id,
                'diameter_mm' => $d,
                'material_id' => $material->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();
        }
    }
}
