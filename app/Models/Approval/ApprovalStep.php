<?php

namespace App\Models\Approval;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Models\Core\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['level', 'name', 'approver_type', 'role_id', 'user_id', 'project_role', 'mode', 'sla_hours'])]
class ApprovalStep extends Model
{
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'approver_type' => ApproverType::class,
            'mode' => ApprovalMode::class,
            'sla_hours' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'level' => $this->level,
            'name' => $this->name,
            'approver_type' => $this->approver_type->value,
            'role_id' => $this->role_id,
            'user_id' => $this->user_id,
            'project_role' => $this->project_role,
            'mode' => $this->mode->value,
        ];
    }
}
