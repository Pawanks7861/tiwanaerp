<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Support\Notifications\NotificationTypes;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/**
 * Mobile inbox over the same company-scoped Laravel notifications as the web bell.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): JsonResponse
    {
        $visible = Project::query()->visibleTo($request->user())->pluck('id')->map(fn ($id) => (string) $id)->all();
        $filters = $request->validate([
            'read' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'type' => ['nullable', Rule::in(NotificationTypes::keys())],
            'project_id' => ['nullable', Rule::in($visible !== [] ? $visible : ['0'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 30);

        $notifications = $this->query($request)
            ->when(($filters['read'] ?? 'all') === 'unread', fn (Builder $q) => $q->whereNull('read_at'))
            ->when(($filters['read'] ?? 'all') === 'read', fn (Builder $q) => $q->whereNotNull('read_at'))
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where('data->kind', $filters['type']))
            ->when(filled($filters['project_id'] ?? null), fn (Builder $q) => $q->where('data->project_id', (int) $filters['project_id']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => $this->payload($n));

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'from' => $notifications->firstItem(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'to' => $notifications->lastItem(),
                'total' => $notifications->total(),
            ],
            'links' => [
                'first' => $notifications->url(1),
                'last' => $notifications->url(max($notifications->lastPage(), 1)),
                'prev' => $notifications->previousPageUrl(),
                'next' => $notifications->nextPageUrl(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $this->query($request)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $this->query($request)->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json(['data' => $this->payload($row->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'data' => [
                'marked' => $updated,
                'unread_count' => 0,
            ],
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function payload(DatabaseNotification $n): array
    {
        $data = $n->data;

        return [
            'id' => $n->id,
            'type' => NotificationTypes::label($data['kind'] ?? null),
            'kind' => $data['kind'] ?? null,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['body'] ?? null,
            'created_at' => $n->created_at?->toIso8601String(),
            'read_at' => $n->read_at?->toIso8601String(),
            'is_read' => $n->read_at !== null,
            'project_id' => $data['project_id'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'mobile_route' => $data['mobile_route'] ?? null,
            'url' => $data['url'] ?? null,
        ];
    }
}
