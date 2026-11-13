<?php

namespace App\Models\Gate;

use App\Enums\Gate\GatePassItemType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Equipment\Equipment;
use App\Models\Masters\Material;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatePassItem extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'item_type' => GatePassItemType::class,
            'quantity' => 'decimal:4',
            'weight' => 'decimal:4',
            'rate' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<GatePass, $this>
     */
    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<ScrapType, $this>
     */
    public function scrapTypeMaster(): BelongsTo
    {
        return $this->belongsTo(ScrapType::class, 'scrap_type_id');
    }
}
