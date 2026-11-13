<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Http\Controllers\Controller;
use App\Models\Utilities\ElectricityTariff;
use App\Models\Utilities\UtilitySite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityTariffComparisonController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $filters = $request->validate([
            'left' => ['nullable', 'integer'],
            'right' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);

        $tariffOptions = ElectricityTariff::query()
            ->with('connection:id,connection_number')
            ->orderByDesc('effective_from')
            ->limit(300)
            ->get();

        $left = isset($filters['left']) ? $this->loadTariff((int) $filters['left']) : null;
        $right = isset($filters['right']) ? $this->loadTariff((int) $filters['right']) : null;
        $diff = ($left && $right) ? ElectricityPresenter::tariffDiff($left, $right, $withCosts) : [];

        return Inertia::render('Utilities/Electricity/TariffComparison', [
            'tariffs' => $tariffOptions->map(fn (ElectricityTariff $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'electricity_connection_id' => $t->electricity_connection_id,
                'connection' => $t->connection?->only(['id', 'connection_number']),
            ])->all(),
            'left' => $left ? ElectricityPresenter::tariff($left, $withCosts) : null,
            'right' => $right ? ElectricityPresenter::tariff($right, $withCosts) : null,
            'diff' => $diff,
            'filters' => $filters,
        ]);
    }

    private function loadTariff(int $id): ?ElectricityTariff
    {
        return ElectricityTariff::query()->with(['connection', 'components'])->find($id);
    }
}
