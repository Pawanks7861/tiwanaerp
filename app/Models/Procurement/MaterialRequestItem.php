<?php

namespace App\Models\Procurement;

use App\Models\Boq\BoqItem;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Material request line. ordered_qty / received_qty are caches recomputed by
 * ProcurementQuantityService from approved purchase orders and GRNs.
 */
#[Fillable(['material_id', 'boq_item_id', 'task_id', 'unit_id', 'quantity', 'remarks', 'sort_order'])]
class MaterialRequestItem extends Model
{
    public const CACHE_COLUMNS = ['ordered_qty', 'received_qty', 'updated_at'];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->exists && array_diff(array_keys($item->getDirty()), self::CACHE_COLUMNS) === []) {
                return;
            }
            $item->parentRequest()->assertEditable();
        });

        static::deleting(fn (self $item) => $item->parentRequest()->assertEditable());
    }

    private function parentRequest(): MaterialRequest
    {
        return $this->relationLoaded('materialRequest') && $this->materialRequest?->id === $this->material_request_id
            ? $this->materialRequest
            : MaterialRequest::query()->withoutGlobalScopes()->findOrFail($this->material_request_id);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'ordered_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MaterialRequest, $this>
     */
    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
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
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return HasMany<RfqItem, $this>
     */
    public function rfqItems(): HasMany
    {
        return $this->hasMany(RfqItem::class);
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
