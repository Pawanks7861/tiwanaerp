<?php

namespace App\Models\Boq;

use App\Enums\Boq\MaterialRecipeStatus;
use App\Enums\Boq\SteelUnitWeightMethod;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SteelUnitWeightProfile extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $profile) {
            if ($profile->getRawOriginal('status') !== MaterialRecipeStatus::Draft->value) {
                $changed = array_diff(array_keys($profile->getDirty()), ['updated_by', 'updated_at', 'status']);
                if ($changed !== []) {
                    throw self::lockedException();
                }
            }
        });

        static::deleting(function (self $profile) {
            if ($profile->getRawOriginal('status') !== MaterialRecipeStatus::Draft->value) {
                throw self::lockedException();
            }
        });
    }

    public static function lockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'steel_profile' => 'Only draft steel unit-weight profiles can be changed or deleted.',
        ]);
    }

    public function assertDraft(): void
    {
        if ($this->status !== MaterialRecipeStatus::Draft) {
            throw self::lockedException();
        }
    }

    protected function casts(): array
    {
        return [
            'method' => SteelUnitWeightMethod::class,
            'status' => MaterialRecipeStatus::class,
            'version' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SteelUnitWeightProfileLine::class, 'profile_id')
            ->orderBy('diameter_mm');
    }
}
