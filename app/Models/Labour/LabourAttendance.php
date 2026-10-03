<?php

namespace App\Models\Labour;

use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One labourer, one day. Wage and OT amounts are server-calculated snapshots
 * (LabourAttendanceService); once approved the row is frozen except for the payment link and
 * the controlled un-approval.
 */
class LabourAttendance extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    /** Columns that may change after approval (payment linking, un-approval). */
    public const LIFECYCLE_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'labour_payment_id', 'updated_by', 'updated_at'];

    protected $table = 'labour_attendance';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());

        static::updating(function (self $row) {
            if ($row->getOriginal('approval_status') !== AttendanceApproval::Approved) {
                return;
            }
            if (array_diff(array_keys($row->getDirty()), self::LIFECYCLE_COLUMNS) !== []) {
                throw ValidationException::withMessages(['attendance' => 'Approved attendance cannot be changed.']);
            }
        });

        static::deleting(function (self $row) {
            if ($row->approval_status === AttendanceApproval::Approved || $row->labour_payment_id) {
                throw ValidationException::withMessages(['attendance' => 'Approved attendance cannot be deleted.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceStatus::class,
            'approval_status' => AttendanceApproval::class,
            'working_hours' => 'decimal:2',
            'ot_hours' => 'decimal:2',
            'daily_wage' => 'decimal:2',
            'ot_rate' => 'decimal:4',
            'wage_amount' => 'decimal:2',
            'ot_amount' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'approved_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->approval_status === AttendanceApproval::Approved;
    }

    /** Wage + OT: the labour cost of the day. */
    public function totalAmount(): string
    {
        return Decimal::of($this->wage_amount)->plus($this->ot_amount)->toMoney();
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
     * @return BelongsTo<Labour, $this>
     */
    public function labour(): BelongsTo
    {
        return $this->belongsTo(Labour::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return BelongsTo<LabourPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(LabourPayment::class, 'labour_payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
