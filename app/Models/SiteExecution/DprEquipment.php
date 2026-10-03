<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\Masters\EquipmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprEquipment extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = Dpr::class;

    public const PARENT_KEY = 'dpr_id';

    protected $table = 'dpr_equipment';

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
