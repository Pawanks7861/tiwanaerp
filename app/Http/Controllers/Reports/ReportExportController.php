<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Models\Reports\ReportExport;
use App\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a queued export: only its owner, in the same company (tenant scope), once ready,
 * and only while the owner can still open the report (and its project).
 */
class ReportExportController extends Controller
{
    public function download(Request $request, ReportExport $reportExport, ReportRegistry $registry): StreamedResponse
    {
        $user = $request->user();
        abort_unless((int) $reportExport->user_id === (int) $user->id, 403);
        abort_unless($reportExport->status === ReportExport::READY && $reportExport->file_path, 404);
        abort_unless($user->can('reports.export'), 403);

        $report = $registry->find($reportExport->report_key);
        abort_unless($report !== null && $report->allows($user), 403);
        if ($reportExport->project_id !== null) {
            abort_unless(Project::query()->visibleTo($user)->whereKey($reportExport->project_id)->exists(), 403);
        }

        $disk = Storage::disk(config('reports.export_disk'));
        abort_unless($disk->exists($reportExport->file_path), 404);

        return $disk->download($reportExport->file_path, $reportExport->file_name, ['Cache-Control' => 'no-store, private']);
    }
}
