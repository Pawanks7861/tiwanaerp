<?php

namespace App\Services\Approval;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Models\Approval\ApprovalWorkflow;
use App\Support\Approval\ApprovalDocumentTypes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApprovalWorkflowService
{
    /**
     * Create or replace the company-wide workflow for one process. In-flight approvals keep
     * the step snapshot taken at submit, so editing here only affects new submissions.
     *
     * @param  array{name: string, is_active: bool, steps: list<array<string, mixed>>}  $data
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

            $attributes = [
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
            ];

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
}
