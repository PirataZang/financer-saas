<?php

namespace App\Http\Controllers;

use App\Service\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService,
    ) {
    }

    /**
     * Authenticate user and return access token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $result = $this->authService->login(
            $validated['email'],
            $validated['password'],
        );

        return response()->json([
            'token' => $result['token'],
            'expire_at' => $result['expire_at'],
            'user' => $result['user'],
        ]);
    }

    /**
     * Current user with fresh permissions (called on app load and by the session poll).
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->authService->sessionUser($request->user()));
    }

    /**
     * Invalidate the current user's access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json([
            'message' => 'Logout realizado com sucesso.',
        ]);
    }
}
