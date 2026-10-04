<?php

namespace App\Services\Quality;

use App\Enums\Quality\InspectionStatus;
use App\Enums\Quality\NcrSeverity;
use App\Enums\Quality\NcrStatus;
use App\Events\Quality\NcrRaised;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityInspection;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NCRs: open → in_progress → resolved → verified → closed; resolved may be sent back to
 * in_progress. The verifier must be someone other than the resolver (separation of duties, also
 * enforced for platform super admins). A closed NCR never changes. No other module is touched.
 */
class NcrService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly QualityInspectionService $inspections,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): Ncr
    {
        return DB::transaction(function () use ($project, $data) {
            $inspection = $this->sourceInspection($project, $data['quality_inspection_id'] ?? null);

            $ncr = new Ncr;
            $ncr->forceFill([
                'project_id' => $project->id,
                'quality_inspection_id' => $inspection?->id,
                'ncr_number' => $this->numbers->next('ncr', $project),
                'status' => NcrStatus::Open,
                ...$this->details($project, $data),
            ]);
            if ($ncr->location === null && $inspection?->location !== null) {
                $ncr->forceFill(['location' => $inspection->location]);
            }
            $ncr->save();
            $ncrId = $ncr->id;
            DB::afterCommit(fn () => NcrRaised::dispatch(Ncr::query()->findOrFail($ncrId)));

            return $ncr;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Ncr $ncr, array $data): Ncr
    {
        return DB::transaction(function () use ($ncr, $data) {
            $locked = $this->lock($ncr);
            $locked->assertEditable();
            $locked->forceFill($this->details($locked->project, $data))->save();

            return $locked;
        });
    }

    public function delete(Ncr $ncr): void
    {
        DB::transaction(function () use ($ncr) {
            $locked = $this->lock($ncr);
            $this->assertStatus($locked, NcrStatus::Open, 'Only an open NCR can be deleted.');
            $locked->delete();
        });
    }

    public function start(Ncr $ncr): void
    {
        $this->transition($ncr, function (Ncr $locked) {
            $this->assertStatus($locked, NcrStatus::Open, 'Only an open NCR can be started.');
            if ($locked->responsible_user_id === null && $locked->subcontractor_id === null) {
                throw ValidationException::withMessages(['responsible_user_id' => 'Assign a responsible person or subcontractor first.']);
            }
            $locked->forceFill(['status' => NcrStatus::InProgress])->save();
        });
    }

    /**
     * @param  array{root_cause: string, corrective_action: string}  $data
     */
    public function resolve(Ncr $ncr, array $data, User $user): void
    {
        $this->transition($ncr, function (Ncr $locked) use ($data, $user) {
            $this->assertStatus($locked, NcrStatus::InProgress, 'Only an NCR in progress can be resolved.');
            $rootCause = trim((string) ($data['root_cause'] ?? ''));
            $action = trim((string) ($data['corrective_action'] ?? ''));
            if ($rootCause === '' || $action === '') {
                throw ValidationException::withMessages([$rootCause === '' ? 'root_cause' : 'corrective_action' => 'Record the root cause and the corrective action.']);
            }

            $locked->forceFill([
                'root_cause' => $rootCause,
                'corrective_action' => $action,
                'status' => NcrStatus::Resolved,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ])->save();
        });
    }

    public function verify(Ncr $ncr, ?string $remarks, User $user): void
    {
        $this->transition($ncr, function (Ncr $locked) use ($remarks, $user) {
            $this->assertStatus($locked, NcrStatus::Resolved, 'Only a resolved NCR can be verified.');
            if ((int) $locked->resolved_by === (int) $user->id) {
                throw ValidationException::withMessages(['ncr' => 'The person who resolved the NCR cannot verify it.']);
            }

            $locked->forceFill([
                'status' => NcrStatus::Verified,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'verification_remarks' => filled($remarks) ? trim($remarks) : null,
            ])->save();
        });
    }

    public function reopen(Ncr $ncr, string $reason, User $user): void
    {
        $this->transition($ncr, function (Ncr $locked) use ($reason, $user) {
            $this->assertStatus($locked, NcrStatus::Resolved, 'Only a resolved NCR can be sent back.');
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'Give the reason the resolution was not accepted.']);
            }

            $locked->forceFill([
                'status' => NcrStatus::InProgress,
                'resolved_by' => null,
                'resolved_at' => null,
                'verification_remarks' => trim($reason),
            ])->save();
            $locked->writeAudit('reopened', null, ['reason' => trim($reason), 'by' => $user->id]);
        });
    }

    public function close(Ncr $ncr, User $user): void
    {
        $this->transition($ncr, function (Ncr $locked) use ($user) {
            $this->assertStatus($locked, NcrStatus::Verified, 'Only a verified NCR can be closed.');
            $locked->forceFill([
                'status' => NcrStatus::Closed,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ])->save();
        });
    }

    /**
     * Completed failed / conditional inspection of the same project, or null for a manual NCR.
     */
    public function sourceInspection(Project $project, mixed $id): ?QualityInspection
    {
        if (blank($id)) {
            return null;
        }

        $inspection = is_numeric($id)
            ? QualityInspection::query()->where('project_id', $project->id)->whereKey((int) $id)->first()
            : null;

        if ($inspection === null || $inspection->status !== InspectionStatus::Completed || ! $inspection->result?->needsFollowUp()) {
            throw ValidationException::withMessages(['quality_inspection_id' => 'Choose a completed failed or conditional inspection of this project.']);
        }

        return $inspection;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(Project $project, array $data): array
    {
        $issue = trim((string) ($data['issue'] ?? ''));
        if ($issue === '') {
            throw ValidationException::withMessages(['issue' => 'Describe the non-conformance.']);
        }
        $severity = NcrSeverity::tryFrom((string) ($data['severity'] ?? ''))
            ?? throw ValidationException::withMessages(['severity' => 'Choose minor, major or critical.']);

        return [
            'issue' => $issue,
            'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
            'severity' => $severity,
            'responsible_user_id' => $this->inspections->projectMember($project, $data['responsible_user_id'] ?? null, 'responsible_user_id'),
            'subcontractor_id' => $this->subcontractor($data['subcontractor_id'] ?? null),
            'target_date' => filled($data['target_date'] ?? null) ? $data['target_date'] : null,
        ];
    }

    private function subcontractor(mixed $id): ?int
    {
        if (blank($id)) {
            return null;
        }

        $found = is_numeric($id) && Subcontractor::query()->active()->whereKey((int) $id)->exists();

        return $found ? (int) $id : throw ValidationException::withMessages(['subcontractor_id' => 'Choose an active subcontractor.']);
    }

    /**
     * @param  callable(Ncr): void  $apply
     */
    private function transition(Ncr $ncr, callable $apply): void
    {
        DB::transaction(function () use ($ncr, $apply) {
            $locked = $this->lock($ncr);
            $apply($locked);
            $ncr->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function assertStatus(Ncr $ncr, NcrStatus $status, string $message): void
    {
        if ($ncr->status !== $status) {
            throw ValidationException::withMessages(['ncr' => $ncr->status === NcrStatus::Closed ? 'A closed NCR cannot be changed.' : $message]);
        }
    }

    private function lock(Ncr $ncr): Ncr
    {
        return Ncr::query()->whereKey($ncr->id)->lockForUpdate()->firstOrFail();
    }
}
