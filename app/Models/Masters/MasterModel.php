<?php

namespace App\Models\Masters;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base for company-owned master data: tenant scoped, blamed, audited, soft deleted.
 * Masters that are referenced elsewhere are deactivated rather than deleted (see isInUse()).
 */
abstract class MasterModel extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    /**
     * Columns matched by the list search box.
     *
     * @var list<string>
     */
    protected array $searchable = ['name'];

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            foreach ($this->searchable as $column) {
                $q->orWhere($column, 'like', '%'.addcslashes($term, '%_\\').'%');
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Whether other records reference this master. Referenced masters cannot be deleted.
     */
    public function isInUse(): bool
    {
        return false;
    }
}
