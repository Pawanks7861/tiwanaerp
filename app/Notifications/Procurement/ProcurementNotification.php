<?php

namespace App\Notifications\Procurement;

use App\Notifications\BaseNotification;

/** Procurement events (MR submitted, PO approved, GRN approved) shown in the notification inbox. */
class ProcurementNotification extends BaseNotification
{
    public function __construct(
        int $companyId,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly array $context = [],
    ) {
        $this->companyId = $companyId;
    }

    public function preferenceKey(): string
    {
        return $this->kind;
    }

    protected function payload(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url] + (isset($this->context) ? $this->context : []);
    }
}
