<?php

namespace App\Models\Integrations;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TallyCostCentreMapping extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
