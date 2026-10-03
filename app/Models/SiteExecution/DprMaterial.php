<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reported consumption, informational only: no stock or cost posting.
 */
class DprMaterial extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = Dpr::class;

    public const PARENT_KEY = 'dpr_id';

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
