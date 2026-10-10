<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Services\Files\Cad\CadPreviewManager;
use App\Services\Files\ShareCadPreview;
use App\Support\Math\Decimal;
use App\Support\Procurement\ProcurementSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profile of the CURRENT company (company admins).
 */
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly CadPreviewManager $cad,
        private readonly ShareCadPreview $sharecad,
    ) {}

    public function edit(Request $request): Response
    {
        $company = $this->tenancy->require();
        Gate::authorize('viewSettings', $company);

        return Inertia::render('Admin/Company/Settings', [
            'company' => $company->only([
                'id', 'name', 'legal_name', 'code', 'gstin', 'pan', 'state_code', 'address', 'city',
                'pincode', 'phone', 'email', 'currency', 'fy_start_month', 'timezone',
            ]),
            'states' => IndianState::options(),
            'procurement' => [
                'grn_tolerance_percent' => (string) ($company->setting(ProcurementSettings::GRN_TOLERANCE_KEY) ?? config('procurement.grn_tolerance_percent', '0')),
            ],
            'can' => ['update' => $request->user()->can('manageSettings', $company)],
            'cadPreview' => $this->cad->health(),
            'dwgPreview' => [
                'provider' => $this->sharecad->provider(),
                'acknowledged' => $this->sharecad->acknowledged(),
                'server_enabled' => $this->sharecad->deploymentEnabled(),
            ],
            'branding' => [
                'logo_url' => $company->logo_path ? route('company.branding.show', ['kind' => 'logo', 'v' => $company->updated_at?->getTimestamp()]) : null,
                'favicon_url' => $company->favicon_path ? route('company.branding.show', ['kind' => 'favicon', 'v' => $company->updated_at?->getTimestamp()]) : null,
                'max_kb' => (int) config('uploads.logo_max_kb'),
                'logo_max_kb' => (int) config('uploads.logo_max_kb'),
                'favicon_max_kb' => (int) config('uploads.favicon_max_kb'),
            ],
        ]);
    }

    public function updateProcurement(Request $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $data = $request->validate([
            'grn_tolerance_percent' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ], [], ['grn_tolerance_percent' => 'GRN over-receipt tolerance']);

        $company->settings()->updateOrCreate(
            ['key' => ProcurementSettings::GRN_TOLERANCE_KEY],
            ['value' => Decimal::of((string) $data['grn_tolerance_percent'])->round(Decimal::PERCENT_SCALE)->toString()],
        );

        return back()->with('success', 'Procurement settings updated.');
    }

    public function updateDwgPreview(Request $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $data = $request->validate([
            'provider' => ['required', 'in:local,sharecad,auto'],
            'sharecad_acknowledged' => ['nullable', 'boolean'],
        ]);

        if (in_array($data['provider'], ['sharecad', 'auto'], true)) {
            if (! $this->sharecad->deploymentEnabled()) {
                throw ValidationException::withMessages([
                    'provider' => 'ShareCAD is not enabled on this server.',
                ]);
            }

            if (! $request->boolean('sharecad_acknowledged')) {
                throw ValidationException::withMessages([
                    'sharecad_acknowledged' => 'Confirm that DWG files may be temporarily shared with an external CAD viewing service.',
                ]);
            }
        }

        $company->settings()->updateOrCreate(
            ['key' => ShareCadPreview::PROVIDER_KEY],
            ['value' => $data['provider']],
        );
        $company->settings()->updateOrCreate(
            ['key' => ShareCadPreview::ACK_KEY],
            ['value' => $request->boolean('sharecad_acknowledged')],
        );

        return back()->with('success', 'File preview settings updated.');
    }

    public function update(CompanyRequest $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        $company->fill($request->safe()->except(['code', 'is_active']))->save();

        return back()->with('success', 'Company profile updated.');
    }
}
