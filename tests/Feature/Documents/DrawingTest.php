<?php

use App\Enums\Documents\DrawingRevisionStatus;
use App\Enums\Documents\DrawingStatus;
use App\Enums\ProjectRole;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

function drawingPayload(array $overrides = []): array
{
    return array_replace([
        'drawing_number' => 'STR-RF-201',
        'title' => 'Raft reinforcement',
        'discipline' => 'structural',
        'revision_code' => 'R0',
    ], $overrides);
}

function revisionRoute(string $action, $test, Drawing $drawing, DrawingRevision $revision): string
{
    return route("projects.drawings.revisions.{$action}", [$test->project, $drawing, $revision]);
}

test('a drawing is registered with its first revision stored privately with checksum', function () {
    $file = $this->pdf('raft.pdf', 'raft-r0');
    $expected = hash_file('sha256', $file->getRealPath());

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.store', $this->project), drawingPayload(['revision_code' => 'r0', 'file' => $file]))
        ->assertSessionHasNoErrors();

    $drawing = $this->inCompany($this->company, fn () => Drawing::query()->firstOrFail());
    $revision = $this->latestRevision($drawing);
    expect($drawing->status)->toBe(DrawingStatus::Draft)
        ->and($drawing->current_revision_id)->toBeNull()
        ->and($revision->revision_code)->toBe('R0')
        ->and($revision->status)->toBe(DrawingRevisionStatus::Draft)
        ->and($revision->checksum)->toBe($expected)
        ->and($revision->mime)->toBe('application/pdf')
        ->and($revision->file_name)->toBe('raft.pdf')
        ->and($revision->file_path)->toStartWith("company/{$this->company->id}/project/{$this->project->id}/drawings/")
        ->and($revision->file_path)->not->toContain('raft');
    Storage::disk('private')->assertExists($revision->file_path);
    expect(hash('sha256', Storage::disk('private')->get($revision->file_path)))->toBe($expected);

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.drawings.index', $this->project))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Documents/Drawings/Index')->where('drawings.total', 1));
});

test('drawing numbers are unique per project and can be edited until approval', function () {
    [$drawing] = $this->makeDrawing('ARC-GF-101');

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.store', $this->project), drawingPayload(['drawing_number' => 'ARC-GF-101', 'file' => $this->pdf()]))
        ->assertSessionHasErrors('drawing_number');
    expect(Storage::disk('private')->allFiles())->toHaveCount(1);

    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($this->otherProject, $this->pm->id, ProjectRole::Manager));
    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.store', $this->otherProject), drawingPayload(['drawing_number' => 'ARC-GF-101', 'file' => $this->pdf()]))
        ->assertSessionHasNoErrors();

    $update = ['drawing_number' => 'ARC-GF-101A', 'title' => 'Ground floor plan (rev)', 'discipline' => 'architectural'];
    $this->actingInCompany($this->pm, $this->company)->put(route('projects.drawings.update', [$this->project, $drawing]), $update)->assertSessionHasNoErrors();
    expect($drawing->fresh()->drawing_number)->toBe('ARC-GF-101A');

    $this->approveRevision($this->latestRevision($drawing));
    $this->actingInCompany($this->pm, $this->company)->put(route('projects.drawings.update', [$this->project, $drawing]), ['drawing_number' => 'CHANGED'] + $update)->assertSessionHasErrors('drawing_number');
    $this->actingInCompany($this->pm, $this->company)->put(route('projects.drawings.update', [$this->project, $drawing]), ['title' => 'Ground floor plan'] + $update)->assertSessionHasNoErrors();
    expect($drawing->fresh()->drawing_number)->toBe('ARC-GF-101A');
});

test('revision codes are never reused and only one revision can be open', function () {
    [$drawing, $r0] = $this->makeDrawing();
    $url = route('projects.drawings.revisions.store', [$this->project, $drawing]);
    $as = fn () => $this->actingInCompany($this->pm, $this->company);

    $as()->post($url, ['revision_code' => 'R1', 'file' => $this->pdf()])->assertSessionHasErrors('revision_code');

    $this->approveRevision($r0);
    $as()->post($url, ['revision_code' => 'r0', 'file' => $this->pdf()])->assertSessionHasErrors('revision_code');
    $as()->post($url, ['revision_code' => 'R 1', 'file' => $this->pdf()])->assertSessionHasErrors('revision_code');

    // A rejected code stays taken.
    $as()->post($url, ['revision_code' => 'R1', 'file' => $this->pdf('r1.pdf', 'r1')])->assertSessionHasNoErrors();
    $r1 = $this->latestRevision($drawing);
    $as()->post(revisionRoute('submit', $this, $drawing, $r1));
    $as()->post(revisionRoute('review', $this, $drawing, $r1));
    $as()->post(revisionRoute('reject', $this, $drawing, $r1), ['comments' => ''])->assertSessionHasErrors('comments');
    $as()->post(revisionRoute('reject', $this, $drawing, $r1), ['comments' => 'Column C4 size wrong'])->assertSessionHasNoErrors();
    expect($r1->fresh()->status)->toBe(DrawingRevisionStatus::Rejected)
        ->and($r1->fresh()->review_comments)->toBe('Column C4 size wrong')
        ->and($drawing->fresh()->current_revision_id)->toBe($r0->id);
    $as()->post($url, ['revision_code' => 'R1', 'file' => $this->pdf()])->assertSessionHasErrors('revision_code');

    // A withdrawn draft code stays taken too.
    $as()->post($url, ['revision_code' => 'R2', 'file' => $this->pdf()])->assertSessionHasNoErrors();
    $r2 = $this->latestRevision($drawing);
    $as()->delete(revisionRoute('withdraw', $this, $drawing, $r2))->assertSessionHasNoErrors();
    expect($this->revisionRow($r2->id)->trashed())->toBeTrue();
    $as()->post($url, ['revision_code' => 'R2', 'file' => $this->pdf()])->assertSessionHasErrors('revision_code');
    $as()->post($url, ['revision_code' => 'R3', 'file' => $this->pdf()])->assertSessionHasNoErrors();
});

test('approving R1 supersedes R0, moves current_revision_id and keeps R0 accessible and unchanged', function () {
    [$drawing, $r0] = $this->makeDrawing('ARC-GF-101', 'R0', $this->pdf('gf-r0.pdf', 'r0-content'));
    $this->approveRevision($r0);
    $r0Before = $this->revisionRow($r0->id)->only(DrawingRevision::FILE_COLUMNS);
    expect($drawing->fresh()->current_revision_id)->toBe($r0->id)->and($drawing->fresh()->status)->toBe(DrawingStatus::Approved);

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.revisions.store', [$this->project, $drawing]), ['revision_code' => 'R1', 'file' => $this->pdf('gf-r1.pdf', 'r1-content')])
        ->assertSessionHasNoErrors();
    $r1 = $this->latestRevision($drawing);
    expect($drawing->fresh()->current_revision_id)->toBe($r0->id);
    $this->approveRevision($r1);

    $r0After = $this->revisionRow($r0->id);
    $r1After = $this->revisionRow($r1->id);
    expect($drawing->fresh()->current_revision_id)->toBe($r1->id)
        ->and($r1After->status)->toBe(DrawingRevisionStatus::Approved)
        ->and($r1After->supersedes_revision_id)->toBe($r0->id)
        ->and($r1After->decided_by)->toBe($this->pm->id)
        ->and($r0After->status)->toBe(DrawingRevisionStatus::Superseded)
        ->and($r0After->superseded_at)->not->toBeNull()
        ->and($r0After->only(DrawingRevision::FILE_COLUMNS))->toBe($r0Before)
        ->and(DrawingRevision::query()->withoutGlobalScopes()->where('drawing_id', $drawing->id)->count())->toBe(2);

    $response = $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('download', $this, $drawing, $r0After));
    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(hash('sha256', $response->streamedContent()))->toBe($r0Before['checksum']);

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.drawings.show', [$this->project, $drawing]))->assertOk()
        ->assertInertia(fn ($page) => $page->has('revisions', 2)
            ->where('drawing.current_revision', 'R1')
            ->where('revisions.0.is_current', true)
            ->where('revisions.1.status', 'superseded')
            ->where('revisions.1.supersedes', null)
            ->where('revisions.0.supersedes', 'R0'));
});

test('approved and superseded revision files are immutable at the model level', function () {
    [$drawing, $r0] = $this->makeDrawing();
    $this->approveRevision($r0);
    $approved = $this->revisionRow($r0->id);

    expect(fn () => $approved->forceFill(['file_path' => 'company/x/evil.pdf'])->save())->toThrow(ValidationException::class)
        ->and(fn () => $this->revisionRow($r0->id)->forceFill(['checksum' => str_repeat('0', 64)])->save())->toThrow(ValidationException::class)
        ->and(fn () => $this->revisionRow($r0->id)->forceFill(['status' => DrawingRevisionStatus::Draft])->save())->toThrow(ValidationException::class)
        ->and(fn () => $this->revisionRow($r0->id)->forceFill(['review_comments' => 'edited'])->save())->toThrow(ValidationException::class)
        ->and(fn () => $this->revisionRow($r0->id)->delete())->toThrow(ValidationException::class)
        ->and(fn () => $this->revisionRow($r0->id)->forceDelete())->toThrow(LogicException::class);

    // Even a draft keeps its file.
    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.revisions.store', [$this->project, $drawing]), ['revision_code' => 'R1', 'file' => $this->pdf()]);
    $draft = $this->latestRevision($drawing);
    expect(fn () => $draft->forceFill(['file_name' => 'renamed.pdf'])->save())->toThrow(ValidationException::class);
});

test('workflow order is enforced and permissions split upload, review and approve', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    [$drawing, $r0] = $this->makeDrawing();
    $sa = fn () => $this->actingInCompany($superAdmin, $this->company);

    $sa()->post(revisionRoute('approve', $this, $drawing, $r0))->assertSessionHasErrors('revision');
    $sa()->post(revisionRoute('review', $this, $drawing, $r0))->assertSessionHasErrors('revision');

    $this->actingInCompany($this->engineer, $this->company)->post(revisionRoute('submit', $this, $drawing, $r0))->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.drawings.store', $this->project), drawingPayload(['file' => $this->pdf()]))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(revisionRoute('submit', $this, $drawing, $r0))->assertSessionHasNoErrors();
    $sa()->delete(revisionRoute('withdraw', $this, $drawing, $r0))->assertSessionHasErrors('revision');

    $this->actingInCompany($this->qe, $this->company)->post(revisionRoute('review', $this, $drawing, $r0))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(revisionRoute('review', $this, $drawing, $r0))->assertSessionHasNoErrors();
    $this->actingInCompany($this->director, $this->company)->post(revisionRoute('approve', $this, $drawing, $r0))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(revisionRoute('approve', $this, $drawing, $r0), ['comments' => 'Good for construction'])->assertSessionHasNoErrors();

    $r0->refresh();
    expect($r0->status)->toBe(DrawingRevisionStatus::Approved)
        ->and($r0->submitted_by)->toBe($this->pm->id)
        ->and($r0->reviewed_by)->toBe($this->pm->id)
        ->and($r0->review_comments)->toBe('Good for construction');
    $sa()->post(revisionRoute('reject', $this, $drawing, $r0), ['comments' => 'Too late now'])->assertSessionHasErrors('revision');
});

test('file type, MIME and CAD signature are validated; DWG is download-only', function () {
    $url = route('projects.drawings.store', $this->project);
    $as = fn () => $this->actingInCompany($this->pm, $this->company);

    $as()->post($url, drawingPayload(['file' => UploadedFile::fake()->createWithContent('plan.docx', 'PK fake')]))->assertSessionHasErrors('file');
    $as()->post($url, drawingPayload(['file' => $this->sniffedUpload('plan.pdf', "<?php echo 'not a pdf';\n")]))->assertSessionHasErrors('file');
    $as()->post($url, drawingPayload(['file' => $this->sniffedUpload('plan.png', "%PDF-1.4\n%x\n%%EOF\n")]))->assertSessionHasErrors('file');
    $as()->post($url, drawingPayload(['file' => UploadedFile::fake()->createWithContent('plan.dwg', 'NOTCAD'.str_repeat("\0", 32))]))->assertSessionHasErrors('file');
    expect(Storage::disk('private')->allFiles())->toBe([]);

    $as()->post($url, drawingPayload(['drawing_number' => 'SNIFF-1', 'file' => $this->sniffedUpload('real.pdf', "%PDF-1.4\n%real\n1 0 obj << >> endobj\n%%EOF\n")]))->assertSessionHasNoErrors();
    $sniffed = $this->inCompany($this->company, fn () => Drawing::query()->where('drawing_number', 'SNIFF-1')->firstOrFail());
    expect($this->latestRevision($sniffed)->mime)->toBe('application/pdf');

    $as()->post($url, drawingPayload(['file' => $this->dwg()]))->assertSessionHasNoErrors();
    $drawing = $this->inCompany($this->company, fn () => Drawing::query()->where('drawing_number', 'STR-RF-201')->firstOrFail());
    $revision = $this->latestRevision($drawing);
    expect($revision->extension)->toBe('dwg');

    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('preview', $this, $drawing, $revision))->assertNotFound();
    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('download', $this, $drawing, $revision))
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=plan.dwg');
});

test('PDF revisions preview inline with no-store headers', function () {
    [$drawing, $r0] = $this->makeDrawing();
    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('preview', $this, $drawing, $r0))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename=drawing.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('private download is authorized per tenant, project and drawing', function () {
    [$drawing, $r0] = $this->makeDrawing('ARC-GF-101');
    [$otherDrawing] = $this->makeDrawing('ARC-GF-102');
    $outsider = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('download', $this, $drawing, $r0))->assertOk();
    $this->actingInCompany($outsider, $this->company)->get(revisionRoute('download', $this, $drawing, $r0))->assertForbidden();
    $this->actingInCompany($otherAdmin, $other)->get(revisionRoute('download', $this, $drawing, $r0))->assertNotFound();
    $this->actingInCompany($this->billing, $this->company)->get(revisionRoute('download', $this, $drawing, $r0))->assertForbidden();

    // IDOR: R0 of drawing 101 requested under drawing 102, or drawing 101 under another project.
    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('download', $this, $otherDrawing, $r0))->assertNotFound();
    $this->actingInCompany($this->qe, $this->company)->get(route('projects.drawings.revisions.download', [$this->otherProject, $drawing, $r0]))->assertNotFound();

    $this->get('/logout');
    auth()->logout();
    $this->get(revisionRoute('download', $this, $drawing, $r0))->assertRedirect();
});

test('a stored file that fails its checksum is not served', function () {
    [$drawing, $r0] = $this->makeDrawing();
    Storage::disk('private')->put($r0->file_path, 'tampered bytes');

    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('download', $this, $drawing, $r0))->assertStatus(409);
    $this->actingInCompany($this->engineer, $this->company)->get(revisionRoute('preview', $this, $drawing, $r0))->assertStatus(409);
});

test('a duplicate file is flagged but accepted', function () {
    [$drawing, $r0] = $this->makeDrawing('ARC-GF-101', 'R0', $this->pdf('a.pdf', 'same'));
    $this->approveRevision($r0);

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.revisions.store', [$this->project, $drawing]), ['revision_code' => 'R1', 'file' => $this->pdf('b.pdf', 'same')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'identical to revision R0'));
});

test('revision ability flags follow state even for a platform super admin', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    [$drawing, $r0] = $this->makeDrawing();
    $this->approveRevision($r0);

    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.drawings.show', [$this->project, $drawing]))
        ->assertInertia(fn ($page) => $page
            ->where('revisions.0.can', ['submit' => false, 'withdraw' => false, 'review' => false, 'approve' => false, 'reject' => false])
            ->where('can.editNumber', false)
            ->where('can.upload', true));

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.drawings.revisions.store', [$this->project, $drawing]), ['revision_code' => 'R1', 'file' => $this->pdf()]);
    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.drawings.show', [$this->project, $drawing]))
        ->assertInertia(fn ($page) => $page
            ->where('can.upload', false)
            ->where('revisions.0.can', ['submit' => true, 'withdraw' => true, 'review' => false, 'approve' => false, 'reject' => false])
            ->where('revisions.1.can.submit', false));
});
