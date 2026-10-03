<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reported consumption at site. Never touches the stock ledger or project cost: the material was
 * already issued (and costed) by a material issue.
 */
class SiteDiaryMaterial extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = SiteDiary::class;

    public const PARENT_KEY = 'site_diary_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }
}
