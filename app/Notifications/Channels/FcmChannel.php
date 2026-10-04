<?php

namespace App\Notifications\Channels;

use App\Notifications\BaseNotification;
use App\Services\Notifications\FcmClient;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification to every FCM token registered for the user.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof BaseNotification || ! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $payload = $notification->toArray($notifiable);
        $data = [
            'url' => (string) ($payload['url'] ?? ''),
            'kind' => (string) ($payload['kind'] ?? $notification->preferenceKey()),
            'company_id' => (string) ($notification->companyId ?? ''),
        ];

        $notifiable->deviceTokens()->pluck('token')->each(function (string $token) use ($payload, $data) {
            $this->fcm->send($token, [
                'title' => (string) ($payload['title'] ?? 'BUILDIFY360'),
                'body' => (string) ($payload['body'] ?? ''),
            ], $data);
        });
    }
}
