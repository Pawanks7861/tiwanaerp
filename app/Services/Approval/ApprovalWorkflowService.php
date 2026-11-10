<?php

namespace App\Services\Approval;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Models\Approval\ApprovalWorkflow;
use App\Support\Approval\ApprovalDocumentTypes;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ApprovalWorkflowService
{
    /**
     * Create or replace the company-wide workflow for one process. In-flight approvals keep
     * the step snapshot taken at submit, so editing here only affects new submissions.
     *
     * @param  array{name: string, is_active: bool, min_amount?: string|null, max_amount?: string|null, steps: list<array<string, mixed>>}  $data
     */
    public function syncCompanyWide(string $type, array $data): ApprovalWorkflow
    {
        if (! ApprovalDocumentTypes::has($type)) {
            throw new InvalidArgumentException("Unknown approval process [{$type}].");
        }

        return DB::transaction(function () use ($type, $data) {
            $workflow = ApprovalWorkflow::query()
                ->where('document_type', $type)
                ->whereNull('project_id')
                ->first();

            $min = filled($data['min_amount'] ?? null) ? Decimal::of($data['min_amount'])->toMoney() : null;
            $max = filled($data['max_amount'] ?? null) ? Decimal::of($data['max_amount'])->toMoney() : null;
            if ($min !== null && $max !== null && Decimal::of($min)->greaterThan($max)) {
                throw ValidationException::withMessages(['max_amount' => 'Maximum amount must be greater than or equal to the minimum amount.']);
            }

            $attributes = [
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
                'min_amount' => $min,
                'max_amount' => $max,
            ];

            $this->assertNoOverlappingBands($type, $min, $max, $workflow?->id);

            if ($workflow === null) {
                $workflow = ApprovalWorkflow::query()->create([
                    'document_type' => $type,
                    'project_id' => null,
                    ...$attributes,
                ]);
            } else {
                $workflow->fill($attributes)->save();
            }

            $workflow->steps()->delete();

            foreach (array_values($data['steps']) as $index => $step) {
                $typeValue = ApproverType::from($step['approver_type']);
                $workflow->steps()->create([
                    'level' => $index + 1,
                    'name' => $step['name'],
                    'approver_type' => $typeValue,
                    'user_id' => $typeValue === ApproverType::User ? $step['user_id'] : null,
                    'role_id' => $typeValue === ApproverType::Role ? $step['role_id'] : null,
                    'project_role' => $typeValue === ApproverType::ProjectRole ? $step['project_role'] : null,
                    'mode' => ApprovalMode::from($step['mode'] ?? ApprovalMode::Any->value),
                ]);
            }

            return $workflow->load(['steps.user:id,name,email', 'steps.role:id,name']);
        });
    }

    /**
     * @return array{workflow: ApprovalWorkflow|null, steps: list<array<string, mixed>>}
     */
    public function preview(string $documentType, ?int $projectId, string $amount): array
    {
        $value = Decimal::of($amount);

        $workflow = ApprovalWorkflow::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->when($projectId, fn ($q) => $q->orWhere('project_id', $projectId)))
            ->with('steps')
            ->get()
            ->filter(function (ApprovalWorkflow $wf) use ($value) {
                return ($wf->min_amount === null || $value->greaterThanOrEqual($wf->min_amount))
                    && ($wf->max_amount === null || $value->lessThanOrEqual($wf->max_amount));
            })
            ->sort(function (ApprovalWorkflow $a, ApprovalWorkflow $b) {
                $byProject = ($b->project_id ? 1 : 0) <=> ($a->project_id ? 1 : 0);

                return $byProject !== 0 ? $byProject : Decimal::of($b->min_amount)->compareTo($a->min_amount);
            })
            ->first();

        return [
            'workflow' => $workflow,
            'steps' => $workflow?->steps->map(fn ($s) => [
                'level' => $s->level,
                'name' => $s->name,
                'approver_type' => $s->approver_type->value,
                'mode' => $s->mode->value,
            ])->values()->all() ?? [],
        ];
    }

    private function assertNoOverlappingBands(string $type, ?string $min, ?string $max, ?int $exceptId): void
    {
        $others = ApprovalWorkflow::query()
            ->where('document_type', $type)
            ->whereNull('project_id')
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['id', 'name', 'min_amount', 'max_amount']);

        foreach ($others as $other) {
            if ($this->bandsOverlap($min, $max, $other->min_amount, $other->max_amount)) {
                throw ValidationException::withMessages([
                    'min_amount' => "Amount range overlaps the active workflow \"{$other->name}\".",
                ]);
            }
        }
    }

    private function bandsOverlap(?string $aMin, ?string $aMax, ?string $bMin, ?string $bMax): bool
    {
        $aLo = $aMin === null ? null : Decimal::of($aMin);
        $aHi = $aMax === null ? null : Decimal::of($aMax);
        $bLo = $bMin === null ? null : Decimal::of($bMin);
        $bHi = $bMax === null ? null : Decimal::of($bMax);

        $startsBeforeEnd = ($aLo === null || $bHi === null || $aLo->lessThanOrEqual($bHi));
        $endsAfterStart = ($bLo === null || $aHi === null || $bLo->lessThanOrEqual($aHi));

        return $startsBeforeEnd && $endsAfterStart;
    }
}
