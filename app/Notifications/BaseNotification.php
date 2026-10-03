<?php

namespace App\Notifications;

use App\Models\Core\NotificationPreference;
use App\Notifications\Channels\CompanyDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for all BUILDIFY360 notifications. Delivery channels come from the user's preferences
 * (database by default); mail / WhatsApp / push channels plug in here later.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public ?int $companyId = null;

    /** Stable key used for user preferences, e.g. "approval.requested". */
    abstract public function preferenceKey(): string;

    /**
     * @return array{title: string, body: string, url: string|null, kind: string}
     */
    abstract protected function payload(object $notifiable): array;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $configured = NotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('notification_type', $this->preferenceKey())
            ->value('channels');

        $channels = is_array($configured) ? $configured : ['database'];

        return array_values(array_map(
            fn (string $channel) => $channel === 'database' ? CompanyDatabaseChannel::class : $channel,
            array_intersect($channels, ['database']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload($notifiable);
    }
}
