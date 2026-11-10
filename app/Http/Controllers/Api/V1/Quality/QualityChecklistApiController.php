<?php

namespace App\Http\Controllers\Api\V1\Quality;

use App\Http\Controllers\Controller;
use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class QualityChecklistApiController extends Controller
{
    public function show(QualityChecklist $checklist): JsonResponse
    {
        Gate::authorize('view', $checklist);

        $checklist->load(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        return response()->json([
            'data' => [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'discipline' => $checklist->discipline->value,
                'discipline_label' => $checklist->discipline->label(),
                'activity' => $checklist->activity,
                'item_count' => $checklist->items->count(),
                'items' => $checklist->items->map(fn (QualityChecklistItem $item) => [
                    'id' => $item->id,
                    'sequence' => $item->sort_order,
                    'sort_order' => $item->sort_order,
                    'title' => $item->checkpoint,
                    'checkpoint' => $item->checkpoint,
                    'response_type' => 'checkpoint_result',
                    'required' => true,
                    'options' => ['pass', 'fail', 'na'],
                    'unit' => null,
                    'acceptance_criteria' => $item->acceptance_criteria,
                    'remarks_rules' => ['optional' => true, 'max' => 500],
                ])->values(),
            ],
        ]);
    }
}
