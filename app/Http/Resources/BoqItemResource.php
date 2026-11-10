<?php

namespace App\Http\Resources;

use App\Http\Controllers\Boq\BoqPresenter;
use App\Models\Boq\BoqItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoqItem
 */
class BoqItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return BoqPresenter::item($this->resource, (bool) $request->user()?->can('boq.view_costs'));
    }
}
