<?php

namespace App\Jobs\Imports;

use App\Enums\Imports\ImportStatus;
use App\Models\Imports\DataImport;
use App\Services\Imports\DataImportService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDataImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    public function uniqueId(): string
    {
        return 'data-import-'.$this->importId;
    }

    public function handle(DataImportService $imports, CurrentCompany $company): void
    {
        $import = DataImport::query()->withoutGlobalScopes()->findOrFail($this->importId);

        $company->runAs($import->company, fn () => $imports->runProcess($import));
    }

    public function failed(?Throwable $e): void
    {
        $import = DataImport::query()->withoutGlobalScopes()->find($this->importId);
        if (! $import) {
            return;
        }

        $import->forceFill([
            'status' => ImportStatus::Failed,
            'failure_message' => 'Processing failed. Nothing was committed. Fix the file and start a new import.',
            'finished_at' => now(),
        ])->save();
    }
}
