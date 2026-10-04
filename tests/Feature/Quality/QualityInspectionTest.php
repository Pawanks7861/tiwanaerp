<?php

use App\Enums\ProjectRole;
use App\Enums\Quality\InspectionResult;
use App\Enums\Quality\InspectionStatus;
use App\Models\Core\Attachment;
use App\Models\Projects\Site;
use App\Models\Quality\QualityInspection;
use App\Services\Projects\ProjectService;
use App\Services\Quality\QualityInspectionService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

test('requesting an inspection numbers it per project and copies the checklist checkpoints', function () {
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.inspections.store', $this->project), [
        'quality_checklist_id' => $this->checklist->id,
        'site_id' => $this->site->id,
        'location' => 'Block A slab',
        'task_id' => $this->task->id,
    ])->assertSessionHasNoErrors();
    $second = $this->requestInspection();

    $first = $this->inCompany($this->company, fn () => QualityInspection::query()->orderBy('id')->firstOrFail());
    expect($first->inspection_number)->toBe("INS-{$this->project->code}-0001")
        ->and($second->inspection_number)->toBe("INS-{$this->project->code}-0002")
        ->and($first->status)->toBe(InspectionStatus::Requested)
        ->and($first->result)->toBeNull()
        ->and($first->requested_by)->toBe($this->engineer->id)
        ->and($first->site_id)->toBe($this->site->id)
        ->and($first->task_id)->toBe($this->task->id);

    $items = $first->items()->get();
    expect($items)->toHaveCount(10)
        ->and($items->pluck('checkpoint')->first())->toBe('Checkpoint 1')
        ->and($items->pluck('acceptance_criteria')->last())->toBe('Criterion 10')
        ->and($items->whereNotNull('result'))->toHaveCount(0);
});

test('task, BOQ item, site, checklist and inspector are validated against the project', function () {
    $otherSite = $this->inCompany($this->company, function () {
        $site = new Site;
        $site->forceFill(['project_id' => $this->otherProject->id, 'name' => 'Tower B site'])->save();

        return $site;
    });
    $otherTask = $this->makeTask(['name' => 'Tower B raft'], $this->otherProject);
    $outsider = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $url = route('projects.inspections.store', $this->project);
    $base = ['quality_checklist_id' => $this->checklist->id];
    $as = fn () => $this->actingInCompany($this->engineer, $this->company);

    $as()->post($url, $base + ['site_id' => $otherSite->id])->assertSessionHasErrors('site_id');
    $as()->post($url, $base + ['task_id' => $otherTask->id])->assertSessionHasErrors('task_id');
    $as()->post($url, $base + ['boq_item_id' => 999999])->assertSessionHasErrors('boq_item_id');
    $as()->post($url, $base + ['engineer_id' => $outsider->id])->assertSessionHasErrors('engineer_id');
    $as()->post($url, ['quality_checklist_id' => 999999])->assertSessionHasErrors('quality_checklist_id');

    $line = $this->approvedBoqLine();
    $as()->post($url, $base + ['boq_item_id' => $line->id, 'engineer_id' => $this->qe->id])->assertSessionHasNoErrors();
    $inspection = $this->inCompany($this->company, fn () => QualityInspection::query()->latest('id')->firstOrFail());
    expect($inspection->boq_item_id)->toBe($line->id)->and($inspection->boq_line_uid)->toBe($line->line_uid);
});

test('hand check: 10 checkpoints with 8 pass, 1 fail and 1 N/A complete as failed', function () {
    $inspection = $this->scheduledInspection();
    $results = $this->checkpointResults($inspection, [4 => ['fail', 'Honeycombing at beam B2'], 9 => ['na']]);

    $this->actingInCompany($this->qe, $this->company)
        ->post(route('projects.inspections.complete', [$this->project, $inspection]), ['items' => $results, 'remarks' => 'Rework beam B2'])
        ->assertSessionHasNoErrors();

    $inspection->refresh();
    $counts = $inspection->items()->get()->countBy(fn ($i) => $i->result->value)->all();
    expect($inspection->status)->toBe(InspectionStatus::Completed)
        ->and($inspection->result)->toBe(InspectionResult::Failed)
        ->and($counts)->toEqual(['pass' => 8, 'fail' => 1, 'na' => 1])
        ->and($inspection->completed_by)->toBe($this->qe->id)
        ->and($inspection->completed_at)->not->toBeNull();
});

test('result rules: passed by default, conditional needs remarks, failed needs a failing checkpoint', function () {
    $service = app(QualityInspectionService::class);
    $complete = function (QualityInspection $inspection, array $overrides, ?string $result, ?string $remarks = null) use ($service) {
        $this->actingAs($this->qe);

        return $this->inCompany($this->company, fn () => $service->complete($inspection, ['items' => $this->checkpointResults($inspection, $overrides), 'result' => $result, 'remarks' => $remarks], $this->qe));
    };

    $a = $this->scheduledInspection();
    expect(fn () => $complete($a, [2 => ['fail', 'Gap']], 'passed'))->toThrow(ValidationException::class, 'A failed checkpoint makes the inspection failed.');
    expect(fn () => $complete($a, [], 'failed'))->toThrow(ValidationException::class, 'No checkpoint failed');
    expect(fn () => $complete($a, [], 'conditional'))->toThrow(ValidationException::class, 'Explain the conditions');
    expect($a->fresh()->status)->toBe(InspectionStatus::Scheduled);

    $complete($a, [], null);
    expect($a->fresh()->result)->toBe(InspectionResult::Passed);

    $b = $this->scheduledInspection();
    $complete($b, [5 => ['na']], 'conditional', 'Accepted subject to curing log');
    expect($b->fresh()->result)->toBe(InspectionResult::Conditional);

    $c = $this->scheduledInspection();
    expect(fn () => $complete($c, array_fill_keys(range(1, 10), ['na']), null))->toThrow(ValidationException::class, 'all are N/A');
    expect(fn () => $complete($c, [1 => ['fail']], null))->toThrow(ValidationException::class, 'failed');
});

test('every checkpoint must be assessed before completion and results can be saved partially', function () {
    $inspection = $this->scheduledInspection();
    $first = $inspection->items()->first();

    $this->actingInCompany($this->qe, $this->company)
        ->post(route('projects.inspections.record', [$this->project, $inspection]), ['items' => [$first->id => ['result' => 'pass', 'remark' => 'ok']]])
        ->assertSessionHasNoErrors();
    expect($first->fresh()->result->value)->toBe('pass');

    $this->actingInCompany($this->qe, $this->company)
        ->post(route('projects.inspections.complete', [$this->project, $inspection]), [])
        ->assertSessionHasErrors('items');
    expect($inspection->fresh()->status)->toBe(InspectionStatus::Scheduled);
});

test('a foreign checkpoint id is rejected', function () {
    $a = $this->scheduledInspection();
    $b = $this->scheduledInspection();
    $foreign = $b->items()->first();

    $this->actingInCompany($this->qe, $this->company)
        ->post(route('projects.inspections.record', [$this->project, $a]), ['items' => [$foreign->id => ['result' => 'pass']]])
        ->assertSessionHasErrors('items');
    expect($foreign->fresh()->result)->toBeNull();
});

test('state locking: results need a schedule and a completed inspection never changes', function () {
    $requested = $this->requestInspection();
    $item = $requested->items()->first();
    $this->actingInCompany($this->qe, $this->company)
        ->post(route('projects.inspections.record', [$this->project, $requested]), ['items' => [$item->id => ['result' => 'pass']]])
        ->assertForbidden();

    $done = $this->completedInspection();
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $as = fn () => $this->actingInCompany($superAdmin, $this->company);
    $doneItem = $done->items()->first();

    $as()->post(route('projects.inspections.record', [$this->project, $done]), ['items' => [$doneItem->id => ['result' => 'fail', 'remark' => 'x']]])->assertSessionHasErrors('inspection');
    $as()->post(route('projects.inspections.complete', [$this->project, $done]), ['result' => 'failed'])->assertSessionHasErrors('inspection');
    $as()->put(route('projects.inspections.update', [$this->project, $done]), ['location' => 'Changed'])->assertSessionHasErrors('inspection');
    $as()->delete(route('projects.inspections.destroy', [$this->project, $done]))->assertSessionHasErrors('inspection');

    expect($done->fresh()->location)->toBe('Block A slab')
        ->and($doneItem->fresh()->result->value)->toBe('pass')
        ->and(fn () => $doneItem->forceFill(['result' => 'fail', 'remark' => 'x'])->save())->toThrow(ValidationException::class);
});

test('evidence can be attached until the inspection is completed', function () {
    $inspection = $this->scheduledInspection();
    $upload = fn () => $this->actingInCompany($this->qe, $this->company)->post(route('attachments.store'), [
        'attachable_type' => 'quality_inspection',
        'attachable_id' => $inspection->id,
        'file' => UploadedFile::fake()->image('slab.jpg'),
    ]);

    $upload()->assertSessionHasNoErrors();
    expect(Attachment::query()->withoutGlobalScopes()->where('attachable_type', 'quality_inspection')->where('attachable_id', $inspection->id)->count())->toBe(1);

    $this->actingAs($this->qe);
    $this->inCompany($this->company, fn () => app(QualityInspectionService::class)->complete($inspection, ['items' => $this->checkpointResults($inspection)], $this->qe));
    $upload()->assertForbidden();
});

test('permissions: engineers request, quality engineers schedule and perform, directors only read', function () {
    $inspection = $this->requestInspection();

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.inspections.schedule', [$this->project, $inspection]), ['inspection_date' => now()->toDateString()])
        ->assertForbidden();
    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.inspections.store', $this->project), ['quality_checklist_id' => $this->checklist->id])
        ->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->get(route('projects.inspections.show', [$this->project, $inspection]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.schedule', false)->where('can.edit', false));

    $this->actingInCompany($this->qe, $this->company)
        ->post(route('projects.inspections.schedule', [$this->project, $inspection]), ['inspection_date' => now()->toDateString(), 'engineer_id' => $this->qe->id])
        ->assertSessionHasNoErrors();
    expect($inspection->fresh()->status)->toBe(InspectionStatus::Scheduled);

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.inspections.complete', [$this->project, $inspection]), ['items' => $this->checkpointResults($inspection)])
        ->assertForbidden();
});

test('project isolation: unassigned users are blocked unless they can view all projects', function () {
    $inspection = $this->requestInspection();
    $outsider = $this->createMember($this->company, DefaultRoles::QUALITY_ENGINEER);

    $this->actingInCompany($outsider, $this->company)->get(route('projects.inspections.index', $this->project))->assertForbidden();
    $this->actingInCompany($outsider, $this->company)->get(route('projects.inspections.show', [$this->project, $inspection]))->assertForbidden();

    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($this->project, $outsider->id, ProjectRole::Quality));
    $this->actingInCompany($outsider, $this->company)->get(route('projects.inspections.show', [$this->project, $inspection]))->assertOk();

    // Directors hold projects.view_all.
    $this->actingInCompany($this->director, $this->company)->get(route('projects.inspections.index', $this->project))->assertOk();

    // An inspection id under another project's URL is not found.
    $this->actingInCompany($this->qe, $this->company)->get(route('projects.inspections.show', [$this->otherProject, $inspection]))->assertNotFound();
});

test('ability flags follow inspection state even for a platform super admin', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $done = $this->completedInspection();
    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.inspections.show', [$this->project, $done]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can', ['edit' => false, 'delete' => false, 'schedule' => false, 'perform' => false, 'attach' => false, 'raiseNcr' => false]));

    $failed = $this->failedInspection();
    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.inspections.show', [$this->project, $failed]))
        ->assertInertia(fn ($page) => $page->where('can.raiseNcr', true)->where('can.perform', false));

    $requested = $this->requestInspection();
    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.inspections.show', [$this->project, $requested]))
        ->assertInertia(fn ($page) => $page->where('can.schedule', true)->where('can.delete', true)->where('can.perform', false)->where('can.raiseNcr', false));
});
