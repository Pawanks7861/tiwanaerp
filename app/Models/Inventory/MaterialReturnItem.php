<?php

namespace App\Models\Inventory;

use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Procurement\GrnItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Return line; references the issue line (site to store) or GRN line (to vendor) it returns against.
 */
class MaterialReturnItem extends Model
{
    public const POSTING_COLUMNS = ['unit_cost', 'value', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $parent = fn (self $item) => MaterialReturn::query()->withoutGlobalScopes()->findOrFail($item->material_return_id);

        static::saving(function (self $item) use ($parent) {
            $return = $parent($item);
            $postingOnly = $item->exists && array_diff(array_keys($item->getDirty()), self::POSTING_COLUMNS) === [];

            if (! $return->isEditable() && ! $postingOnly) {
                throw $return->lockedException();
            }
        });

        static::deleting(fn (self $item) => $parent($item)->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<MaterialReturn, $this>
     */
    public function materialReturn(): BelongsTo
    {
        return $this->belongsTo(MaterialReturn::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<MaterialIssueItem, $this>
     */
    public function issueItem(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueItem::class, 'material_issue_item_id');
    }

    /**
     * @return BelongsTo<GrnItem, $this>
     */
    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class);
    }
}
