<?php

namespace App\Service;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private const TOKEN_TTL_DAYS = 7;

    /**
     * Authenticate user and issue a new access token.
     *
     * @return array{token: string, expire_at: string, user: array}
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password) || !$user->active) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $token = Str::random(64);
        $expireAt = now()->addDays(self::TOKEN_TTL_DAYS);

        $user->forceFill([
            'token' => $token,
            'expire_at' => $expireAt,
        ])->save();

        return [
            'token' => $token,
            'expire_at' => $expireAt->toIso8601String(),
            'user' => $this->sessionUser($user->fresh()),
        ];
    }

    /**
     * User payload for the SPA session, including the permission keys the frontend
     * uses for menus, route guards and v-can (the API still checks every request).
     */
    public function sessionUser(User $user): array
    {
        return [
            ...$user->toArray(),
            'permissions' => $user->permissionKeys(),
        ];
    }

    /**
     * Resolve an authenticated user from the given token.
     */
    public function resolveUserFromToken(string $token): ?User
    {
        $user = User::where('token', $token)->first();

        if (!$user || !$user->active || !$user->expire_at || $user->expire_at->isPast()) {
            return null;
        }

        return $user;
    }

    /**
     * Invalidate the user's access token.
     */
    public function logout(User $user): void
    {
        $user->forceFill([
            'token' => null,
            'expire_at' => null,
        ])->save();
    }
}
