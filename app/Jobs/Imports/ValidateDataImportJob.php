<?php

namespace App\Jobs\Imports;

use App\Enums\Imports\ImportStatus;
use App\Models\Imports\DataImport;
use App\Services\Imports\DataImportService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateDataImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    public function handle(DataImportService $imports, CurrentCompany $company): void
    {
        $import = DataImport::query()->withoutGlobalScopes()->findOrFail($this->importId);

        $company->runAs($import->company, fn () => $imports->runValidation($import));
    }

    public function failed(?Throwable $e): void
    {
        $import = DataImport::query()->withoutGlobalScopes()->find($this->importId);
        if (! $import) {
            return;
        }

        $import->forceFill([
            'status' => ImportStatus::Failed,
            'failure_message' => 'Validation failed. Re-upload the file and try again.',
            'finished_at' => now(),
        ])->save();
    }
}
