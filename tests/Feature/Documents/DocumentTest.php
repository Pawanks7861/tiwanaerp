<?php

use App\Enums\Documents\DocumentStatus;
use App\Enums\ProjectRole;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Documents\DocumentVersion;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

/**
 * @return Collection<int, DocumentVersion>
 */
function versionRows(Document $document): Collection
{
    return DocumentVersion::query()->withoutGlobalScopes()->where('document_id', $document->id)->orderBy('version_no')->get();
}

function folderRow($test, string $name): DocumentFolder
{
    return $test->inCompany($test->company, fn () => DocumentFolder::query()->where('name', $name)->firstOrFail());
}

test('folders nest, names are unique among siblings and slashes are refused', function () {
    $url = route('projects.documents.folders.store', $this->project);
    $as = fn () => $this->actingInCompany($this->pm, $this->company);

    $as()->post($url, ['name' => 'Contracts'])->assertSessionHasNoErrors();
    $contracts = folderRow($this, 'Contracts');
    $as()->post($url, ['name' => 'Subcontracts', 'parent_id' => $contracts->id])->assertSessionHasNoErrors();
    $sub = folderRow($this, 'Subcontracts');
    $as()->post($url, ['name' => 'Formwork', 'parent_id' => $sub->id])->assertSessionHasNoErrors();

    $as()->post($url, ['name' => 'Contracts'])->assertSessionHasErrors('name');
    $as()->post($url, ['name' => 'Subcontracts', 'parent_id' => $contracts->id])->assertSessionHasErrors('name');
    $as()->post($url, ['name' => 'Subcontracts'])->assertSessionHasNoErrors();
    $as()->post($url, ['name' => 'A/B'])->assertSessionHasErrors('name');

    expect($sub->parent_id)->toBe($contracts->id)
        ->and(folderRow($this, 'Formwork')->parent_id)->toBe($sub->id);

    // Same name in another project is fine.
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($this->otherProject, $this->pm->id, ProjectRole::Manager));
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.folders.store', $this->otherProject), ['name' => 'Contracts'])->assertSessionHasNoErrors();
});

test('folder moves cannot create cycles or cross projects, and only empty folders are deleted', function () {
    $as = fn () => $this->actingInCompany($this->pm, $this->company);
    $as()->post(route('projects.documents.folders.store', $this->project), ['name' => 'Root']);
    $root = folderRow($this, 'Root');
    $as()->post(route('projects.documents.folders.store', $this->project), ['name' => 'Child', 'parent_id' => $root->id]);
    $child = folderRow($this, 'Child');
    $as()->post(route('projects.documents.folders.store', $this->project), ['name' => 'Grandchild', 'parent_id' => $child->id]);
    $grandchild = folderRow($this, 'Grandchild');
    $foreign = $this->inCompany($this->company, function () {
        $f = new DocumentFolder;
        $f->forceFill(['project_id' => $this->otherProject->id, 'name' => 'Tower B docs'])->save();

        return $f;
    });

    $update = fn (DocumentFolder $f, array $data) => $as()->put(route('projects.documents.folders.update', [$this->project, $f]), $data);
    $update($root, ['name' => 'Root', 'parent_id' => $grandchild->id])->assertSessionHasErrors('parent_id');
    $update($root, ['name' => 'Root', 'parent_id' => $root->id])->assertSessionHasErrors('parent_id');
    $update($child, ['name' => 'Child', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id');
    $update($grandchild, ['name' => 'Moved up', 'parent_id' => null])->assertSessionHasNoErrors();
    expect($grandchild->fresh()->parent_id)->toBeNull()->and($grandchild->fresh()->name)->toBe('Moved up');

    // IDOR: another project's folder under this project's URL.
    $as()->put(route('projects.documents.folders.update', [$this->project, $foreign]), ['name' => 'x'])->assertNotFound();

    $this->makeDocument(['document_folder_id' => $child->id]);
    $as()->delete(route('projects.documents.folders.destroy', [$this->project, $root]))->assertSessionHasErrors('folder');
    $as()->delete(route('projects.documents.folders.destroy', [$this->project, $child]))->assertSessionHasErrors('folder');
    $as()->delete(route('projects.documents.folders.destroy', [$this->project, $grandchild]))->assertSessionHasNoErrors();
    expect(DocumentFolder::query()->withoutGlobalScopes()->whereKey($grandchild->id)->exists())->toBeFalse();

    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.documents.folders.store', $this->project), ['name' => 'Nope'])->assertForbidden();
});

test('creating a document stores version 1 with a checksum and an internal number separate from the reference', function () {
    $file = $this->pdf('spec.pdf', 'spec-v1');
    $checksum = hash_file('sha256', $file->getRealPath());

    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.store', $this->project), [
        'name' => 'Structural specification',
        'category' => 'specification',
        'reference_no' => 'CLIENT/SPEC/007',
        'file' => $file,
        'revision_label' => 'Issue A',
    ])->assertSessionHasNoErrors();

    $document = $this->inCompany($this->company, fn () => Document::query()->firstOrFail());
    $versions = versionRows($document);
    expect($document->document_number)->toBe("DOC-{$this->project->code}-0001")
        ->and($document->reference_no)->toBe('CLIENT/SPEC/007')
        ->and($document->status)->toBe(DocumentStatus::Draft)
        ->and($versions)->toHaveCount(1)
        ->and($versions[0]->version_no)->toBe(1)
        ->and($versions[0]->checksum)->toBe($checksum)
        ->and($versions[0]->revision_label)->toBe('Issue A')
        ->and($document->current_version_id)->toBe($versions[0]->id);
    expect(hash('sha256', Storage::disk('private')->get($versions[0]->file_path)))->toBe($checksum);
});

test('subsequent versions are numbered by the server, move current_version_id and leave prior rows untouched', function () {
    [$document, $v1] = $this->makeDocument([], $this->pdf('spec.pdf', 'v1'));
    $v1Before = versionRows($document)->first()->getAttributes();
    $url = route('projects.documents.versions.store', [$this->project, $document]);

    $this->actingInCompany($this->pm, $this->company)->post($url, ['file' => $this->pdf('spec-v2.pdf', 'v2'), 'version_no' => 99])->assertSessionHasNoErrors();
    $this->actingInCompany($this->pm, $this->company)->post($url, ['file' => $this->pdf('spec-v3.pdf', 'v3')])->assertSessionHasNoErrors();

    $versions = versionRows($document);
    expect($versions->pluck('version_no')->all())->toBe([1, 2, 3])
        ->and($document->fresh()->current_version_id)->toBe($versions[2]->id)
        ->and($versions[0]->getAttributes())->toBe($v1Before)
        ->and($versions->pluck('checksum')->unique())->toHaveCount(3);
    foreach ($versions as $version) {
        Storage::disk('private')->assertExists($version->file_path);
        expect(hash('sha256', Storage::disk('private')->get($version->file_path)))->toBe($version->checksum);
    }

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.documents.show', [$this->project, $document]))->assertOk()
        ->assertInertia(fn ($page) => $page->has('versions', 3)
            ->where('versions.0.version_no', 3)
            ->where('versions.0.is_current', true)
            ->where('versions.2.is_current', false));
});

test('an identical upload is flagged as a duplicate but accepted', function () {
    [$document] = $this->makeDocument([], $this->pdf('a.pdf', 'same'));

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.documents.versions.store', [$this->project, $document]), ['file' => $this->pdf('b.pdf', 'same')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'version 1'));

    expect(versionRows($document))->toHaveCount(2);
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.documents.show', [$this->project, $document]))
        ->assertInertia(fn ($page) => $page->where('versions.0.duplicate_of', 1)->where('versions.1.duplicate_of', null));
});

test('versions are immutable and never deleted', function () {
    [$document] = $this->makeDocument();
    $version = versionRows($document)->first();

    expect(fn () => $version->forceFill(['file_path' => 'company/x/evil.pdf'])->save())->toThrow(LogicException::class)
        ->and(fn () => versionRows($document)->first()->forceFill(['notes' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => versionRows($document)->first()->delete())->toThrow(LogicException::class);
    expect(versionRows($document))->toHaveCount(1);
});

test('publish, archive and restore follow the lifecycle; archived documents take no versions but keep history', function () {
    [$document] = $this->makeDocument();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.versions.store', [$this->project, $document]), ['file' => $this->pdf('v2.pdf', 'v2')]);
    $as = fn () => $this->actingInCompany($this->pm, $this->company);

    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $sa = fn () => $this->actingInCompany($superAdmin, $this->company);
    $as()->post(route('projects.documents.archive', [$this->project, $document]))->assertForbidden();
    $sa()->post(route('projects.documents.archive', [$this->project, $document]))->assertSessionHasErrors('document');
    $as()->post(route('projects.documents.publish', [$this->project, $document]))->assertSessionHasNoErrors();
    $sa()->post(route('projects.documents.publish', [$this->project, $document]))->assertSessionHasErrors('document');
    expect($document->fresh()->status)->toBe(DocumentStatus::Active);
    $sa()->delete(route('projects.documents.destroy', [$this->project, $document]))->assertSessionHasErrors('document');

    $as()->post(route('projects.documents.archive', [$this->project, $document]))->assertSessionHasNoErrors();
    $fresh = $document->fresh();
    expect($fresh->status)->toBe(DocumentStatus::Archived)->and($fresh->archived_by)->toBe($this->pm->id);

    $as()->post(route('projects.documents.versions.store', [$this->project, $document]), ['file' => $this->pdf('v3.pdf', 'v3')])->assertForbidden();
    $sa()->post(route('projects.documents.versions.store', [$this->project, $document]), ['file' => $this->pdf('v3.pdf', 'v3')])->assertSessionHasErrors('document');
    $sa()->put(route('projects.documents.update', [$this->project, $document]), ['name' => 'Changed', 'category' => 'report'])->assertSessionHasErrors();
    expect(Storage::disk('private')->allFiles())->toHaveCount(2);
    expect(versionRows($document))->toHaveCount(2)->and($document->fresh()->name)->toBe('Structural specification');

    foreach (versionRows($document) as $version) {
        $this->actingInCompany($this->engineer, $this->company)
            ->get(route('projects.documents.versions.download', [$this->project, $document, $version]))->assertOk();
    }

    $as()->post(route('projects.documents.restore', [$this->project, $document]))->assertSessionHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Active)->and($document->fresh()->archived_at)->toBeNull();
});

test('a draft document can be deleted softly and its versions remain', function () {
    [$document] = $this->makeDocument();
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.documents.destroy', [$this->project, $document]))->assertRedirect();

    expect(Document::query()->withoutGlobalScopes()->withTrashed()->find($document->id)->trashed())->toBeTrue()
        ->and(versionRows($document))->toHaveCount(1);
    Storage::disk('private')->assertExists(versionRows($document)->first()->file_path);
});

test('permissions: viewers read, uploaders upload, only delete-holders archive', function () {
    [$document] = $this->makeDocument();
    $as = fn ($user) => $this->actingInCompany($user, $this->company);

    $as($this->engineer)->get(route('projects.documents.index', $this->project))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.create', false)->where('can.manageFolders', false));
    $as($this->engineer)->post(route('projects.documents.store', $this->project), ['name' => 'X', 'category' => 'other', 'file' => $this->pdf()])->assertForbidden();
    $as($this->engineer)->post(route('projects.documents.versions.store', [$this->project, $document]), ['file' => $this->pdf()])->assertForbidden();
    $as($this->engineer)->post(route('projects.documents.publish', [$this->project, $document]))->assertForbidden();
    $as($this->director)->delete(route('projects.documents.destroy', [$this->project, $document]))->assertForbidden();
    $as($this->billing)->get(route('projects.documents.index', $this->project))->assertForbidden();
});

test('version download is authorized per tenant, project and document', function () {
    [$document, $version] = $this->makeDocument();
    [$otherDocument] = $this->makeDocument(['name' => 'Other doc']);
    $outsider = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $download = fn ($project, $doc) => route('projects.documents.versions.download', [$project, $doc, $version]);

    $response = $this->actingInCompany($this->engineer, $this->company)->get($download($this->project, $document));
    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(hash('sha256', $response->streamedContent()))->toBe($version->checksum);

    $this->actingInCompany($outsider, $this->company)->get($download($this->project, $document))->assertForbidden();
    $this->actingInCompany($otherAdmin, $other)->get($download($this->project, $document))->assertNotFound();
    $this->actingInCompany($this->engineer, $this->company)->get($download($this->project, $otherDocument))->assertNotFound();
    $this->actingInCompany($this->qe, $this->company)->get($download($this->otherProject, $document))->assertNotFound();

    Storage::disk('private')->put($version->file_path, 'tampered');
    $this->actingInCompany($this->engineer, $this->company)->get($download($this->project, $document))->assertStatus(409);
});

test('documents and folders are tenant isolated and filtered by folder', function () {
    $as = fn () => $this->actingInCompany($this->pm, $this->company);
    $as()->post(route('projects.documents.folders.store', $this->project), ['name' => 'Permits']);
    $permits = folderRow($this, 'Permits');
    $this->makeDocument(['name' => 'Fire NOC', 'category' => 'permit', 'document_folder_id' => $permits->id]);
    [$unfiled] = $this->makeDocument(['name' => 'Kick-off minutes', 'category' => 'minutes']);

    $as()->get(route('projects.documents.index', [$this->project, 'folder' => $permits->id]))
        ->assertInertia(fn ($page) => $page->where('documents.total', 1)->where('documents.data.0.name', 'Fire NOC'));
    $as()->get(route('projects.documents.index', [$this->project, 'folder' => 0]))
        ->assertInertia(fn ($page) => $page->where('documents.total', 1)->where('unfiledCount', 1));

    $foreignFolder = $this->inCompany($this->company, function () {
        $f = new DocumentFolder;
        $f->forceFill(['project_id' => $this->otherProject->id, 'name' => 'Tower B'])->save();

        return $f;
    });
    $as()->get(route('projects.documents.index', [$this->project, 'folder' => $foreignFolder->id]))->assertNotFound();
    $as()->post(route('projects.documents.store', $this->project), ['name' => 'X', 'category' => 'other', 'document_folder_id' => $foreignFolder->id, 'file' => $this->pdf()])
        ->assertSessionHasErrors('document_folder_id');

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $other)->get(route('projects.documents.show', [$this->project, $unfiled]))->assertNotFound();
    $this->actingInCompany($otherAdmin, $other)->delete(route('projects.documents.folders.destroy', [$this->project, $permits]))->assertNotFound();
});

test('document ability flags follow state even for a platform super admin', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    [$document] = $this->makeDocument();
    $show = fn () => $this->actingInCompany($superAdmin, $this->company)->get(route('projects.documents.show', [$this->project, $document]));

    $show()->assertInertia(fn ($page) => $page->where('can', ['update' => true, 'addVersion' => true, 'publish' => true, 'archive' => false, 'restore' => false, 'delete' => true]));

    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.publish', [$this->project, $document]));
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.archive', [$this->project, $document]));
    $show()->assertInertia(fn ($page) => $page->where('can', ['update' => false, 'addVersion' => false, 'publish' => false, 'archive' => false, 'restore' => true, 'delete' => false]));
});
