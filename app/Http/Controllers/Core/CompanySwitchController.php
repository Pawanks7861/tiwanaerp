<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $companyId = (int) $request->validate(['company_id' => ['required', 'integer']])['company_id'];
        $user = $request->user();

        $company = $user->accessibleCompaniesQuery()->whereKey($companyId)->first();
        if ($company === null) {
            throw ValidationException::withMessages(['company_id' => 'You do not have access to that company.']);
        }

        $request->session()->put(SetCurrentCompany::SESSION_KEY, $company->id);
        $user->forceFill(['current_company_id' => $company->id])->saveQuietly();

        return redirect()->route('dashboard')->with('success', "Switched to {$company->name}.");
    }
}
