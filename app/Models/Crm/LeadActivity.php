<?php

namespace App\Models\Crm;

use App\Enums\Crm\LeadActivityType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => LeadActivityType::class,
            'activity_at' => 'datetime',
            'next_follow_up' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
