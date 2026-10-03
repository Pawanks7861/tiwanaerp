<?php

namespace App\Models\Labour;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash advanced to a labourer: a receivable recovered through payment batches, never a project
 * cost. recovered_amount is a cache recomputed by LabourAdvanceService from settled payment lines.
 */
class LabourAdvance extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'advance_date' => 'date',
            'amount' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
        ];
    }

    public function outstanding(): Decimal
    {
        return Decimal::of($this->amount)->minus($this->recovered_amount);
    }

    /**
     * @return BelongsTo<Labour, $this>
     */
    public function labour(): BelongsTo
    {
        return $this->belongsTo(Labour::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
