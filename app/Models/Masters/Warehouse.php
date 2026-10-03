<?php

namespace App\Models\Masters;

use App\Enums\WarehouseType;
use App\Models\Inventory\StockTransaction;
use App\Models\Procurement\Grn;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'site_id', 'code', 'name', 'type', 'address', 'is_active'])]
class Warehouse extends MasterModel
{
    protected array $searchable = ['name', 'code'];

    protected function casts(): array
    {
        return [
            'type' => WarehouseType::class,
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return Grn::query()->withTrashed()->where('warehouse_id', $this->id)->exists()
            || StockTransaction::query()->where('warehouse_id', $this->id)->exists();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
