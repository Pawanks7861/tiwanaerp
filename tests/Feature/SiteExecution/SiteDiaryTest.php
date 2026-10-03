<?php

use App\Enums\ProjectRole;
use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Models\Planning\ProgressEntry;
use App\Models\Projects\Site;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Models\SiteExecution\SiteDiaryWorkItem;
use App\Services\Projects\ProjectService;
use App\Services\SiteExecution\SiteDiaryService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSiteExecutionData;

uses(BuildsSiteExecutionData::class);

beforeEach(function () {
    $this->setUpSiteExecution();
});

function diaryOf($test, ?int $id = null): SiteDiary
{
    return $test->inCompany($test->company, fn () => $id ? SiteDiary::query()->findOrFail($id) : SiteDiary::query()->latest('id')->firstOrFail());
}

function diaryPayload($test, array $extra = []): array
{
    return $extra + [
        'uuid' => (string) Str::uuid(),
        'site_id' => $test->site->id,
        'diary_date' => now()->toDateString(),
        'weather' => 'Sunny',
        'temperature' => '31.5',
        'work_location' => 'Block A raft',
        'work_performed' => 'Raft concreting grid A-C',
        'latitude' => '28.61393951',
        'longitude' => '77.20902112',
        'work_items' => [['task_id' => $test->task->id, 'quantity' => '12.5', 'unit_id' => $test->unitId()]],
        'labours' => [['labour_trade_id' => $test->mason, 'headcount' => 8, 'hours' => '8']],
        'equipment' => [['equipment_type_id' => $test->mixer, 'working_hours' => '6', 'idle_hours' => '2']],
        'materials' => [['material_id' => $test->cement->id, 'quantity' => '40', 'unit_id' => $test->unitId('Bag')]],
    ];
}

test('an engineer records a diary with work, labour, equipment and material lines; tenant and status input are ignored', function () {
    $payload = diaryPayload($this, ['company_id' => 999, 'status' => 'approved', 'project_id' => $this->otherProject->id]);

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.site-diaries.store', $this->project), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $diary = diaryOf($this);
    $lines = $this->inCompany($this->company, fn () => [$diary->workItems()->sole(), $diary->labours()->sole(), $diary->equipment()->sole(), $diary->materials()->sole()]);

    expect($diary->company_id)->toBe($this->company->id)
        ->and($diary->project_id)->toBe($this->project->id)
        ->and($diary->status)->toBe(SiteDiaryStatus::Draft)
        ->and($diary->created_by)->toBe($this->engineer->id)
        ->and($diary->latitude)->toBe('28.6139395')
        ->and($lines[0]->quantity)->toBe('12.5000')
        ->and($lines[1]->headcount)->toBe(8)
        ->and($lines[2]->working_hours)->toBe('6.00')
        ->and($lines[3]->quantity)->toBe('40.0000');
});

test('creating a diary is idempotent on the client uuid', function () {
    $payload = diaryPayload($this);
    $client = $this->actingInCompany($this->engineer, $this->company);

    $client->post(route('projects.site-diaries.store', $this->project), $payload)->assertSessionHasNoErrors();
    $client->post(route('projects.site-diaries.store', $this->project), $payload)->assertSessionHasNoErrors();

    expect($this->inCompany($this->company, fn () => SiteDiary::query()->count()))->toBe(1)
        ->and($this->inCompany($this->company, fn () => SiteDiaryWorkItem::query()->count()))->toBe(1);
});

test('a draft diary is edited by its author (not a colleague) and deleted only with site_diary.delete', function () {
    $diary = $this->makeDiary();
    $colleague = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($this->project, $colleague->id, ProjectRole::Engineer));

    $this->actingInCompany($colleague, $this->company)->put(route('projects.site-diaries.update', [$this->project, $diary]), diaryPayload($this))->assertForbidden();
    $this->actingInCompany($colleague, $this->company)->delete(route('projects.site-diaries.destroy', [$this->project, $diary]))->assertForbidden();

    $this->actingInCompany($this->engineer, $this->company)
        ->put(route('projects.site-diaries.update', [$this->project, $diary]), diaryPayload($this, ['uuid' => $diary->uuid, 'work_items' => [], 'labours' => []]))
        ->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => [$diary->workItems()->count(), $diary->labours()->count(), $diary->materials()->count()]))->toBe([0, 0, 1]);

    $this->actingInCompany($this->engineer, $this->company)->delete(route('projects.site-diaries.destroy', [$this->project, $diary]))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.site-diaries.destroy', [$this->project, $diary]))->assertRedirect(route('projects.site-diaries.index', $this->project));
    expect($this->inCompany($this->company, fn () => SiteDiary::query()->count()))->toBe(0)
        ->and($this->inCompany($this->company, fn () => SiteDiary::withTrashed()->count()))->toBe(1);
});

test('several diaries per project and day are allowed', function () {
    $this->makeDiary();
    $this->makeDiary(['work_location' => 'Block B']);
    $this->makeDiary([], $this->pm);

    expect($this->inCompany($this->company, fn () => SiteDiary::query()->whereDate('diary_date', now())->count()))->toBe(3);
});

test('the site, task, BOQ line and unit must belong to the project and match', function () {
    $client = $this->actingInCompany($this->engineer, $this->company);
    $otherSite = $this->inCompany($this->company, function () {
        $site = new Site(['name' => 'Tower B site', 'is_active' => true]);
        $site->forceFill(['project_id' => $this->otherProject->id])->save();

        return $site;
    });
    $foreignTask = $this->makeTask(['wbs_code' => '1.1'], $this->otherProject);
    $line = $this->approvedBoqLine();
    $otherLine = $this->inCompany($this->company, fn () => $line->boq->items()->where('item_code', 'A.2')->first());
    $linkedTask = $this->makeTask(['wbs_code' => '2.1', 'boq_item_id' => $line->id]);

    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, ['site_id' => $otherSite->id]))->assertSessionHasErrors('site_id');
    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, [
        'work_items' => [['task_id' => $foreignTask->id, 'quantity' => '1', 'unit_id' => $this->unitId()]],
    ]))->assertSessionHasErrors('work_items.0.task_id');
    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, [
        'work_items' => [['task_id' => $this->task->id, 'quantity' => '1', 'unit_id' => $this->unitId('Bag')]],
    ]))->assertSessionHasErrors('work_items.0.unit_id');
    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, [
        'work_items' => [['task_id' => $linkedTask->id, 'boq_item_id' => $otherLine->id, 'quantity' => '1', 'unit_id' => $this->unitId()]],
    ]))->assertSessionHasErrors('work_items.0.boq_item_id');
    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, [
        'work_items' => [['quantity' => '1', 'unit_id' => $this->unitId()]],
    ]))->assertSessionHasErrors('work_items.0.description');
    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, [
        'materials' => [['material_id' => $this->cement->id, 'quantity' => '5', 'unit_id' => $this->unitId()]],
    ]))->assertSessionHasErrors('materials.0.unit_id');

    $client->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, [
        'work_items' => [['task_id' => $linkedTask->id, 'quantity' => '2', 'unit_id' => $this->unitId()]],
    ]))->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => SiteDiaryWorkItem::query()->sole()->boq_item_id))->toBe($line->id);
});

test('workflow: draft → submitted → reviewed → approved, with segregation from the submitter', function () {
    $diary = $this->makeDiary();
    $engineer = fn () => $this->actingInCompany($this->engineer, $this->company);
    $pm = fn () => $this->actingInCompany($this->pm, $this->company);

    $engineer()->post(route('projects.site-diaries.approve', [$this->project, $diary]))->assertForbidden();
    $engineer()->post(route('projects.site-diaries.submit', [$this->project, $diary]))->assertSessionHasNoErrors()->assertRedirect();
    expect(diaryOf($this, $diary->id)->status)->toBe(SiteDiaryStatus::Submitted);

    $engineer()->post(route('projects.site-diaries.review', [$this->project, $diary]))->assertForbidden();
    $engineer()->put(route('projects.site-diaries.update', [$this->project, $diary]), diaryPayload($this))->assertForbidden();
    $pm()->post(route('projects.site-diaries.approve', [$this->project, $diary]))->assertForbidden();

    $pm()->post(route('projects.site-diaries.review', [$this->project, $diary]))->assertSessionHasNoErrors()->assertRedirect();
    $pm()->post(route('projects.site-diaries.approve', [$this->project, $diary]))->assertSessionHasNoErrors()->assertRedirect();

    $diary = diaryOf($this, $diary->id);
    expect($diary->status)->toBe(SiteDiaryStatus::Approved)
        ->and($diary->submitted_by)->toBe($this->engineer->id)
        ->and($diary->reviewed_by)->toBe($this->pm->id)
        ->and($diary->approved_by)->toBe($this->pm->id);

    $pm()->put(route('projects.site-diaries.update', [$this->project, $diary]), diaryPayload($this))->assertForbidden();
    $pm()->delete(route('projects.site-diaries.destroy', [$this->project, $diary]))->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => $diary->forceFill(['work_performed' => 'changed'])->save()))->toThrow(ValidationException::class);
    expect(fn () => $this->inCompany($this->company, fn () => $diary->workItems()->first()->forceFill(['quantity' => '99'])->save()))->toThrow(ValidationException::class);
});

test('the person who submitted a diary cannot review, approve or reject it', function () {
    $diary = $this->makeDiary([], $this->pm);
    $service = app(SiteDiaryService::class);
    $this->inCompany($this->company, fn () => $service->submit($diary, $this->pm));

    $this->actingInCompany($this->pm, $this->company)->post(route('projects.site-diaries.review', [$this->project, $diary]))->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => $service->review($diary, $this->pm)))->toThrow(ValidationException::class)
        ->and(fn () => $this->inCompany($this->company, fn () => $service->reject($diary, $this->pm, 'Not my own')))->toThrow(ValidationException::class);
});

test('a rejected diary goes back to its author, is editable and can be resubmitted', function () {
    $diary = $this->makeDiary();
    $this->inCompany($this->company, fn () => app(SiteDiaryService::class)->submit($diary, $this->engineer));

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.site-diaries.reject', [$this->project, $diary]), ['reason' => 'Quantity looks wrong'])
        ->assertSessionHasNoErrors();
    expect(diaryOf($this, $diary->id))->status->toBe(SiteDiaryStatus::Rejected)->rejection_reason->toBe('Quantity looks wrong');

    $engineer = $this->actingInCompany($this->engineer, $this->company);
    $engineer->put(route('projects.site-diaries.update', [$this->project, $diary]), diaryPayload($this, ['uuid' => $diary->uuid, 'work_performed' => 'Corrected']))->assertSessionHasNoErrors();
    $engineer->post(route('projects.site-diaries.submit', [$this->project, $diary]))->assertSessionHasNoErrors();

    expect(diaryOf($this, $diary->id))
        ->status->toBe(SiteDiaryStatus::Submitted)
        ->work_performed->toBe('Corrected')
        ->rejection_reason->toBeNull()
        ->rejected_by->toBeNull();
});

test('save-and-submit from the form stores the draft and submits it in one step', function () {
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.site-diaries.store', $this->project), diaryPayload($this, ['intent' => 'submit']))
        ->assertSessionHasNoErrors();

    expect(diaryOf($this)->status)->toBe(SiteDiaryStatus::Submitted);
});

test('a diary needs work performed or a work line before it can be submitted', function () {
    $diary = $this->makeDiary(['work_performed' => null, 'work_items' => []]);

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.site-diaries.submit', [$this->project, $diary]))
        ->assertSessionHasErrors('diary');
    expect(diaryOf($this, $diary->id)->status)->toBe(SiteDiaryStatus::Draft);
});

test('approving a diary never posts progress', function () {
    $this->approveDiary($this->makeDiary());

    expect($this->inCompany($this->company, fn () => ProgressEntry::query()->count()))->toBe(0)
        ->and($this->task()->completed_qty)->toBeIn([null, '0.0000']);
});

test('photos: compressed upload with thumbnail, idempotent uuid, images only, editable diaries only', function () {
    Storage::fake(config('uploads.disk'));
    $diary = $this->makeDiary();
    $engineer = $this->actingInCompany($this->engineer, $this->company);
    $uuid = (string) Str::uuid();
    $upload = fn (UploadedFile $file, ?string $id = null) => $engineer->post(route('projects.site-diaries.photos.store', [$this->project, $diary]), [
        'photo' => $file, 'uuid' => $id ?? (string) Str::uuid(), 'caption' => 'Raft', 'latitude' => '28.6', 'longitude' => '77.2', 'taken_at' => now()->toIso8601String(),
    ]);

    $upload(UploadedFile::fake()->image('raft.jpg', 1200, 900), $uuid)->assertSessionHasNoErrors();
    $upload(UploadedFile::fake()->image('raft.jpg', 1200, 900), $uuid)->assertSessionHasNoErrors();
    $upload(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertSessionHasErrors('photo');

    $photo = $this->inCompany($this->company, fn () => SiteDiaryPhoto::query()->sole());
    expect($photo->uuid)->toBe($uuid)
        ->and($photo->caption)->toBe('Raft')
        ->and($photo->thumbnail_path)->not->toBeNull()
        ->and($photo->path)->toStartWith("company/{$this->company->id}/site-diaries/");
    Storage::disk(config('uploads.disk'))->assertExists([$photo->path, $photo->thumbnail_path]);

    $engineer->get(route('projects.site-diaries.photos.thumb', [$this->project, $diary, $photo]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->inCompany($this->company, fn () => app(SiteDiaryService::class)->submit($diary, $this->engineer));
    $upload(UploadedFile::fake()->image('late.jpg'))->assertForbidden();
    $engineer->delete(route('projects.site-diaries.photos.destroy', [$this->project, $diary, $photo]))->assertForbidden();
});

test('diaries are isolated by company, project and permission', function () {
    $diary = $this->makeDiary();
    $otherCompany = $this->createCompany();
    $outsider = $this->createMember($otherCompany, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($outsider, $otherCompany)->get(route('projects.site-diaries.show', [$this->project, $diary]))->assertNotFound();
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.site-diaries.show', [$this->otherProject, $diary]))->assertNotFound();

    $this->actingInCompany($this->billing, $this->company)->get(route('projects.site-diaries.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->get(route('projects.site-diaries.index', $this->project))->assertOk();
    $this->actingInCompany($this->director, $this->company)->get(route('projects.site-diaries.create', $this->project))->assertForbidden();

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('projects.site-diaries.show', [$this->project, $diary]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Diaries/Show')
            ->where('can.submit', true)
            ->where('can.approve', false)
            ->has('work_items', 1));
});

test('a platform super admin is not offered transitions the diary state does not allow', function () {
    $diary = $this->makeDiary();
    $root = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN, ['is_super_admin' => true]);

    $this->actingInCompany($root, $this->company)
        ->get(route('projects.site-diaries.show', [$this->project, $diary]))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', true)->where('can.submit', true)
            ->where('can.review', false)->where('can.approve', false)->where('can.reject', false));

    $this->approveDiary($diary);
    $this->actingInCompany($root, $this->company)
        ->get(route('projects.site-diaries.show', [$this->project, $diary]))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.delete', false)->where('can.approve', false));
    $this->actingInCompany($root, $this->company)->get(route('projects.site-diaries.edit', [$this->project, $diary]))->assertForbidden();
});

test('diary screens render', function () {
    $diary = $this->makeDiary();
    $client = $this->actingInCompany($this->engineer, $this->company);

    $client->get(route('projects.site-diaries.index', $this->project))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Diaries/Index')->has('diaries.data', 1));
    $client->get(route('projects.site-diaries.create', $this->project))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Diaries/Form')->has('options.tasks', 1)->has('options.sites', 1));
    $client->get(route('projects.site-diaries.edit', [$this->project, $diary]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Diaries/Form')->where('diary.id', $diary->id));
});
