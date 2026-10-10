<?php

use App\Models\Core\Attachment;
use App\Models\Masters\Vendor;
use App\Services\Files\LocalPreviewConverter;
use App\Services\Files\PreviewConversionException;
use App\Services\Files\PreviewConverter;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('private');

    $this->company = $this->createCompany();
    $this->other = $this->createCompany();
    $this->purchase = $this->createMember($this->company, DefaultRoles::PURCHASE_MANAGER);
    $this->stranger = $this->createMember($this->other, DefaultRoles::COMPANY_ADMIN);
    $this->vendor = $this->inCompany($this->company, fn () => Vendor::query()->create(['code' => 'VEN-VIEW', 'name' => 'Viewer Cement']));
});

function viewerFile($test, string $name, string $extension, string $contents, ?int $attachableId = null): Attachment
{
    return $test->inCompany($test->company, function () use ($test, $name, $extension, $contents, $attachableId) {
        $path = "company/{$test->company->id}/vendor/{$extension}-".md5($name).".{$extension}";
        Storage::disk('private')->put($path, $contents);

        $attachment = new Attachment;
        $attachment->forceFill([
            'attachable_type' => 'vendor',
            'attachable_id' => $attachableId ?? $test->vendor->id,
            'disk' => 'private',
            'path' => $path,
            'original_name' => $name,
            'mime' => 'application/octet-stream',
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'uploaded_by' => $test->purchase->id,
        ])->save();

        return $attachment;
    });
}

test('an authorized image streams with a private no-sniff response and a stranger is hidden', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $attachment = viewerFile($this, 'site.png', 'png', $png);

    $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $show = $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk();

    expect($show->json('file.stream_url'))->toContain('/files/attachment/')
        ->and($show->json('file.stream_url'))->not->toContain('/storage/')
        ->and($show->json('file.strategy'))->toBe('image');

    $headers = $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]))
        ->headers->get('Cache-Control');
    expect($headers)->toContain('private')->toContain('no-store');

    $this->actingInCompany($this->stranger, $this->other)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertNotFound();
    $this->actingInCompany($this->stranger, $this->other)
        ->get(route('files.download', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertNotFound();
});

test('a pdf preview stays on the authorized route', function () {
    $attachment = viewerFile($this, 'drawing.pdf', 'pdf', "%PDF-1.4\n");

    $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $url = $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->json('file.stream_url');

    expect($url)->toContain('/files/')->and($url)->not->toContain('/storage/');
});

test('text preview escapes nothing into html and keeps script text as data', function () {
    $attachment = viewerFile($this, 'note.txt', 'txt', '<script>alert(1)</script>');

    $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.preview', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('text', '<script>alert(1)</script>')
        ->assertJsonPath('kind', 'text');
});

test('a large text file is previewed from a capped prefix', function () {
    config(['uploads.preview_text_bytes' => 2048]);
    $attachment = viewerFile($this, 'big.txt', 'txt', str_repeat('A', 3000));

    $response = $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.preview', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk();

    expect(strlen($response->json('text')))->toBe(2048)
        ->and($response->json('truncated'))->toBeTrue()
        ->and($response->json('message'))->toBe('Large file — preview truncated');
});

test('a spreadsheet preview limits rows and returns formula text', function () {
    config(['uploads.preview_sheet_rows' => 2, 'uploads.preview_sheet_columns' => 2]);
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('A1', '=1+1');
    $sheet->setCellValue('B1', '<script>alert(1)</script>');
    $sheet->setCellValue('A2', 'two');
    $sheet->setCellValue('A3', 'three');
    $sheet->setCellValue('A4', 'four');
    $temp = tempnam(sys_get_temp_dir(), 'preview');
    (new Xlsx($book))->save($temp);
    $attachment = viewerFile($this, 'boq.xlsx', 'xlsx', (string) file_get_contents($temp));
    unlink($temp);

    $response = $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.preview', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/json');

    $rows = $response->json('sheets.0.rows');
    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe('=1+1')
        ->and($rows[0][1])->toBe('<script>alert(1)</script>')
        ->and($response->json('message'))->toBe('Preview limited for performance.');
});

test('a dwg preview is generated by the local converter and the original name never reaches the command', function () {
    $fake = new class implements PreviewConverter
    {
        public array $paths = [];

        public function officeAvailable(): bool
        {
            return true;
        }

        public function cadAvailable(): bool
        {
            return true;
        }

        public function toPdf(string $sourceAbsolute, string $directory): string
        {
            $this->paths[] = $sourceAbsolute;
            $target = $directory.DIRECTORY_SEPARATOR.'preview.pdf';
            file_put_contents($target, "%PDF-1.4\n");

            return $target;
        }

        public function toSvg(string $sourceAbsolute, string $directory): string
        {
            $this->paths[] = $sourceAbsolute;
            $target = $directory.DIRECTORY_SEPARATOR.'preview.svg';
            file_put_contents($target, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><line x1="0" y1="0" x2="1" y2="1"/></svg>');

            return $target;
        }
    };
    $this->app->instance(PreviewConverter::class, $fake);

    $attachment = viewerFile($this, 'plan"; $(rm).dwg', 'dwg', 'AC1032 fake');

    $show = $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk();

    expect($show->json('file.status'))->toBe('ready')
        ->and($show->json('file.note'))->toContain('Original file unchanged')
        ->and(basename($fake->paths[0]))->toBe('input.dwg')
        ->and($fake->paths[0])->not->toContain('rm');

    $stream = $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
    expect(file_get_contents($stream->baseResponse->getFile()->getPathname()))->toContain('<svg');

    $download = $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.download', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk();
    expect(file_get_contents($download->baseResponse->getFile()->getPathname()))->toBe('AC1032 fake');

    $this->actingInCompany($this->stranger, $this->other)
        ->get(route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertNotFound();
});

test('a missing cad converter falls back to download without failing the file', function () {
    $this->app->instance(PreviewConverter::class, new class implements PreviewConverter
    {
        public function officeAvailable(): bool
        {
            return false;
        }

        public function cadAvailable(): bool
        {
            return false;
        }

        public function toPdf(string $sourceAbsolute, string $directory): string
        {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        public function toSvg(string $sourceAbsolute, string $directory): string
        {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }
    });

    $attachment = viewerFile($this, 'plan.dwg', 'dwg', 'AC1032 fake');

    $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertJsonPath('file.status', 'unsupported')
        ->assertJsonPath('file.message', 'DWG preview unavailable on this server');

    $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.download', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk();
});

test('a text dxf becomes a private svg preview without an external converter', function () {
    $dxf = implode("\n", ['0', 'SECTION', '2', 'ENTITIES', '0', 'LINE', '10', '0', '20', '0', '11', '10', '21', '5', '0', 'ENDSEC', '0', 'EOF']);
    $attachment = viewerFile($this, 'grid.dxf', 'dxf', $dxf);

    $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertJsonPath('file.status', 'ready');

    $stream = $this->actingInCompany($this->purchase, $this->company)
        ->get(route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
    $svg = file_get_contents($stream->baseResponse->getFile()->getPathname());
    expect($svg)->toContain('<svg')->and($svg)->not->toContain('<script');
});

test('staged conversion names ignore quotes spaces and traversal', function () {
    $converter = new LocalPreviewConverter;

    expect($converter->stagedBasename('dwg'))->toBe('input.dwg')
        ->and($converter->stagedBasename('docx'))->toBe('input.docx');

    expect(fn () => $converter->guardedExtension('dwg;rm'))->toThrow(PreviewConversionException::class);
    expect(fn () => $converter->guardedExtension('../dwg'))->toThrow(PreviewConversionException::class);
    expect(fn () => $converter->guardedExtension('dwg"'))->toThrow(PreviewConversionException::class);
});

test('an attachment whose parent cannot be viewed is refused', function () {
    $attachment = viewerFile($this, 'hidden.pdf', 'pdf', "%PDF-1.4\n", 999999);

    $this->actingInCompany($this->purchase, $this->company)
        ->getJson(route('files.show', ['source' => 'attachment', 'id' => $attachment->id]))
        ->assertForbidden();
});

test('video bytes can be requested as a range', function () {
    $attachment = viewerFile($this, 'walk.mp4', 'mp4', str_repeat('v', 200));

    $response = $this->actingInCompany($this->purchase, $this->company)
        ->call('GET', route('files.stream', ['source' => 'attachment', 'id' => $attachment->id]), [], [], [], ['HTTP_RANGE' => 'bytes=0-9']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('content-type'))->toBe('video/mp4')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});
