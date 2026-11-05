<?php

namespace App\Models\Boq;

use App\Enums\Boq\MaterialRecipeStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class MaterialRecipeTemplate extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $recipe) {
            if ($recipe->getRawOriginal('status') !== MaterialRecipeStatus::Draft->value) {
                $changed = array_diff(array_keys($recipe->getDirty()), ['updated_by', 'updated_at', 'status', 'effective_from', 'effective_to']);
                if ($changed !== []) {
                    throw self::lockedException();
                }
            }
        });

        static::deleting(function (self $recipe) {
            if ($recipe->getRawOriginal('status') !== MaterialRecipeStatus::Draft->value) {
                throw self::lockedException();
            }
        });
    }

    public static function lockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'recipe' => 'Only draft recipe templates can be changed or deleted.',
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
            'status' => MaterialRecipeStatus::class,
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRecipeTemplateLine::class, 'recipe_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }
}
