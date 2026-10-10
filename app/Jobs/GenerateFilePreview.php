<?php

namespace App\Jobs;

use App\Models\Core\Company;
use App\Models\Files\FilePreview;
use App\Services\Files\FilePreviewService;
use App\Services\Files\FileSourceResolver;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Builds a private preview after the upload has already succeeded. A missing converter
 * records an unsupported status and does not fail the original file.
 */
class GenerateFilePreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly string $source,
        public readonly int $id,
        public readonly int $companyId,
    ) {}

    public function handle(FileSourceResolver $files, FilePreviewService $previews, CurrentCompany $current): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null) {
            return;
        }

        $current->runAs($company, function () use ($files, $previews) {
            try {
                $file = $files->locate($this->source, $this->id);
            } catch (ModelNotFoundException|HttpException) {
                return;
            }

            if ($file->companyId !== $this->companyId) {
                return;
            }

            $previews->generate($file);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null) {
            return;
        }

        app(CurrentCompany::class)->runAs($company, function () {
            $preview = FilePreview::query()
                ->where('source_type', $this->source)
                ->where('source_id', $this->id)
                ->first();

            if ($preview !== null && $preview->status !== FilePreview::READY) {
                $preview->forceFill([
                    'status' => FilePreview::FAILED,
                    'error_message' => 'Conversion failed',
                ])->save();
            }
        });
    }
}
