<?php

namespace App\Models\Boq;

use App\Enums\Boq\MeasurementSheetStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoqMeasurementSheet extends Model
{
    use BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => MeasurementSheetStatus::class,
            'total_quantity' => 'decimal:4',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function drawingRevision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BoqMeasurementLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
