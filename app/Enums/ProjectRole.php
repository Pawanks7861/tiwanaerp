<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A user's role *within one project* (project_users.project_role). Company-level permissions
 * come from Spatie roles; the project role scopes data access and resolves approvers such as
 * "the project manager of this project".
 */
enum ProjectRole: string
{
    use HasOptions;

    case Manager = 'manager';
    case Engineer = 'engineer';
    case Purchase = 'purchase';
    case Store = 'store';
    case Billing = 'billing';
    case Quality = 'quality';
    case Accounts = 'accounts';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Project Manager',
            self::Engineer => 'Site Engineer',
            self::Purchase => 'Purchase',
            self::Store => 'Store',
            self::Billing => 'Billing Engineer',
            self::Quality => 'Quality Engineer',
            self::Accounts => 'Accounts',
            self::Viewer => 'Viewer',
        };
    }
}
