<?php

namespace App\Notifications\Inventory;

use App\Notifications\BaseNotification;

/** Inventory events (low stock) shown in the notification inbox. */
class InventoryNotification extends BaseNotification
{
    public function __construct(
        int $companyId,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {
        $this->companyId = $companyId;
    }

    public function preferenceKey(): string
    {
        return $this->kind;
    }

    protected function payload(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
