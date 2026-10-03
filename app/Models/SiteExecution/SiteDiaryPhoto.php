<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site photo on the private disk. Served only through the authorised photo controller.
 */
class SiteDiaryPhoto extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = SiteDiary::class;

    public const PARENT_KEY = 'site_diary_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'taken_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SiteDiary, $this>
     */
    public function diary(): BelongsTo
    {
        return $this->belongsTo(SiteDiary::class, 'site_diary_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
