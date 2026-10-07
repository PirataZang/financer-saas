<?php

namespace App\Http\Middleware;

use App\Service\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    public function __construct(
        protected AuthService $authService,
    ) {
    }

    /**
     * Validate bearer token and ensure it has not expired.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Token de autenticação não informado.',
            ], 401);
        }

        $user = $this->authService->resolveUserFromToken($token);

        if (!$user) {
            return response()->json([
                'message' => 'Token inválido ou expirado.',
            ], 401);
        }

        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $next($request);
    }
}
