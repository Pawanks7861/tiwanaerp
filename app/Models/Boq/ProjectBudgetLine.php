<?php

namespace App\Models\Boq;

use App\Enums\CostHead;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cost_head', 'boq_item_id', 'description', 'amount'])]
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
            'amount' => 'decimal:2',
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
}
