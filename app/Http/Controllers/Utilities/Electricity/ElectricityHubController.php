<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Http\Controllers\Controller;
use App\Models\Utilities\UtilitySite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityHubController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UtilitySite::class);

        return Inertia::render('Utilities/Electricity/Hub');
    }
}
