<?php

namespace App\Models\Gate;

use App\Enums\Gate\GatePassReturnStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatePassReturn extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => GatePassReturnStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GatePassReturnItem::class);
    }
}
