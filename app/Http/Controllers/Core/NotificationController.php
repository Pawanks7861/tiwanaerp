<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Support\Notifications\NotificationTypes;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app notifications of the current company only. Filters (read state, type, project) stay in
 * the URL. Mark read, mark all read and the source link are unchanged.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        $visible = Project::query()->visibleTo($request->user())->pluck('id')->map(fn ($id) => (string) $id)->all();
        $filters = $request->validate([
            'read' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'type' => ['nullable', Rule::in(NotificationTypes::keys())],
            'project_id' => ['nullable', Rule::in($visible !== [] ? $visible : ['0'])],
        ]);

        $notifications = $this->query($request)
            ->when(($filters['read'] ?? 'all') === 'unread', fn (Builder $q) => $q->whereNull('read_at'))
            ->when(($filters['read'] ?? 'all') === 'read', fn (Builder $q) => $q->whereNotNull('read_at'))
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where('data->kind', $filters['type']))
            ->when(filled($filters['project_id'] ?? null), fn (Builder $q) => $q->where('data->project_id', (int) $filters['project_id']))
            ->latest()
            ->paginate(30)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? null,
                'type' => NotificationTypes::label($n->data['kind'] ?? null),
                'title' => $n->data['title'] ?? 'Notification',
                'body' => $n->data['body'] ?? null,
                'url' => $n->data['url'] ?? null,
                'project_id' => $n->data['project_id'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filters' => [
                'read' => $filters['read'] ?? 'all',
                'type' => $filters['type'] ?? 'all',
                'project_id' => isset($filters['project_id']) ? (string) $filters['project_id'] : 'all',
            ],
            'options' => [
                'read' => [
                    ['value' => 'all', 'label' => 'All'],
                    ['value' => 'unread', 'label' => 'Unread'],
                    ['value' => 'read', 'label' => 'Read'],
                ],
                'types' => array_merge(
                    [['value' => 'all', 'label' => 'All types']],
                    array_map(fn (array $t) => ['value' => $t['key'], 'label' => $t['label']], NotificationTypes::all()),
                ),
                'projects' => array_merge(
                    [['value' => 'all', 'label' => 'All projects']],
                    Project::query()->visibleTo($request->user())->orderBy('code')->get(['id', 'code', 'name'])
                        ->map(fn (Project $p) => ['value' => (string) $p->id, 'label' => "{$p->code} — {$p->name}"])->all(),
                ),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->query($request)->whereKey($notification)->firstOrFail()->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function query(Request $request): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $request->user()->getMorphClass())
            ->where('notifiable_id', $request->user()->id)
            ->where('company_id', $this->tenancy->require()->id);
    }
}
