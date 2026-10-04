<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\AuditLog;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Audit\AuditValueFormatter;
use App\Support\Reports\Sql;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company-wide audit log. Reads only: stored rows are never updated. Values are labelled and
 * masked on the way out. Permission: admin.audit_logs.view.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly AuditValueFormatter $formatter,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('admin.audit_logs.view'), 403);

        $companyId = $this->current->require()->id;
        $types = array_keys(Relation::morphMap());
        $visible = Project::query()->visibleTo($request->user())->pluck('id')->map(fn ($id) => (string) $id)->all();
        $members = User::query()
            ->whereHas('memberships', fn (Builder $m) => $m->where('company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d'], filled($request->query('from')) ? ['after_or_equal:from'] : []),
            'user_id' => ['nullable', Rule::in($members->pluck('id')->map(fn ($id) => (string) $id)->all() ?: ['0'])],
            'auditable_type' => ['nullable', Rule::in($types)],
            'event' => ['nullable', 'string', 'max:30'],
            'project_id' => ['nullable', Rule::in($visible !== [] ? $visible : ['0'])],
            'record_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()->forCurrentCompany()
            ->with('user:id,name')
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->where('created_at', '<=', Sql::eod($filters['to'])))
            ->when(filled($filters['user_id'] ?? null), fn (Builder $q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(filled($filters['auditable_type'] ?? null), fn (Builder $q) => $q->where('auditable_type', $filters['auditable_type']))
            ->when(filled($filters['event'] ?? null), fn (Builder $q) => $q->where('event', $filters['event']))
            ->when(filled($filters['record_id'] ?? null), fn (Builder $q) => $q->where('auditable_id', (int) $filters['record_id']))
            ->when(filled($filters['project_id'] ?? null), function (Builder $q) use ($filters) {
                $id = (int) $filters['project_id'];
                $q->where(function (Builder $w) use ($id) {
                    $w->where(fn (Builder $p) => $p->where('auditable_type', 'project')->where('auditable_id', $id))
                        ->orWhere('new_values->project_id', $id)
                        ->orWhere('old_values->project_id', $id);
                });
            })
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(function (Builder $w) use ($term) {
                    $w->where('event', 'like', $term)
                        ->orWhere('auditable_type', 'like', $term)
                        ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'event_label' => Str::of($log->event)->replace('_', ' ')->headline()->toString(),
                'entity' => $this->formatter->entityLabel($log->auditable_type),
                'entity_type' => $log->auditable_type,
                'record_id' => $log->auditable_id,
                'user' => $log->user?->name ?? 'System',
                'at' => $log->created_at?->toIso8601String(),
                'changes' => $this->formatter->changes($log->old_values ?? [], $log->new_values ?? []),
            ]);

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'user_id' => isset($filters['user_id']) ? (string) $filters['user_id'] : 'all',
                'auditable_type' => $filters['auditable_type'] ?? 'all',
                'event' => $filters['event'] ?? 'all',
                'project_id' => isset($filters['project_id']) ? (string) $filters['project_id'] : 'all',
                'record_id' => isset($filters['record_id']) ? (string) $filters['record_id'] : '',
                'search' => $filters['search'] ?? '',
            ],
            'options' => [
                'users' => array_merge(
                    [['value' => 'all', 'label' => 'All users']],
                    $members->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])->all(),
                ),
                'entities' => array_merge(
                    [['value' => 'all', 'label' => 'All records']],
                    array_map(fn (string $type) => ['value' => $type, 'label' => $this->formatter->entityLabel($type)], $types),
                ),
                'events' => [
                    ['value' => 'all', 'label' => 'All events'],
                    ['value' => 'created', 'label' => 'Created'],
                    ['value' => 'updated', 'label' => 'Updated'],
                    ['value' => 'deleted', 'label' => 'Deleted'],
                    ['value' => 'restored', 'label' => 'Restored'],
                ],
                'projects' => array_merge(
                    [['value' => 'all', 'label' => 'All projects']],
                    Project::query()->visibleTo($request->user())->orderBy('code')->get(['id', 'code', 'name'])
                        ->map(fn (Project $p) => ['value' => (string) $p->id, 'label' => "{$p->code} — {$p->name}"])->all(),
                ),
            ],
        ]);
    }
}
