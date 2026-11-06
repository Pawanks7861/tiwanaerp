<?php

namespace App\Policies;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * BOQ line attachments and related actions follow the parent BOQ's view/update rules.
 * Company scoping is enforced by resolving the BOQ through the tenant scope.
 */
class BoqItemPolicy
{
    public function view(User $user, BoqItem $item): bool
    {
        $boq = Boq::query()->find($item->boq_id);

        return $boq !== null && Gate::forUser($user)->allows('view', $boq);
    }

    public function update(User $user, BoqItem $item): bool
    {
        $boq = Boq::query()->find($item->boq_id);

        return $boq !== null && Gate::forUser($user)->allows('update', $boq);
    }
}
