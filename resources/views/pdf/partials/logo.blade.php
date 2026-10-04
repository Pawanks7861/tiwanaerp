@php
    $brandLogo = $logo ?? null;
    if ($brandLogo === null && isset($company) && $company instanceof \App\Models\Core\Company) {
        $brandLogo = app(\App\Services\Branding\CompanyBranding::class)->dataUri($company);
    }
@endphp
@if ($brandLogo)
    <img src="{{ $brandLogo }}" alt="" style="height: 32px; margin-bottom: 4px;">
@endif
