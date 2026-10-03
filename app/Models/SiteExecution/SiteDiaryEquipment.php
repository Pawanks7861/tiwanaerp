<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\Masters\EquipmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equipment usage hours. equipment_id is reserved for the Phase 6 equipment register; until then
 * the line names an equipment type and/or a description. No cost is posted.
 */
class SiteDiaryEquipment extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = SiteDiary::class;

    public const PARENT_KEY = 'site_diary_id';

    protected $table = 'site_diary_equipment';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['working_hours' => 'decimal:2', 'idle_hours' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<EquipmentType, $this>
     */
    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class)->withTrashed();
    }
}
