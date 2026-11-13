<?php

namespace App\Notifications\Utilities;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Company-scoped electricity operational alerts (reading pending, bill review, demand/PF/due).
 * Phase 1: database channel only; finance payment linkage is future.
 */
class ElectricityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly string $targetType,
        public readonly ?int $targetId,
        public readonly array $payload = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'utilities.electricity',
            'event' => $this->event,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'payload' => $this->payload,
        ];
    }
}
