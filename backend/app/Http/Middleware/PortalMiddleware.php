<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Service\PortalService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/portal/* (Portal do cliente): a customer's portal session, never a user's. The
 * request is scoped to the customer's company, and blocked while that company's license is
 * inactive, like the CRM. Read-only by design: the portal routes are all GETs (plus logout).
 */
class PortalMiddleware
{
    public const CUSTOMER = 'portal_customer';

    public function __construct(
        protected PortalService $portalService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $customer = $token ? $this->portalService->resolve($token) : null;

        if (!$customer) {
            return response()->json(['message' => 'Sessão expirada. Entre novamente.'], 401);
        }

        if (!$customer->company?->isActive()) {
            return response()->json(['code' => 'COMPANY_INACTIVE', 'message' => 'O portal está indisponível no momento.'], 403);
        }

        $request->attributes->set(self::CUSTOMER, $customer);

        return Company::as($customer->company_id, fn () => $next($request));
    }
}
