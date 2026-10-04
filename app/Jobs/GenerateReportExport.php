<?php

namespace App\Jobs;

use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\Reports\ReportExport;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Generates a large report export in the background. Permissions are re-checked at generation
 * time (membership, report access, project visibility), the file goes to private storage and is
 * downloadable only by its owner through ReportExportController.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportRegistry $registry, ReportRunner $runner, ReportExporter $exporter, CurrentCompany $current, PermissionRegistrar $permissions): void
    {
        $export = ReportExport::query()->withoutGlobalScope(CompanyScope::class)->find($this->exportId);
        if ($export === null || $export->status !== ReportExport::QUEUED) {
            return;
        }
        $company = Company::query()->find($export->company_id);
        $user = User::query()->find($export->user_id);

        $current->runAs($company, function () use ($export, $company, $user, $registry, $runner, $exporter, $permissions) {
            $previousTeam = $permissions->getPermissionsTeamId();
            $permissions->setPermissionsTeamId($company->id);
            $user?->unsetRelation('roles')->unsetRelation('permissions');

            try {
                $member = $user !== null && $user->is_active && DB::table('company_user')->where('company_id', $company->id)
                    ->where('user_id', $user->id)->where('is_active', true)->exists();
                $report = $registry->find($export->report_key);
                if (! $member || $report === null || ! $report->allows($user)) {
                    throw new RuntimeException('You no longer have access to this report.');
                }
                $project = null;
                if ($export->project_id !== null) {
                    $project = Project::query()->visibleTo($user)->find($export->project_id)
                        ?? throw new RuntimeException('You no longer have access to this project.');
                }

                $ctx = $runner->context($report, $user, $company, $project, $export->filters['input'] ?? []);
                $file = $exporter->render($report, $ctx, $export->format);
                $path = trim(config('reports.export_directory'), '/')."/{$company->id}/".Str::uuid().'.'.$export->format;
                Storage::disk(config('reports.export_disk'))->put($path, $file['content']);

                $export->forceFill([
                    'status' => ReportExport::READY,
                    'file_path' => $path,
                    'file_name' => $file['name'],
                    'size_bytes' => strlen($file['content']),
                    'row_count' => $file['rows'],
                    'completed_at' => now(),
                ])->save();

                $user->notify(new GeneralNotification($company->id, 'reports.export_ready', 'Report export ready',
                    "{$report->title()} ({$export->format}) is ready to download.", route('reports.exports.download', $export->id),
                    ['project_id' => $export->project_id]));
            } catch (Throwable $e) {
                $export->forceFill(['status' => ReportExport::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500), 'completed_at' => now()])->save();
                if (! $e instanceof RuntimeException) {
                    report($e);
                }
            } finally {
                $permissions->setPermissionsTeamId($previousTeam);
                $user?->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }
}
