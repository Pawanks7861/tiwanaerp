<?php

namespace App\Notifications;

/**
 * Inbox notification with a fixed preference key (kind), title, body and source link. Used by
 * the Phase 9 events: tasks, client invoices, receipts, NCRs and report exports.
 */
class GeneralNotification extends BaseNotification
{
    /**
     * @param  array<string, mixed>  $context  extra payload (e.g. project_id) for the inbox
     */
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
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url] + $this->context;
    }
}
