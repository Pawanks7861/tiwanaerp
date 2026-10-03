<?php

namespace App\Policies;

use App\Models\Core\Attachment;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;

/**
 * Attachment access follows the parent record: view parent to download, update parent to add/remove.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->owns($attachment)
            && $attachment->attachable !== null
            && Gate::forUser($user)->allows('view', $attachment->attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->owns($attachment)
            && $attachment->attachable !== null
            && Gate::forUser($user)->allows('update', $attachment->attachable);
    }

    private function owns(Attachment $attachment): bool
    {
        return (int) $attachment->company_id === app(CurrentCompany::class)->id();
    }
}
