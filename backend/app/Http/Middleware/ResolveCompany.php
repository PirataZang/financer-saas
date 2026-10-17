<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the company every query of this request is scoped to (BelongsToCompany).
 * The company always comes from the authenticated user, never from the request,
 * except for a master: masters may pick any company (X-Company-Id), active or not;
 * an unknown id falls back to their own. Everyone else is blocked while their
 * company's license is inactive (no token, expired, or deleted).
 */
class ResolveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $company = $user->master && $request->hasHeader('X-Company-Id')
            ? Company::find((int) $request->header('X-Company-Id')) ?? $user->company
            : $user->company;

        if (!$user->master && !$company?->isActive()) {
            return response()->json([
                'code' => 'COMPANY_INACTIVE',
                'message' => 'O acesso da sua empresa está suspenso ou expirado. Fale com o suporte.',
            ], 403);
        }

        return Company::as($company->id, fn () => $next($request));
    }
}
