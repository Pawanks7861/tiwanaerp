<?php

use App\Models\Core\Attachment;
use App\Models\Documents\Document;
use App\Models\Documents\Drawing;
use App\Models\Masters\Vendor;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Models\Uploads\UploadSession;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
    config(['uploads.chunk_bytes' => 8]);
    $this->buyer = $this->createMember($this->company, DefaultRoles::PURCHASE_MANAGER);
    $this->vendor = $this->inCompany($this->company, fn () => Vendor::query()->create([
        'code' => 'VEN-UP-1',
        'name' => 'Upload Cement',
    ]));
});

function gbInit($test, $user, string $name, int $size, array $extra = [])
{
    return $test->actingInCompany($user, $test->company)->postJson(route('uploads.store'), array_merge([
        'original_name' => $name,
        'total_size' => $size,
        'module' => 'generic',
    ], $extra));
}

function gbChunk($test, $user, string $id, int $number, string $part, ?string $checksum = null)
{
    $payload = ['chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $part)];
    if ($checksum !== null) {
        $payload['checksum'] = $checksum;
    }

    return $test->actingInCompany($user, $test->company)->post(
        route('uploads.chunks.store', ['upload' => $id, 'number' => $number]),
        $payload,
        ['Accept' => 'application/json'],
    );
}

function gbParts(string $bytes): array
{
    return str_split($bytes, (int) config('uploads.chunk_bytes'));
}

function gbFinish($test, $user, string $name, string $bytes, array $extra = [], ?string $checksum = null): string
{
    $response = gbInit($test, $user, $name, strlen($bytes), $extra)->assertCreated();
    $id = $response->json('upload.id');
    foreach (gbParts($bytes) as $index => $part) {
        gbChunk($test, $user, $id, $index + 1, $part)->assertOk();
    }
    $done = $test->actingInCompany($user, $test->company)->postJson(route('uploads.complete', $id), array_filter([
        'checksum' => $checksum,
    ], fn ($value) => $value !== null));
    $done->assertOk();

    return $id;
}

test('the generic ceiling is one binary gigabyte and oversized metadata is refused', function () {
    config(['uploads.chunk_bytes' => 1024 * 1024]);
    expect((int) config('uploads.max_file_size_bytes'))->toBe(1073741824);

    gbInit($this, $this->pm, 'just-under.bin', 1073741824 - 1)->assertCreated();
    gbInit($this, $this->pm, 'exact.bin', 1073741824)->assertCreated();
    gbInit($this, $this->pm, 'over.bin', 1073741824 + 1)
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'File exceeds 1 GB');
});

test('chunks can arrive out of order, a duplicate is ignored, and a missing chunk cannot finish', function () {
    $bytes = 'abcdefghijklmnopqr';
    $id = gbInit($this, $this->pm, 'notes.bin', strlen($bytes))->assertCreated()->json('upload.id');
    $parts = gbParts($bytes);

    gbChunk($this, $this->pm, $id, 2, $parts[1])->assertOk();
    gbChunk($this, $this->pm, $id, 1, $parts[0])->assertOk();
    gbChunk($this, $this->pm, $id, 1, $parts[0])->assertOk();

    $status = $this->actingInCompany($this->pm, $this->company)->getJson(route('uploads.show', $id))->assertOk();
    expect($status->json('upload.uploaded_chunks'))->toBe(2)
        ->and($status->json('upload.received'))->toBe([1, 2]);

    $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('uploads.complete', $id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Upload interrupted — Retry');

    gbChunk($this, $this->pm, $id, 3, $parts[2])->assertOk();
    $this->actingInCompany($this->pm, $this->company)->postJson(route('uploads.complete', $id))->assertOk();
    $this->actingInCompany($this->pm, $this->company)->postJson(route('uploads.complete', $id))->assertOk();

    $session = $this->inCompany($this->company, fn () => UploadSession::query()->findOrFail($id));
    expect($session->status)->toBe('completed')
        ->and($session->checksum)->toBe(hash('sha256', $bytes))
        ->and($session->final_path)->not->toContain('notes.bin');
    Storage::disk('private')->assertExists($session->final_path);
});

test('a bad chunk checksum can be retried without restarting the upload', function () {
    $bytes = 'abcdefghijklmnop';
    $id = gbInit($this, $this->pm, 'retry.bin', strlen($bytes))->assertCreated()->json('upload.id');
    $parts = gbParts($bytes);

    gbChunk($this, $this->pm, $id, 1, $parts[0], str_repeat('ab', 32))
        ->assertUnprocessable()
        ->assertJsonPath('errors.chunk.0', 'File corrupted during upload');

    gbChunk($this, $this->pm, $id, 1, $parts[0], hash('sha256', $parts[0]))->assertOk();
    gbChunk($this, $this->pm, $id, 2, $parts[1])->assertOk();
    $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('uploads.complete', $id), ['checksum' => hash('sha256', $bytes)])
        ->assertOk()
        ->assertJsonPath('upload.checksum', hash('sha256', $bytes));
});

test('the wrong final checksum is rejected and a cancelled or expired upload cannot finish', function () {
    $bytes = 'abcdefghijklmnop';
    $id = gbFinish($this, $this->pm, 'good.bin', $bytes, [], hash('sha256', $bytes));
    expect($this->inCompany($this->company, fn () => UploadSession::query()->find($id))->status)->toBe('completed');

    $bad = gbInit($this, $this->pm, 'bad.bin', strlen($bytes))->assertCreated()->json('upload.id');
    foreach (gbParts($bytes) as $index => $part) {
        gbChunk($this, $this->pm, $bad, $index + 1, $part)->assertOk();
    }
    $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('uploads.complete', $bad), ['checksum' => str_repeat('c', 64)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Final checksum mismatch');

    $cancelled = gbInit($this, $this->pm, 'stop.bin', strlen($bytes))->assertCreated()->json('upload.id');
    $this->actingInCompany($this->pm, $this->company)->deleteJson(route('uploads.cancel', $cancelled))->assertOk();
    gbChunk($this, $this->pm, $cancelled, 1, gbParts($bytes)[0])->assertUnprocessable();
    $this->actingInCompany($this->pm, $this->company)->postJson(route('uploads.complete', $cancelled))->assertUnprocessable();

    $expired = gbInit($this, $this->pm, 'old.bin', strlen($bytes))->assertCreated()->json('upload.id');
    $this->inCompany($this->company, function () use ($expired) {
        UploadSession::query()->whereKey($expired)->update(['expires_at' => now()->subMinute()]);
    });
    $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('uploads.complete', $expired))
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Upload expired');
});

test('executable names, double extensions and path traversal are blocked while an unknown binary is stored', function () {
    foreach (['shell.php', 'invoice.pdf.php', 'setup.exe', 'run.bat', '../../.env'] as $name) {
        gbInit($this, $this->pm, $name, 16)->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'File type not permitted for security reasons');
    }

    $php = "<?php echo 'no';";
    $id = gbInit($this, $this->pm, 'notes.txt', strlen($php))->assertCreated()->json('upload.id');
    foreach (gbParts($php) as $index => $part) {
        gbChunk($this, $this->pm, $id, $index + 1, $part)->assertOk();
    }
    $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('uploads.complete', $id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'File type not permitted for security reasons');

    $stored = gbFinish($this, $this->pm, 'model.rvt', 'RVT-model-bytes');
    $session = $this->inCompany($this->company, fn () => UploadSession::query()->findOrFail($stored));
    expect($session->status)->toBe('completed')
        ->and($session->scan_status)->toBe('not_configured')
        ->and($session->original_name)->toBe('model.rvt');
    Storage::disk('private')->assertExists($session->final_path);
    expect($session->final_path)->toStartWith('uploads/ready/');
});

test('another user or company cannot read, resume, finish or cancel an upload', function () {
    $id = gbInit($this, $this->pm, 'secret.bin', 8)->assertCreated()->json('upload.id');
    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($this->engineer, $this->company)->getJson(route('uploads.show', $id))->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('uploads.chunks.store', ['upload' => $id, 'number' => 1]), [
            'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'abcdefgh'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->postJson(route('uploads.complete', $id))->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->deleteJson(route('uploads.cancel', $id))->assertForbidden();

    $this->actingInCompany($outsider, $other)->getJson(route('uploads.show', $id))->assertNotFound();
    $this->actingInCompany($outsider, $other)->postJson(route('uploads.complete', $id))->assertNotFound();
    $this->actingInCompany($outsider, $other)->deleteJson(route('uploads.cancel', $id))->assertNotFound();
});

test('a forged record, a chat outsider and an inaccessible project are refused', function () {
    gbInit($this, $this->buyer, 'bill.bin', 8, [
        'module' => 'attachment',
        'source_type' => 'vendor',
        'source_id' => 999999,
    ])->assertNotFound();

    gbInit($this, $this->engineer, 'doc.bin', 8, [
        'module' => 'document',
        'source_type' => 'project',
        'source_id' => $this->project->id,
    ])->assertForbidden();

    $conversation = $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('chat.direct'), ['user_id' => $this->buyer->id])
        ->assertOk()
        ->json('id');

    gbInit($this, $this->engineer, 'chat.bin', 8, [
        'module' => 'chat',
        'source_type' => 'conversation',
        'source_id' => $conversation,
    ])->assertNotFound();
});

test('documents, drawings, photos, chat and record attachments share the uploader', function () {
    $documentId = gbFinish($this, $this->pm, 'spec.bin', 'structural specification', [
        'module' => 'document',
        'source_type' => 'project',
        'source_id' => $this->project->id,
    ]);
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.documents.store', $this->project), [
        'name' => 'Structural specification',
        'category' => 'other',
        'upload_id' => $documentId,
    ])->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => Document::query()->where('name', 'Structural specification')->exists()))->toBeTrue();

    $drawingId = gbFinish($this, $this->pm, 'plan.dwg', "AC1032\n".str_repeat(' ', 24), [
        'module' => 'drawing',
        'source_type' => 'project',
        'source_id' => $this->project->id,
    ]);
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.drawings.store', $this->project), [
        'drawing_number' => 'ARC-UP-1',
        'title' => 'Ground floor',
        'discipline' => 'architectural',
        'revision_code' => 'R0',
        'upload_id' => $drawingId,
    ])->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => Drawing::query()->where('drawing_number', 'ARC-UP-1')->exists()))->toBeTrue();

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $diary = $this->makeDiary();
    $photoId = gbFinish($this, $this->engineer, 'site.png', $png, [
        'module' => 'site_photo',
        'source_type' => 'site_diary',
        'source_id' => $diary->id,
    ]);
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.site-diaries.photos.store', [$this->project, $diary]), ['upload_id' => $photoId])
        ->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => SiteDiaryPhoto::query()->where('site_diary_id', $diary->id)->count()))->toBe(1);

    $inspection = $this->requestInspection();
    gbFinish($this, $this->engineer, 'check.bin', 'inspection-photo-note', [
        'module' => 'attachment',
        'source_type' => 'quality_inspection',
        'source_id' => $inspection->id,
    ], null);
    gbFinish($this, $this->buyer, 'quote.bin', 'vendor-quotation-note', [
        'module' => 'attachment',
        'source_type' => 'vendor',
        'source_id' => $this->vendor->id,
    ]);

    $rows = $this->inCompany($this->company, fn () => Attachment::query()->get());
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->path)->toStartWith("company/{$this->company->id}/")
            ->and($row->checksum)->toHaveLength(64);
        Storage::disk('private')->assertExists($row->path);
    }

    $conversation = $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('chat.direct'), ['user_id' => $this->engineer->id])
        ->json('id');
    $chatId = gbFinish($this, $this->pm, 'voice.bin', 'chat-file-bytes', [
        'module' => 'chat',
        'source_type' => 'conversation',
        'source_id' => $conversation,
    ]);
    $this->actingInCompany($this->pm, $this->company)
        ->postJson(route('chat.messages.store', $conversation), [
            'body' => 'See the attached file',
            'upload_ids' => [$chatId],
        ])
        ->assertOk()
        ->assertJsonPath('message.attachments.0.name', 'voice.bin');
});

test('an authorized download is streamed with private no-sniff headers and a stranger is refused', function () {
    $bytes = 'not-an-executable';
    gbFinish($this, $this->buyer, 'archive.bin', $bytes, [
        'module' => 'attachment',
        'source_type' => 'vendor',
        'source_id' => $this->vendor->id,
    ]);
    $attachment = $this->inCompany($this->company, fn () => Attachment::query()->sole());

    $response = $this->actingInCompany($this->buyer, $this->company)->get(route('attachments.download', $attachment));
    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->streamedContent())->toBe($bytes);

    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('attachments.download', $attachment))->assertNotFound();
});

test('logo and favicon stay far below the generic limit', function () {
    expect((int) config('uploads.logo_max_kb'))->toBeLessThanOrEqual(10240)
        ->and((int) config('uploads.favicon_max_kb'))->toBeLessThanOrEqual(5120)
        ->and((int) config('uploads.logo_max_kb') * 1024)->toBeLessThan((int) config('uploads.max_file_size_bytes'));

    $admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($admin, $this->company)
        ->post(route('admin.company.logo.store'), [
            'file' => UploadedFile::fake()->create('logo.png', 3000, 'image/png'),
        ])
        ->assertSessionHasErrors('file');
});

test('a company quota blocks a new upload and cleanup drops abandoned chunks only', function () {
    config(['uploads.company_quota_bytes' => 4]);
    gbInit($this, $this->pm, 'too-big.bin', 8)
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Insufficient storage space');

    config(['uploads.company_quota_bytes' => null]);
    $id = gbInit($this, $this->pm, 'later.bin', 8)->assertCreated()->json('upload.id');
    gbChunk($this, $this->pm, $id, 1, 'abcdefgh')->assertOk();
    $this->inCompany($this->company, function () use ($id) {
        UploadSession::query()->whereKey($id)->update(['expires_at' => now()->subHour()]);
    });

    $this->artisan('uploads:cleanup')->assertSuccessful();

    $session = UploadSession::withoutGlobalScopes()->find($id);
    expect($session->status)->toBe('expired');
    Storage::disk('private')->assertMissing($session->temp_path.'/1');
});
