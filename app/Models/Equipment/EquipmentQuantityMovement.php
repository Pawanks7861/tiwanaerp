<?php

namespace App\Models\Equipment;

use App\Enums\Equipment\EquipmentQuantityMovementType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentQuantityMovement extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'moved_at' => 'date',
            'movement_type' => EquipmentQuantityMovementType::class,
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
