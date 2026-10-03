<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by / updated_by / deleted_by from the authenticated user.
 */
trait Blameable
{
    public static function bootBlameable(): void
    {
        static::creating(function ($model) {
            if ($userId = Auth::id()) {
                $model->created_by ??= $userId;
                $model->updated_by ??= $userId;
            }
        });

        static::updating(function ($model) {
            if ($userId = Auth::id()) {
                $model->updated_by = $userId;
            }
        });

        // Soft delete only writes deleted_at, so deleted_by is stamped right after (all soft-deletable
        // tables carry deleted_by by convention, see Columns::blame()).
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::softDeleted(function ($model) {
                if ($userId = Auth::id()) {
                    $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(['deleted_by' => $userId]);
                    $model->setAttribute('deleted_by', $userId);
                }
            });
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
