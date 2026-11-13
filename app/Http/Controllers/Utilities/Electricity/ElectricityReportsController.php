<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Http\Controllers\Controller;
use App\Models\Utilities\UtilitySite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityReportsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $reports = [];
        if ($request->user()->can('utilities.electricity.export') || $request->user()->can('reports.view')) {
            $reports[] = [
                'key' => 'monthly-comparison',
                'title' => 'Monthly comparison',
                'description' => 'Month-on-month consumption and billing.',
                'url' => route('utilities.electricity.comparison'),
            ];
            $reports[] = [
                'key' => 'tariff-comparison',
                'title' => 'Tariff comparison',
                'description' => 'Compare component rates between tariff versions.',
                'url' => route('utilities.electricity.tariff-comparison'),
            ];
        }

        return Inertia::render('Utilities/Electricity/Reports/Index', [
            'groups' => $reports ? [[
                'key' => 'analytics',
                'label' => 'Analytics',
                'reports' => $reports,
            ]] : [],
            'reports' => [],
        ]);
    }
}
