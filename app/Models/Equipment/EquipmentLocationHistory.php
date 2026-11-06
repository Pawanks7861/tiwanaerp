<?php

namespace App\Models\Equipment;

use App\Enums\Equipment\EquipmentLocationType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentLocationHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'equipment_location_history';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'from_location_type' => EquipmentLocationType::class,
            'to_location_type' => EquipmentLocationType::class,
            'changed_at' => 'datetime',
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
    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
