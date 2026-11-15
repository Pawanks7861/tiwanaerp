<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Controller;
use App\Models\Boq\Boq;
use App\Models\Projects\Project;
use App\Services\Boq\BoqCommercialRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BoqCommercialRateController extends Controller
{
    public function __construct(private readonly BoqCommercialRateService $rates) {}

    public function update(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('editCommercialRates', $boq);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.client_rate' => ['required', 'numeric', 'min:0'],
            'rows.*.margin_percent' => ['nullable', 'numeric'],
        ]);

        $this->rates->updateRates($boq, $data['rows'], $data['reason'], $request->user());

        return back()->with('success', 'Commercial rates updated.');
    }
}
