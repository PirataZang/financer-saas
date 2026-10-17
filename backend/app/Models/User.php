<?php

namespace App\Models;

use App\Attributes\ProtectedColumns;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

/**
 * Same contracts/traits as Laravel's Foundation\Auth\User, on top of the app's base Model.
 * token (session hash) and master never leave or enter through the API; master is also
 * locked: only the database changes it (/auth/me states it explicitly, for the user themselves).
 */
#[Fillable(['name', 'email', 'password', 'active'])]
#[Hidden(['password', 'remember_token'])]
#[ProtectedColumns(['token'], locked: ['master'])]
class User extends Model implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract
{
    use Authenticatable, Authorizable, CanResetPassword, MustVerifyEmail;
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, Auditable, BelongsToCompany;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'expire_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'master' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * Single authorization rule shared by the API middleware and the frontend
     * (which receives the same keys from /auth/me): master bypasses, otherwise exact key match.
     */
    public function hasPermission(string $key): bool
    {
        return $this->master || in_array($key, $this->permissionKeys(), true);
    }

    /**
     * Permission keys granted to this user. A master gets every key that exists — no
     * exceptions, so even code reading the list directly treats master as all-access.
     * Non-master keys are cached until a grant/revoke/sync or a rename/delete invalidates them.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        if ($this->master) {
            return Permission::orderBy('key')->pluck('key')->all();
        }

        return Cache::rememberForever(
            self::permissionCacheKey($this->id),
            fn () => $this->permissions()->orderBy('key')->pluck('key')->all(),
        );
    }

    public function givePermission(string ...$keys): void
    {
        $this->permissions()->syncWithoutDetaching(Permission::whereIn('key', $keys)->pluck('id'));
        $this->forgetPermissionCache();
    }

    public function revokePermission(string ...$keys): void
    {
        $this->permissions()->detach(Permission::whereIn('key', $keys)->pluck('id'));
        $this->forgetPermissionCache();
    }

    /**
     * @param  list<string>  $keys
     */
    public function syncPermissions(array $keys): void
    {
        $this->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        $this->forgetPermissionCache();
    }

    public function forgetPermissionCache(): void
    {
        Cache::forget(self::permissionCacheKey($this->id));
    }

    public static function permissionCacheKey(int $userId): string
    {
        return "user:{$userId}:permissions";
    }
}
