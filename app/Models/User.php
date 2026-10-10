<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Blameable;
use App\Models\Core\Company;
use App\Models\Core\CompanyUser;
use App\Models\Core\DeviceToken;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'mobile', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, Blameable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** @var list<string> */
    protected array $auditExclude = [
        'last_login_at', 'last_seen_at', 'current_company_id',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Company, $this, CompanyUser>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
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
     * @return HasMany<DeviceToken, $this>
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    /**
     * @return HasMany<ProjectUser, $this>
     */
    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectUser::class);
    }

    /**
     * @return BelongsToMany<Project, $this, ProjectUser>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_users')
            ->using(ProjectUser::class)
            ->withPivot(['id', 'project_role', 'is_active'])
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Session authentication can hold a model loaded before these columns were selected.
     * Read the timestamp from the database when this instance does not have it yet.
     */
    public function twoFactorConfirmed(): bool
    {
        if (! array_key_exists('two_factor_confirmed_at', $this->attributes)) {
            $value = $this->newQuery()->whereKey($this->getKey())->value('two_factor_confirmed_at');
            $this->setAttribute('two_factor_confirmed_at', $value);
            $this->syncOriginalAttribute('two_factor_confirmed_at');
        }

        return $this->two_factor_confirmed_at !== null;
    }

    public function twoFactorPending(): bool
    {
        if ($this->twoFactorConfirmed()) {
            return false;
        }

        if (! array_key_exists('two_factor_secret', $this->attributes)) {
            return $this->newQuery()->whereKey($this->getKey())->whereNotNull('two_factor_secret')->exists();
        }

        return filled($this->two_factor_secret);
    }

    /**
     * Companies this user may work in: active memberships of active companies
     * (super admins may enter any active company).
     *
     * @return Builder<Company>
     */
    public function accessibleCompaniesQuery(): Builder
    {
        $query = Company::query()->where('is_active', true)->orderBy('name');

        if ($this->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('memberships', fn ($m) => $m->where('user_id', $this->id)->where('is_active', true));
    }

    public function canAccessCompany(Company|int $company): bool
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return $this->accessibleCompaniesQuery()->whereKey($companyId)->exists();
    }

    public function isProjectMember(Project|int $project): bool
    {
        $projectId = $project instanceof Project ? $project->id : $project;

        return $this->projectMemberships()
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->exists();
    }
}
