<?php

namespace App\Http\Controllers\Api\V1\Scrap\Concerns;

use App\Models\Scrap\ScrapGeneration;

trait MapsScrapGeneration
{
    protected function detail(ScrapGeneration $generation): ScrapGeneration
    {
        return $generation->load([
            'items.scrapType.unit',
            'items.unit',
            'latestApprovalRequest',
        ])->loadCount('items');
    }
}
