<?php

namespace App\Models\SiteExecution;

use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Engineer's daily record of a site: work done, labour, equipment, material used, issues and
 * photos. Approval only makes it available to the DPR; it never posts progress, stock or cost.
 */
#[Fillable([
    'site_id', 'diary_date', 'weather', 'temperature', 'work_location', 'work_performed', 'issues',
    'safety_incidents', 'remarks', 'latitude', 'longitude', 'captured_at',
])]
class SiteDiary extends Model
{
    use Auditable, BelongsToCompany, Blameable, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteDiaryStatus::class,
            'diary_date' => 'date',
            'temperature' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'captured_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This site diary is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'diary';
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
        return $this->belongsTo(Site::class)->withTrashed();
    }

    /**
     * @return HasMany<SiteDiaryWorkItem, $this>
     */
    public function workItems(): HasMany
    {
        return $this->hasMany(SiteDiaryWorkItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<SiteDiaryLabour, $this>
     */
    public function labours(): HasMany
    {
        return $this->hasMany(SiteDiaryLabour::class)->orderBy('id');
    }

    /**
     * @return HasMany<SiteDiaryEquipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(SiteDiaryEquipment::class)->orderBy('id');
    }

    /**
     * @return HasMany<SiteDiaryMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(SiteDiaryMaterial::class)->orderBy('id');
    }

    /**
     * @return HasMany<SiteDiaryPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(SiteDiaryPhoto::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
