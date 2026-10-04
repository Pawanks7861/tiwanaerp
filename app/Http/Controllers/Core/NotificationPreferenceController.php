<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\NotificationPreference;
use App\Support\Notifications\NotificationTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-user notification preferences. In-app and push can be turned on per type. Email and
 * WhatsApp stay listed until those channels exist.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $saved = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->keyBy('notification_type');

        $groups = [];
        foreach (NotificationTypes::all() as $type) {
            $channels = $saved->get($type['key'])?->channels;
            $groups[$type['group']][] = [
                'key' => $type['key'],
                'label' => $type['label'],
                'database' => $channels === null || in_array('database', $channels, true),
                'push' => is_array($channels) && in_array('push', $channels, true),
            ];
        }

        return Inertia::render('Notifications/Preferences', [
            'groups' => collect($groups)->map(fn (array $types, string $group) => [
                'group' => $group,
                'types' => $types,
            ])->values()->all(),
            'futureChannels' => [
                ['key' => 'mail', 'label' => 'Email'],
                ['key' => 'whatsapp', 'label' => 'WhatsApp'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', 'distinct', Rule::in(NotificationTypes::keys())],
            'preferences.*.database' => ['required', 'boolean'],
            'preferences.*.push' => ['sometimes', 'boolean'],
        ]);

        foreach ($data['preferences'] as $row) {
            $channels = [];
            if ($row['database']) {
                $channels[] = 'database';
            }
            if (! empty($row['push'])) {
                $channels[] = 'push';
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $request->user()->id, 'notification_type' => $row['type']],
                ['channels' => $channels],
            );
        }

        return back()->with('success', 'Notification preferences saved.');
    }
}
