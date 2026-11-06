<?php

namespace App\Http\Middleware;

use App\Support\Procurement\ProcurementSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFQ screens stay in the application, but are unreachable while procurement.rfq_enabled is off.
 */
class EnsureProcurementRfqEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ProcurementSettings::rfqEnabled(), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
