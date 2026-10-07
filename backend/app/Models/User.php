<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;

#[Fillable(['name', 'email', 'password', 'active'])]
#[Hidden(['password', 'remember_token', 'token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, Auditable;

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

    /**
     * Never log the password/token fields — Auditable logs fillable attributes
     * by default, and User's fillable includes password.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnly(['master'])
            ->logExcept(['password'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
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
