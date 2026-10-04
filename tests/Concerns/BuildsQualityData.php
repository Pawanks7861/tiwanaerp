<?php

namespace Tests\Concerns;

use App\Enums\ProjectRole;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentVersion;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\Subcontractor;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityInspection;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DrawingService;
use App\Services\Projects\ProjectService;
use App\Services\Quality\NcrService;
use App\Services\Quality\QualityChecklistService;
use App\Services\Quality\QualityInspectionService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8 fixtures on top of the site execution team: a Quality Engineer on both projects, a
 * 10-checkpoint checklist and a subcontractor. Files go to a faked private disk.
 * Site engineer: requests inspections, raises NCRs. QE: performs, verifies, closes. PM: drawings
 * and documents (upload / review / approve, manage folders).
 */
trait BuildsQualityData
{
    use BuildsSiteExecutionData;

    public User $qe;

    public QualityChecklist $checklist;

    public Subcontractor $subcontractor;

    public function setUpQuality(): void
    {
        Storage::fake('private');
        $this->setUpSiteExecution();
        $this->qe = $this->createMember($this->company, DefaultRoles::QUALITY_ENGINEER);

        $this->inCompany($this->company, function () {
            $projects = app(ProjectService::class);
            $projects->assignMember($this->project, $this->qe->id, ProjectRole::Quality);
            $projects->assignMember($this->otherProject, $this->qe->id, ProjectRole::Quality);

            $this->checklist = app(QualityChecklistService::class)->create([
                'name' => 'RCC slab pre-pour',
                'discipline' => 'structural',
                'activity' => 'Slab concreting',
                'items' => array_map(fn (int $n) => ['checkpoint' => "Checkpoint {$n}", 'acceptance_criteria' => "Criterion {$n}"], range(1, 10)),
            ]);

            $sub = new Subcontractor;
            $sub->forceFill(['code' => 'SUB-T1', 'name' => 'Shree Formwork', 'is_active' => true])->save();
            $this->subcontractor = $sub;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function requestInspection(array $data = [], ?User $user = null): QualityInspection
    {
        $user ??= $this->engineer;
        $this->actingAs($user);

        return $this->inCompany($this->company, fn () => app(QualityInspectionService::class)->create($data['project'] ?? $this->project, $data + [
            'quality_checklist_id' => $this->checklist->id,
            'site_id' => $this->site->id,
            'location' => 'Block A slab',
            'task_id' => $this->task->id,
        ], $user));
    }

    public function scheduledInspection(array $data = []): QualityInspection
    {
        $inspection = $this->requestInspection($data);
        $this->actingAs($this->qe);
        $this->inCompany($this->company, fn () => app(QualityInspectionService::class)->schedule($inspection, ['inspection_date' => now()->toDateString(), 'engineer_id' => $this->qe->id]));

        return $inspection->fresh();
    }

    /**
     * Results keyed by item position (1-based) → [result, remark]; unspecified items pass.
     *
     * @param  array<int, array{0: string, 1?: ?string}>  $overrides
     * @return array<int, array{result: string, remark: ?string}>
     */
    public function checkpointResults(QualityInspection $inspection, array $overrides = []): array
    {
        $results = [];
        foreach ($inspection->items()->get()->values() as $index => $item) {
            [$result, $remark] = ($overrides[$index + 1] ?? ['pass']) + [1 => null];
            $results[$item->id] = ['result' => $result, 'remark' => $remark];
        }

        return $results;
    }

    public function completedInspection(array $overrides = [], ?string $result = null, ?string $remarks = null): QualityInspection
    {
        $inspection = $this->scheduledInspection();
        $this->actingAs($this->qe);
        $this->inCompany($this->company, fn () => app(QualityInspectionService::class)->complete($inspection, [
            'items' => $this->checkpointResults($inspection, $overrides),
            'result' => $result,
            'remarks' => $remarks,
        ], $this->qe));

        return $inspection->fresh();
    }

    public function failedInspection(): QualityInspection
    {
        return $this->completedInspection([3 => ['fail', 'Cover blocks missing']]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function raiseNcr(array $data = [], ?QualityInspection $inspection = null): Ncr
    {
        $this->actingAs($this->engineer);

        return $this->inCompany($this->company, fn () => app(NcrService::class)->create($this->project, $data + [
            'quality_inspection_id' => $inspection?->id,
            'issue' => 'Cover blocks missing at grid C4',
            'severity' => 'major',
            'responsible_user_id' => $this->engineer->id,
            'target_date' => now()->addDays(7)->toDateString(),
        ]));
    }

    public function pdf(string $name = 'drawing.pdf', string $body = 'v1'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%{$body}\n1 0 obj << >> endobj\n%%EOF\n");
    }

    /**
     * A real temp-file upload so the server sniffs the MIME type from content (fake uploads report
     * a type guessed from the extension).
     */
    public function sniffedUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'p8');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function dwg(string $name = 'plan.dwg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'AC1032'.str_repeat("\0", 64).'drawing-data');
    }

    /**
     * @return array{0: Drawing, 1: DrawingRevision}
     */
    public function makeDrawing(string $number = 'ARC-GF-101', string $code = 'R0', ?UploadedFile $file = null): array
    {
        $this->actingAs($this->pm);

        return $this->inCompany($this->company, fn () => app(DrawingService::class)->create($this->project, [
            'drawing_number' => $number,
            'title' => 'Ground floor plan',
            'discipline' => 'architectural',
            'revision_code' => $code,
        ], $file ?? $this->pdf(), $this->pm));
    }

    public function latestRevision(Drawing $drawing): DrawingRevision
    {
        return $this->inCompany($this->company, fn () => $drawing->revisions()->firstOrFail());
    }

    /**
     * Unscoped read (includes withdrawn rows) for assertions outside a request.
     */
    public function revisionRow(int $id): DrawingRevision
    {
        return DrawingRevision::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * Submit → review → approve a revision as the PM.
     */
    public function approveRevision(DrawingRevision $revision): void
    {
        $this->actingAs($this->pm);
        $this->inCompany($this->company, function () use ($revision) {
            $service = app(DrawingService::class);
            $service->submit($revision, $this->pm);
            $service->startReview($revision, $this->pm);
            $service->approve($revision, 'OK for construction', $this->pm);
        });
    }

    /**
     * @return array{0: Document, 1: DocumentVersion}
     */
    public function makeDocument(array $data = [], ?UploadedFile $file = null): array
    {
        $this->actingAs($this->pm);

        return $this->inCompany($this->company, fn () => app(DocumentService::class)->create($this->project, $data + [
            'name' => 'Structural specification',
            'category' => 'specification',
        ], $file ?? $this->pdf('spec.pdf'), $this->pm));
    }
}
