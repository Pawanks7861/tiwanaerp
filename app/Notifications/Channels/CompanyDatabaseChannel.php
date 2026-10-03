<?php

namespace App\Notifications\Channels;

use App\Notifications\BaseNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Database channel that also stores the company the notification belongs to, so the bell
 * only shows notifications for the company the user is currently working in.
 */
class CompanyDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return array_merge(parent::buildPayload($notifiable, $notification), [
            'company_id' => $notification instanceof BaseNotification ? $notification->companyId : null,
        ]);
    }
}
