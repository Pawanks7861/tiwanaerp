<?php

namespace App\Http\Controllers\Platform;

use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-level company management (super admin only).
 */
class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Company::class);

        return Inertia::render('Platform/Companies/Index', [
            'companies' => Company::query()->withCount(['memberships', 'projects'])->orderBy('name')->get()
                ->map(fn (Company $c) => [
                    ...$c->only(['id', 'name', 'code', 'gstin', 'city', 'is_active']),
                    'users_count' => $c->memberships_count,
                    'projects_count' => $c->projects_count,
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Company::class);

        return Inertia::render('Platform/Companies/Form', ['company' => null, 'states' => IndianState::options()]);
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $company = DB::transaction(function () use ($data) {
            $admin = User::query()->where('email', $data['admin_email'])->first();
            if ($admin === null) {
                if (blank($data['admin_password'] ?? null)) {
                    throw ValidationException::withMessages(['admin_password' => 'A password is required for a new admin user.']);
                }
                $admin = new User;
                $admin->fill(['name' => $data['admin_name'], 'email' => $data['admin_email'], 'password' => $data['admin_password']]);
                $admin->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
            }

            $companyData = collect($data)->except(['admin_name', 'admin_email', 'admin_password'])->all();

            return $this->companies->create($companyData + ['is_active' => true], $admin);
        });

        return redirect()->route('platform.companies.index')->with('success', "Company {$company->name} created and set up.");
    }

    public function edit(Company $company): Response
    {
        Gate::authorize('update', $company);

        return Inertia::render('Platform/Companies/Form', [
            'company' => $company->only([
                'id', 'name', 'legal_name', 'code', 'gstin', 'pan', 'state_code', 'address', 'city',
                'pincode', 'phone', 'email', 'is_active',
            ]),
            'states' => IndianState::options(),
        ]);
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $company->fill($request->validated())->save();

        return redirect()->route('platform.companies.index')->with('success', 'Company updated.');
    }
}
