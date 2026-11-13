<?php

namespace App\Http\Controllers\Api\V1\Scrap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Scrap\ScrapPresenter;
use App\Http\Resources\ScrapCategoryResource;
use App\Http\Resources\ScrapTypeResource;
use App\Models\Masters\ScrapCategory;
use App\Models\Masters\ScrapType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
class ScrapLookupController extends Controller
{
    public function categories(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('scrap.view'), 403);

        return ScrapCategoryResource::collection(
            ScrapCategory::query()->active()->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function types(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('scrap.view'), 403);

        $categoryId = $request->validate(['category_id' => ['nullable', 'integer']])['category_id'] ?? null;

        $rows = ScrapType::query()
            ->active()
            ->with(['unit:id,symbol', 'category:id,name,code'])
            ->when($categoryId, fn ($q) => $q->where('scrap_category_id', $categoryId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ScrapTypeResource::collection($rows);
    }

    public function typeOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->can('scrap.view'), 403);

        return response()->json(['data' => ScrapPresenter::typeOptions()]);
    }
}
