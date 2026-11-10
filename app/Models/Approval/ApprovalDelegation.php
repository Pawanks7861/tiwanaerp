<?php

namespace App\Models\Approval;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['delegator_id', 'delegate_id', 'starts_at', 'ends_at', 'document_types', 'reason', 'is_active', 'created_by'])]
class ApprovalDelegation extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'document_types' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function coversDocumentType(string $documentType): bool
    {
        $types = $this->document_types;

        return $types === null || $types === [] || in_array($documentType, $types, true);
    }

    public function isInRange(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && $this->starts_at <= $at
            && $this->ends_at >= $at;
    }
}
