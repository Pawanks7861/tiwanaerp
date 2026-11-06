<?php

namespace App\Policies\Weighbridge;

use App\Models\User;
use App\Models\Weighbridge\WeighbridgeReceipt;
use Illuminate\Support\Facades\Gate;

/**
 * A weighbridge slip follows the document it is attached to.
 */
class WeighbridgeReceiptPolicy
{
    public function view(User $user, WeighbridgeReceipt $receipt): bool
    {
        return $receipt->weighable !== null && Gate::forUser($user)->allows('view', $receipt->weighable);
    }

    public function update(User $user, WeighbridgeReceipt $receipt): bool
    {
        return $receipt->weighable !== null && Gate::forUser($user)->allows('update', $receipt->weighable);
    }
}
