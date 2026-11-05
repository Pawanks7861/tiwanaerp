<?php

namespace App\Models\Boq;

use App\Enums\Boq\BbsStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BoqBbs extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $table = 'boq_bbs';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(function (self $bbs) {
            if ($bbs->status === BbsStatus::Approved) {
                throw ValidationException::withMessages(['bbs' => 'Approved BBS cannot be deleted.']);
            }
            $boq = Boq::query()->withoutGlobalScopes()->find($bbs->boq_id);
            $boq?->assertEditable();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => BbsStatus::class,
            'wastage_percent' => 'decimal:4',
            'total_theoretical_weight' => 'decimal:4',
            'wastage_weight' => 'decimal:4',
            'required_weight' => 'decimal:4',
            'submitted_at' => 'datetime',
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
        return $this->belongsTo(DrawingRevision::class, 'drawing_revision_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BoqBbsLine::class, 'bbs_id')->orderBy('sequence')->orderBy('id');
    }

    public function steelMaps(): HasMany
    {
        return $this->hasMany(BoqBbsSteelMap::class, 'bbs_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
