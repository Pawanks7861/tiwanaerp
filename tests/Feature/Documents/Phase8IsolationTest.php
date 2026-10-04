<?php

use App\Models\Core\Attachment;
use App\Models\Projects\ProjectUser;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

test('every Phase 8 record is invisible to another company, even under its own project URL', function () {
    $inspection = $this->failedInspection();
    $ncr = $this->raiseNcr([], $inspection);
    [$drawing, $revision] = $this->makeDrawing();
    [$document, $version] = $this->makeDocument();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.folders.store', $this->project), ['name' => 'Contracts']);

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $otherProject = $this->inCompany($other, fn () => app(ProjectService::class)->create(['name' => 'Other tower', 'project_manager_id' => $otherAdmin->id]));
    $as = fn () => $this->actingInCompany($otherAdmin, $other);

    foreach ([$this->project, $otherProject] as $project) {
        $as()->get(route('projects.inspections.show', [$project, $inspection]))->assertNotFound();
        $as()->post(route('projects.inspections.schedule', [$project, $inspection]), ['inspection_date' => now()->toDateString()])->assertNotFound();
        $as()->get(route('projects.ncrs.show', [$project, $ncr]))->assertNotFound();
        $as()->post(route('projects.ncrs.close', [$project, $ncr]))->assertNotFound();
        $as()->get(route('projects.drawings.show', [$project, $drawing]))->assertNotFound();
        $as()->get(route('projects.drawings.revisions.download', [$project, $drawing, $revision]))->assertNotFound();
        $as()->post(route('projects.drawings.revisions.submit', [$project, $drawing, $revision]))->assertNotFound();
        $as()->get(route('projects.documents.show', [$project, $document]))->assertNotFound();
        $as()->get(route('projects.documents.versions.download', [$project, $document, $version]))->assertNotFound();
    }

    $as()->get(route('projects.inspections.index', $otherProject))->assertOk()->assertInertia(fn ($page) => $page->where('inspections.total', 0));
    $as()->get(route('projects.ncrs.index', $otherProject))->assertOk()->assertInertia(fn ($page) => $page->where('ncrs.total', 0));
    $as()->get(route('projects.drawings.index', $otherProject))->assertOk()->assertInertia(fn ($page) => $page->where('drawings.total', 0));
    $as()->get(route('projects.documents.index', $otherProject))->assertOk()->assertInertia(fn ($page) => $page->where('documents.total', 0)->has('folders', 0));
});

test('inspection and NCR evidence attachments are tenant and project protected', function () {
    $inspection = $this->scheduledInspection();
    $this->actingInCompany($this->qe, $this->company)->post(route('attachments.store'), [
        'attachable_type' => 'quality_inspection',
        'attachable_id' => $inspection->id,
        'file' => UploadedFile::fake()->image('evidence.jpg'),
    ])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->withoutGlobalScopes()->where('attachable_type', 'quality_inspection')->firstOrFail();

    $ncr = $this->raiseNcr();
    $this->actingInCompany($this->engineer, $this->company)->post(route('attachments.store'), [
        'attachable_type' => 'ncr',
        'attachable_id' => $ncr->id,
        'file' => UploadedFile::fake()->create('photo.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $this->actingInCompany($this->engineer, $this->company)->get(route('attachments.download', $attachment))->assertOk();

    $outsider = $this->createMember($this->company, DefaultRoles::QUALITY_ENGINEER);
    $this->actingInCompany($outsider, $this->company)->get(route('attachments.download', $attachment))->assertForbidden();

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $other)->get(route('attachments.download', $attachment))->assertNotFound();
    $this->actingInCompany($otherAdmin, $other)->post(route('attachments.store'), [
        'attachable_type' => 'quality_inspection',
        'attachable_id' => $inspection->id,
        'file' => UploadedFile::fake()->image('x.jpg'),
    ])->assertNotFound();
});

test('project viewers with view_all can read but never act', function () {
    $inspection = $this->requestInspection();
    [$drawing, $revision] = $this->makeDrawing();

    $this->actingInCompany($this->director, $this->company)->get(route('projects.inspections.show', [$this->project, $inspection]))->assertOk();
    $this->actingInCompany($this->director, $this->company)->get(route('projects.drawings.revisions.download', [$this->project, $drawing, $revision]))->assertOk();
    $this->actingInCompany($this->director, $this->company)->post(route('projects.drawings.revisions.submit', [$this->project, $drawing, $revision]))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->delete(route('projects.inspections.destroy', [$this->project, $inspection]))->assertForbidden();

    // Removing the engineer from the team removes access to the project's quality records.
    $this->inCompany($this->company, fn () => app(ProjectService::class)->removeMember(
        $this->project,
        ProjectUser::query()->where('project_id', $this->project->id)->where('user_id', $this->engineer->id)->firstOrFail(),
    ));
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.inspections.show', [$this->project, $inspection]))->assertForbidden();
});

test('quality, drawing and document workflows have no stock, cost, progress, billing or procurement side effects', function () {
    $tables = ['stock_transactions', 'project_cost_ledger', 'progress_entries', 'client_invoices', 'vendor_bills', 'subcontractor_bills', 'purchase_orders', 'material_requests'];
    $count = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $line = $this->approvedBoqLine();
    $before = $count();
    $taskBefore = DB::table('project_tasks')->where('id', $this->task->id)->first();
    $boqBefore = DB::table('boq_items')->where('id', $line->id)->first();

    $failed = $this->completedInspection([2 => ['fail', 'Honeycomb']]);
    $ncr = $this->raiseNcr(['subcontractor_id' => $this->subcontractor->id], $failed);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.start', [$this->project, $ncr]));
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.resolve', [$this->project, $ncr]), ['root_cause' => 'C', 'corrective_action' => 'A']);
    $this->actingInCompany($this->qe, $this->company)->post(route('projects.ncrs.verify', [$this->project, $ncr]));
    $this->actingInCompany($this->qe, $this->company)->post(route('projects.ncrs.close', [$this->project, $ncr]))->assertSessionHasNoErrors();
    [, $revision] = $this->makeDrawing();
    $this->approveRevision($revision);
    $this->makeDocument();

    expect($count())->toBe($before)
        ->and(DB::table('project_tasks')->where('id', $this->task->id)->first())->toEqual($taskBefore)
        ->and(DB::table('boq_items')->where('id', $line->id)->first())->toEqual($boqBefore);
});
