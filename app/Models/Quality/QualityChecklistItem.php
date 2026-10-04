<?php

namespace App\Models\Quality;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityChecklistItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QualityChecklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(QualityChecklist::class, 'quality_checklist_id');
    }
}
