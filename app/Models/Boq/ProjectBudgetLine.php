<?php

namespace App\Models\Boq;

use App\Enums\Boq\BudgetLineSource;
use App\Enums\CostHead;
use App\Models\Masters\Material;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cost_head',
    'boq_item_id',
    'source_type',
    'source_key',
    'material_id',
    'material_code',
    'material_name',
    'unit',
    'quantity',
    'rate',
    'description',
    'amount',
    'sources',
])]
class ProjectBudgetLine extends Model
{
    protected static function booted(): void
    {
        $guard = function (self $line) {
            ProjectBudget::query()->withoutGlobalScopes()->find($line->project_budget_id)?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'cost_head' => CostHead::class,
            'source_type' => BudgetLineSource::class,
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'sources' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProjectBudget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(ProjectBudget::class, 'project_budget_id');
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function isBoqMaterial(): bool
    {
        return $this->source_type === BudgetLineSource::BoqMaterial;
    }
}
