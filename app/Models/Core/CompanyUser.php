<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Blameable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Company membership. Deactivating a membership revokes access to that company only.
 */
class CompanyUser extends Pivot
{
    use Auditable, Blameable;

    protected $table = 'company_user';

    public $incrementing = true;

    protected $fillable = ['company_id', 'user_id', 'user_type', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Linked vendor / subcontractor / client for external portal users (later phases).
     *
     * @return MorphTo<Model, $this>
     */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }
}
