<?php

namespace App\Models\Labour;

use App\Enums\Labour\IdProofType;
use App\Models\Concerns\HasAttachments;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\MasterModel;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Labour register (architecture H.11). daily_wage / ot_rate_per_hour are the current rates; each
 * attendance row snapshots them, so later rate changes never alter marked or approved days.
 */
#[Fillable([
    'code', 'name', 'mobile', 'labour_trade_id', 'subcontractor_id', 'daily_wage', 'ot_rate_per_hour',
    'current_project_id', 'joining_date', 'id_proof_type', 'id_proof_no', 'is_active',
])]
class Labour extends MasterModel
{
    use HasAttachments;

    protected array $searchable = ['name', 'code', 'mobile'];

    protected static function booted(): void
    {
        static::creating(fn (self $labour) => $labour->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'daily_wage' => 'decimal:2',
            'ot_rate_per_hour' => 'decimal:4',
            'joining_date' => 'date',
            'id_proof_type' => IdProofType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LabourTrade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(LabourTrade::class, 'labour_trade_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    /**
     * @return HasMany<LabourAttendance, $this>
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(LabourAttendance::class);
    }

    /**
     * @return HasMany<LabourAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(LabourAdvance::class);
    }

    public function isInUse(): bool
    {
        return LabourAttendance::query()->where('labour_id', $this->id)->exists()
            || LabourPaymentLine::query()->where('labour_id', $this->id)->exists()
            || LabourAdvance::query()->where('labour_id', $this->id)->exists()
            || EquipmentAssignment::query()->where('operator_labour_id', $this->id)->exists();
    }

    /** ID number for users who may not edit the register: last 4 characters only. */
    public function maskedIdProofNo(): ?string
    {
        if ($this->id_proof_no === null || $this->id_proof_no === '') {
            return null;
        }

        $visible = mb_substr($this->id_proof_no, -4);

        return str_repeat('•', max(mb_strlen($this->id_proof_no) - 4, 0)).$visible;
    }
}
