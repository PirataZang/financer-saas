<?php

namespace App\Service;

use App\Models\Company;
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
        // Before login there is no current company: look the user up across all of them.
        $user = User::withoutGlobalScope('company')->where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password) || !$user->active) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        // Only after the password matched, so it reveals nothing to someone guessing.
        if (!$user->master && !$user->company->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['O acesso da sua empresa está suspenso ou expirado. Fale com o suporte.'],
            ]);
        }

        $token = Str::random(64);
        $expireAt = now()->addDays(self::TOKEN_TTL_DAYS);

        // Only the hash is stored: a leaked users table doesn't hand out live sessions.
        $user->forceFill([
            'token' => hash('sha256', $token),
            'expire_at' => $expireAt,
        ])->save();

        return [
            'token' => $token,
            'expire_at' => $expireAt->toIso8601String(),
            'user' => $this->sessionUser($user),
        ];
    }

    /**
     * User payload for the SPA session, including the permission keys the frontend
     * uses for menus, route guards and v-can (the API still checks every request).
     */
    public function sessionUser(User $user): array
    {
        $company = Company::find(Company::current() ?? $user->company_id);

        return [
            ...$user->toArray(),
            'master' => $user->master,
            'permissions' => $user->permissionKeys(),
            'company' => $company->only(['id', 'name', 'trade_name', 'active']),
            // Masters switch between every company (company switcher), active or not.
            'companies' => $user->master
                ? Company::orderBy('name')->get()->map->only(['id', 'name', 'trade_name', 'active'])
                : [],
        ];
    }

    /**
     * Resolve an authenticated user from the given token.
     */
    public function resolveUserFromToken(string $token): ?User
    {
        $user = User::withoutGlobalScope('company')->where('token', hash('sha256', $token))->first();

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
