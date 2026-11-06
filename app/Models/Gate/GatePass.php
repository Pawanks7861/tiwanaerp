<?php

namespace App\Models\Gate;

use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use App\Models\Weighbridge\WeighbridgeReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Validation\ValidationException;

class GatePass extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'direction' => GatePassDirection::class,
            'status' => GatePassStatus::class,
            'returnable' => 'boolean',
            'passed_at' => 'datetime',
            'expected_return_date' => 'date',
            'returned_at' => 'datetime',
            'approved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function assertEditable(): void
    {
        if (! $this->isEditable()) {
            throw ValidationException::withMessages(['gate_pass' => 'This gate pass can no longer be edited.']);
        }
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<GatePassItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GatePassItem::class)->orderBy('sort_order');
    }

    /**
     * @return MorphOne<WeighbridgeReceipt, $this>
     */
    public function weighbridgeReceipt(): MorphOne
    {
        return $this->morphOne(WeighbridgeReceipt::class, 'weighable');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
