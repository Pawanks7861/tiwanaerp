<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app notifications of the current company only.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        $notifications = $this->query($request)
            ->latest()
            ->paginate(30)
            ->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notification',
                'body' => $n->data['body'] ?? null,
                'url' => $n->data['url'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Notifications/Index', ['notifications' => $notifications]);
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
