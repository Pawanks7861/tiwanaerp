<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use App\Models\User;
use Database\Factories\Core\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'legal_name', 'code', 'gstin', 'pan', 'state_code', 'address', 'city', 'pincode',
    'phone', 'email', 'currency', 'fy_start_month', 'timezone', 'is_active',
])]
#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Auditable, Blameable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fy_start_month' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, CompanyUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(CompanyUser::class)
            ->withPivot(['id', 'user_type', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<CompanySetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(CompanySetting::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()->where('key', $key)->value('value') ?? $default;
    }
}
