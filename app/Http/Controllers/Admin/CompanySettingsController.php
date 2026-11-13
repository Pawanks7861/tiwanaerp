<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Services\Files\Cad\CadPreviewManager;
use App\Support\Math\Decimal;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Crm\DefaultClientResolver;
use App\Support\Pdf\DocumentPdfSettings;
use App\Support\Procurement\ProcurementSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            'boq' => [
                'advanced_calculations_enabled' => BoqCompanySettings::enabled($company),
            ],
            'pdf' => DocumentPdfSettings::for($company),
            'crm' => [
                'default_client' => app(DefaultClientResolver::class)->payload($company),
            ],
            'can' => ['update' => $request->user()->can('manageSettings', $company)],
            'cadPreview' => $this->cad->health(),
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

    public function updateBoq(Request $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $data = $request->validate([
            'advanced_calculations_enabled' => ['required', 'boolean'],
        ]);

        BoqCompanySettings::set($company, (bool) $data['advanced_calculations_enabled']);

        return back()->with('success', 'BOQ settings updated.');
    }

    public function ensureDefaultClient(): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $client = app(DefaultClientResolver::class)->ensure($company);

        return back()->with('success', "Default client set to {$client->company_name}.");
    }

    public function updatePdf(Request $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $data = $request->validate([
            'signatory_name' => ['nullable', 'string', 'max:120'],
            'signatory_designation' => ['nullable', 'string', 'max:120'],
            'former_name' => ['nullable', 'string', 'max:200'],
            'cin' => ['nullable', 'string', 'max:40'],
            'computer_generated' => ['required', 'boolean'],
        ]);

        $map = [
            DocumentPdfSettings::SIGNATORY_NAME => $data['signatory_name'] ?? null,
            DocumentPdfSettings::SIGNATORY_DESIGNATION => $data['signatory_designation'] ?? null,
            DocumentPdfSettings::FORMER_NAME => $data['former_name'] ?? null,
            DocumentPdfSettings::CIN => $data['cin'] ?? null,
            DocumentPdfSettings::COMPUTER_GENERATED => $data['computer_generated'] ? '1' : '0',
        ];

        foreach ($map as $key => $value) {
            if ($value === null || $value === '') {
                $company->settings()->where('key', $key)->delete();
            } else {
                $company->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        return back()->with('success', 'PDF document settings updated.');
    }

    public function update(CompanyRequest $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        $company->fill($request->safe()->except(['code', 'is_active']))->save();

        return back()->with('success', 'Company profile updated.');
    }
}
