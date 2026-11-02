<?php

namespace App\Services\Imports;

use App\Exports\Imports\ImportTemplateExport;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Imports\ImportCatalog;
use App\Support\Imports\ImportDefinition;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Builds downloadable import templates from ImportDefinition schemas so headers
 * always match the universal importer.
 */
class ImportTemplateService
{
    public function definition(string $module): ImportDefinition
    {
        return ImportCatalog::get($module);
    }

    public function download(User $user, string $module, string $format = 'xlsx', ?Project $project = null): BinaryFileResponse
    {
        $definition = $this->definition($module);
        abort_unless($definition->authorize($user, $project), 403);

        if ($definition->requiresProject() && $project === null) {
            // Template download is allowed without project for schema preview, but
            // reference sheets that need project context will be empty.
        }

        $format = strtolower($format) === 'csv' ? 'csv' : 'xlsx';
        $basename = $definition->templateFilename();
        $export = ImportTemplateExport::for($definition, $user, $project);

        if ($format === 'csv') {
            return Excel::download(
                $export->dataSheetOnly(),
                $basename.'.csv',
                ExcelFormat::CSV,
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        }

        return Excel::download(
            $export,
            $basename.'.xlsx',
            ExcelFormat::XLSX,
        );
    }
}
