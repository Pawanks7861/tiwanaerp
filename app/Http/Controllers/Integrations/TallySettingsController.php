<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Tally\TallyConnectionTester;
use App\Integrations\Tally\TallyMasterSyncService;
use App\Integrations\Tally\TallySyncService;
use App\Models\Integrations\TallyConnection;
use App\Models\Projects\Project;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TallySettingsController extends Controller
{
    public function __construct(
        private readonly TallyConnectionTester $tester,
        private readonly TallySyncService $sync,
        private readonly TallyMasterSyncService $masters,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): Response
    {
        abort_unless($request->user()->can('tally.view'), 403);
        $connection = TallyConnection::query()->first();
        if ($connection?->enabled && $connection->host && ($connection->last_checked_at === null || $connection->last_checked_at->lt(now()->subMinutes(15)))) {
            $this->tester->test($connection);
            $connection->refresh();
        }

        return Inertia::render('Integrations/Tally/Settings', [
            'connection' => $this->payload($connection),
            'can' => $this->abilities($request),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (Project $project) => [
                'value' => $project->id,
                'label' => $project->code.' — '.$project->name,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.manage'), 403);
        $data = $this->validated($request);
        $connection = TallyConnection::query()->first() ?? new TallyConnection;
        $before = $connection->exists ? $connection->only(['enabled', 'transport', 'host', 'port', 'tally_company_name', 'format', 'auto_sync', 'dry_run']) : null;
        $connection->forceFill($data)->save();
        $this->audit->record($connection, 'tally_settings_updated', $before, $connection->only(array_keys($before ?? $data)));

        return back()->with('success', 'Tally settings saved.');
    }

    public function test(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.manage'), 403);
        $connection = TallyConnection::query()->first();
        if ($connection === null) {
            return back()->with('error', 'Save the connection before testing it.');
        }
        $result = $this->tester->test($connection);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function syncMasters(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.sync'), 403);
        $counts = $this->masters->syncMappedMasters();

        return back()->with('success', "Masters checked. Synced {$counts['synced']}, conflicts {$counts['conflicts']}, skipped {$counts['skipped']}.");
    }

    public function syncPending(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.sync'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);
        $count = $this->sync->enqueuePending($filters);

        return back()->with('success', $count === 0 ? 'Nothing is waiting to sync.' : "Queued {$count} documents. At most 50 are sent in one batch.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'transport' => ['required', Rule::in(['direct', 'connector'])],
            'protocol' => ['required', Rule::in(['http', 'https'])],
            'format' => ['required', Rule::in(['xml', 'json'])],
            'host' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:enabled,true', 'integer', 'min:1', 'max:65535'],
            'tally_company_name' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'timeout_seconds' => ['required', 'integer', 'min:3', 'max:60'],
            'auto_sync' => ['required', 'boolean'],
            'sync_approved_transactions' => ['required', 'boolean'],
            'dry_run' => ['required', 'boolean'],
            'cost_centres_enabled' => ['required', 'boolean'],
        ]);
        if ($data['enabled']) {
            $this->assertPrivateHost($data['host']);
        }

        return $data;
    }

    private function assertPrivateHost(string $host): void
    {
        $host = trim($host);
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/')) {
            throw ValidationException::withMessages(['host' => 'Enter a host name or IP, without a URL path.']);
        }
        $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($public !== false) {
            throw ValidationException::withMessages(['host' => 'Tally must stay on localhost, a private LAN, or a VPN. Do not expose port 9000 to the internet.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?TallyConnection $connection): array
    {
        $local = app()->environment('local');

        return [
            'enabled' => (bool) $connection?->enabled,
            'transport' => $connection?->transport ?? 'direct',
            'protocol' => $connection?->protocol ?? 'http',
            'format' => $connection?->format ?? 'xml',
            'host' => $connection?->host ?? ($local ? '127.0.0.1' : ''),
            'port' => $connection?->port ?? ($local ? 9000 : null),
            'tally_company_name' => $connection?->tally_company_name ?? '',
            'timeout_seconds' => $connection?->timeout_seconds ?? 15,
            'auto_sync' => (bool) $connection?->auto_sync,
            'sync_approved_transactions' => $connection?->sync_approved_transactions ?? true,
            'dry_run' => $connection?->dry_run ?? true,
            'cost_centres_enabled' => (bool) $connection?->cost_centres_enabled,
            'last_checked_at' => $connection?->last_checked_at?->toIso8601String(),
            'last_connected_at' => $connection?->last_connected_at?->toIso8601String(),
            'last_sync_at' => $connection?->last_sync_at?->toIso8601String(),
            'last_status' => $connection?->last_status ?? 'offline',
            'last_status_message' => $connection?->last_status_message,
            'suggested_defaults' => $local && $connection === null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => $user->can('tally.manage'),
            'sync' => $user->can('tally.sync'),
            'retry' => $user->can('tally.retry'),
            'mapping' => $user->can('tally.mapping'),
        ];
    }
}
