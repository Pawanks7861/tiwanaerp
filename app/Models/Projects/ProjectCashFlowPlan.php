<?php

namespace App\Models\Projects;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active', 'total_planned'])]
class ProjectCashFlowPlan extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'total_planned' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function months(): HasMany
    {
        return $this->hasMany(ProjectCashFlowPlanMonth::class)->orderBy('year_month');
    }
}
