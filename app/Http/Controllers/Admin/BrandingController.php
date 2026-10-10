<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Branding\CompanyBranding;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo and favicon for the active company. Changing them needs the company-settings permission.
 * Reading the image needs only an authenticated session in that company, so the shell can show it.
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly CompanyBranding $branding,
    ) {}

    public function show(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['logo', 'favicon'], true), 404);

        return $this->branding->response($this->tenancy->require(), $kind);
    }

    public function storeLogo(Request $request): RedirectResponse
    {
        return $this->store($request, 'logo');
    }

    public function storeFavicon(Request $request): RedirectResponse
    {
        return $this->store($request, 'favicon');
    }

    public function destroyLogo(): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);
        $this->branding->removeLogo($company);

        return back()->with('success', 'Company logo removed.');
    }

    public function destroyFavicon(): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);
        $this->branding->removeFavicon($company);

        return back()->with('success', 'Favicon removed.');
    }

    private function store(Request $request, string $kind): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $maxKb = $kind === 'logo' ? (int) config('uploads.logo_max_kb') : (int) config('uploads.favicon_max_kb');
        $label = max(1, (int) round($maxKb / 1024));
        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
        ], [
            'file.max' => "The file must be {$label} MB or smaller.",
            'file.uploaded' => "The file is larger than the server limit of {$label} MB.",
        ]);

        if ($kind === 'logo') {
            $this->branding->storeLogo($company, $request->file('file'));
        } else {
            $this->branding->storeFavicon($company, $request->file('file'));
        }

        return back()->with('success', $kind === 'logo' ? 'Company logo updated.' : 'Favicon updated.');
    }
}
