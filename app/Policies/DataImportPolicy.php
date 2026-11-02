<?php

namespace App\Policies;

use App\Models\Imports\DataImport;
use App\Models\User;
use App\Support\Imports\ImportCatalog;

class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return ImportCatalog::availableFor($user) !== [];
    }

    public function view(User $user, DataImport $import): bool
    {
        if ((int) $import->company_id !== (int) app(\App\Support\Tenancy\CurrentCompany::class)->id()) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->can('admin.users.manage')) {
            return true;
        }

        return (int) $import->uploaded_by === (int) $user->id
            && ImportCatalog::get($import->module)->authorize($user, $import->project);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function downloadErrorReport(User $user, DataImport $import): bool
    {
        return $this->view($user, $import);
    }
}
