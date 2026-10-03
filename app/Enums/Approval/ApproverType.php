<?php

namespace App\Enums\Approval;

use App\Enums\Concerns\HasOptions;

enum ApproverType: string
{
    use HasOptions;

    /** Any active company member holding this company role. */
    case Role = 'role';

    /** One specific user. */
    case User = 'user';

    /** Members of the document's project holding this project role (e.g. that project's manager). */
    case ProjectRole = 'project_role';

    public function label(): string
    {
        return match ($this) {
            self::Role => 'Company Role',
            self::User => 'Specific User',
            self::ProjectRole => 'Project Role',
        };
    }
}
