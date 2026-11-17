<?php

namespace App\Models\Projects;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['year_month', 'planned_amount'])]
class ProjectCashFlowPlanMonth extends Model
{
    protected function casts(): array
    {
        return [
            'planned_amount' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProjectCashFlowPlan::class, 'project_cash_flow_plan_id');
    }
}
