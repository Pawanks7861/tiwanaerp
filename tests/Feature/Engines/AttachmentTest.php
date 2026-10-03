<?php

use App\Models\Core\Attachment;
use App\Models\Masters\Vendor;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->company = $this->createCompany();
    $this->purchase = $this->createMember($this->company, DefaultRoles::PURCHASE_MANAGER);
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->vendor = $this->inCompany($this->company, fn () => Vendor::query()->create(['code' => 'VEN-0001', 'name' => 'Shree Cement']));
});

function uploadTo($test, $user, UploadedFile $file)
{
    return $test->actingInCompany($user, $test->company)->post(route('attachments.store'), [
        'attachable_type' => 'vendor',
        'attachable_id' => $test->vendor->id,
        'category' => 'GST certificate',
        'file' => $file,
    ]);
}

test('a file is stored privately under the company folder and can be downloaded by members', function () {
    uploadTo($this, $this->purchase, UploadedFile::fake()->create('gst.pdf', 20, 'application/pdf'))
        ->assertSessionHasNoErrors();

    $attachment = $this->inCompany($this->company, fn () => Attachment::query()->sole());

    expect($attachment->path)->toStartWith("company/{$this->company->id}/vendor/")
        ->and($attachment->original_name)->toBe('gst.pdf')
        ->and($attachment->uploaded_by)->toBe($this->purchase->id)
        ->and($attachment->checksum)->toHaveLength(64);
    Storage::disk('private')->assertExists($attachment->path);

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('attachments.download', $attachment))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('disallowed or disguised file types are rejected', function (UploadedFile $file) {
    uploadTo($this, $this->purchase, $file)->assertSessionHasErrors('file');

    expect($this->inCompany($this->company, fn () => Attachment::query()->count()))->toBe(0);
})->with([
    'executable' => fn () => UploadedFile::fake()->create('setup.exe', 5, 'application/x-msdownload'),
    'php script' => fn () => UploadedFile::fake()->create('shell.php', 5, 'application/x-php'),
    'script disguised as pdf' => fn () => UploadedFile::fake()->create('invoice.pdf', 5, 'application/x-php'),
]);

test('users who cannot edit the record cannot attach files to it', function () {
    uploadTo($this, $this->engineer, UploadedFile::fake()->create('gst.pdf', 20, 'application/pdf'))->assertForbidden();
});

test('only morph types that accept attachments can be targeted', function () {
    $this->actingInCompany($this->purchase, $this->company)->post(route('attachments.store'), [
        'attachable_type' => 'user',
        'attachable_id' => $this->purchase->id,
        'file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
    ])->assertSessionHasErrors('attachable_type');
});

test('deleting an attachment is a soft delete that keeps the file', function () {
    uploadTo($this, $this->purchase, UploadedFile::fake()->create('gst.pdf', 20, 'application/pdf'));
    $attachment = $this->inCompany($this->company, fn () => Attachment::query()->sole());

    $this->actingInCompany($this->engineer, $this->company)->delete(route('attachments.destroy', $attachment))->assertForbidden();
    $this->actingInCompany($this->purchase, $this->company)->delete(route('attachments.destroy', $attachment))->assertSessionHasNoErrors();

    $trashed = Attachment::withoutGlobalScopes()->withTrashed()->find($attachment->id);
    expect($trashed->trashed())->toBeTrue()->and($trashed->deleted_by)->toBe($this->purchase->id);
    Storage::disk('private')->assertExists($attachment->path);
});
