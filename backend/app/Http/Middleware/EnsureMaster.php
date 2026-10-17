<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricted area (companies, server status): masters only. Not a permission key on
 * purpose, so it can't be granted to anyone through the Permissões tab.
 */
class EnsureMaster
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->master) {
            return response()->json([
                'code' => 'NO_PERMISSION',
                'message' => 'Você não possui permissão para realizar esta ação.',
            ], 403);
        }

        return $next($request);
    }
}
