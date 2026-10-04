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

        $request->validate([
            'file' => ['required', 'file', 'max:'.CompanyBranding::MAX_KB],
        ], [
            'file.max' => 'The file must be 2 MB or smaller.',
            'file.uploaded' => 'The file is larger than the server limit of 2 MB.',
        ]);

        if ($kind === 'logo') {
            $this->branding->storeLogo($company, $request->file('file'));
        } else {
            $this->branding->storeFavicon($company, $request->file('file'));
        }

        return back()->with('success', $kind === 'logo' ? 'Company logo updated.' : 'Favicon updated.');
    }
}
