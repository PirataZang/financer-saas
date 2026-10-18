<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function login(array $permissions = [], bool $master = false): array
    {
        $factory = $master ? User::factory()->master() : User::factory();
        $user = $factory->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->json('token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_user_with_permission_is_allowed(): void
    {
        [, $headers] = $this->login(['users.view', 'dashboard.view']);

        $this->getJson('/api/users', $headers)->assertOk();
        $this->getJson('/api/dashboard/summary', $headers)->assertOk();
    }

    public function test_user_without_permission_is_denied_through_the_api(): void
    {
        [$user, $headers] = $this->login(['users.view']);
        $other = User::factory()->create();

        $this->getJson('/api/dashboard/summary', $headers)->assertForbidden()->assertJson(['code' => 'NO_PERMISSION']);
        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@x.com', 'password' => 'secret123'], $headers)->assertForbidden();
        $this->putJson("/api/users/{$other->id}", ['name' => 'Hacked'], $headers)->assertForbidden();
        $this->deleteJson("/api/users/{$other->id}", [], $headers)->assertForbidden();
        $this->putJson("/api/users/{$user->id}/permissions", ['permissions' => ['users.delete']], $headers)->assertForbidden();
        $this->getJson('/api/permissions', $headers)->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => $other->name]);
    }

    public function test_master_bypasses_every_permission(): void
    {
        [, $headers] = $this->login(master: true);
        $other = User::factory()->create();

        $this->getJson('/api/dashboard/summary', $headers)->assertOk();
        $this->getJson('/api/permissions', $headers)->assertOk();
        $this->deleteJson("/api/users/{$other->id}", [], $headers)->assertOk();
    }

    public function test_login_and_me_return_permission_keys(): void
    {
        [$user, $headers] = $this->login(['users.view']);

        $this->getJson('/api/auth/me', $headers)
            ->assertOk()
            ->assertJson(['id' => $user->id, 'master' => false, 'permissions' => ['users.view']]);
    }

    public function test_revoked_permission_takes_effect_immediately(): void
    {
        [$user, $headers] = $this->login(['users.view']);
        $this->getJson('/api/users', $headers)->assertOk();

        $user->revokePermission('users.view');

        $this->getJson('/api/users', $headers)->assertForbidden();
        $this->getJson('/api/auth/me', $headers)->assertJson(['permissions' => []]);
    }

    public function test_give_revoke_and_sync_permissions(): void
    {
        $user = User::factory()->create();

        $user->givePermission('users.view', 'users.create');
        $this->assertSame(['users.create', 'users.view'], $user->permissionKeys());

        $user->revokePermission('users.create');
        $this->assertSame(['users.view'], $user->permissionKeys());

        $user->syncPermissions(['dashboard.view']);
        $this->assertSame(['dashboard.view'], $user->permissionKeys());
        $this->assertFalse($user->hasPermission('users.view'));
    }

    public function test_permission_manager_can_assign_permissions_they_hold(): void
    {
        [, $headers] = $this->login(['users.permissions', 'users.view']);
        $target = User::factory()->create();

        $this->putJson("/api/users/{$target->id}/permissions", ['permissions' => ['users.view']], $headers)
            ->assertOk()
            ->assertJson(['permissions' => ['users.view']]);

        $this->assertTrue($target->hasPermission('users.view'));
    }

    public function test_non_master_cannot_grant_permissions_they_do_not_hold(): void
    {
        [$user, $headers] = $this->login(['users.permissions']);

        $this->putJson("/api/users/{$user->id}/permissions", ['permissions' => ['users.permissions', 'users.delete']], $headers)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasPermission('users.delete'));
    }

    public function test_non_master_cannot_edit_or_delete_a_master(): void
    {
        [, $headers] = $this->login(['users.update', 'users.delete']);
        $master = User::factory()->master()->create();

        $this->putJson("/api/users/{$master->id}", ['password' => 'takeover1'], $headers)->assertForbidden();
        $this->deleteJson("/api/users/{$master->id}", [], $headers)->assertForbidden();
    }

    public function test_master_flag_is_never_set_through_the_api(): void
    {
        [$user, $headers] = $this->login(['users.update', 'users.create']);
        [, $masterHeaders] = $this->login(master: true);

        foreach ([$headers, $masterHeaders] as $i => $h) {
            $this->putJson("/api/users/{$user->id}", ['master' => true], $h)->assertOk();
            $this->postJson('/api/users', ['name' => 'X', 'email' => "x{$i}@x.com", 'password' => 'secret123', 'master' => true], $h)
                ->assertCreated();
        }

        $this->assertFalse($user->fresh()->master);
        $this->assertSame(0, User::whereIn('email', ['x0@x.com', 'x1@x.com'])->where('master', true)->count());

        // The flag lives only in the database: user payloads never carry it.
        $this->assertArrayNotHasKey('master', $this->getJson("/api/users/{$user->id}", $masterHeaders)->json());
        $this->assertArrayNotHasKey('master', $this->getJson('/api/users', $masterHeaders)->json('data.0'));
    }

    public function test_master_has_every_permission_key_without_assignments(): void
    {
        [$master, $headers] = $this->login(master: true);
        $all = \App\Models\Permission::orderBy('key')->pluck('key')->all();

        $this->assertNotEmpty($all);
        $this->assertSame($all, $master->permissionKeys());
        $this->getJson('/api/auth/me', $headers)->assertJson(['master' => true, 'permissions' => $all]);

        // A permission created later is covered immediately too.
        app(PermissionService::class)->create('reports', 'view', 'Ver relatórios', 'Relatórios');
        $this->assertContains('reports.view', $master->permissionKeys());
        $this->assertTrue($master->hasPermission('reports.view'));
        app(PermissionService::class)->delete('reports.view');
    }

    public function test_master_accounts_are_changed_only_in_the_database(): void
    {
        [$master, $headers] = $this->login(master: true);
        $other = User::factory()->master()->create();

        foreach ([$master, $other] as $target) {
            $this->getJson("/api/users/{$target->id}", $headers)->assertNotFound();
            $this->putJson("/api/users/{$target->id}", ['name' => 'X'], $headers)->assertForbidden();
            $this->putJson("/api/users/{$target->id}/permissions", ['permissions' => []], $headers)->assertForbidden();
            $this->deleteJson("/api/users/{$target->id}", [], $headers)->assertForbidden();
        }

        $listed = collect($this->getJson('/api/users', $headers)->json('data'))->pluck('id');
        $this->assertFalse($listed->contains($master->id) || $listed->contains($other->id));
    }

    public function test_activity_log_requires_view_permission_of_the_subject(): void
    {
        [$user, $headers] = $this->login();

        $this->getJson("/api/activity-log?subject_type=user&subject_id={$user->id}", $headers)->assertForbidden();
    }

    public function test_every_controller_route_declares_a_permission_or_is_explicitly_public(): void
    {
        // Actions with no permission on purpose (auth-only or public). Anything else
        // must carry #[Permission], so a new endpoint can't ship unprotected by accident.
        $authOnly = [
            'App\Http\Controllers\AuthController@login',
            'App\Http\Controllers\AuthController@me',
            'App\Http\Controllers\AuthController@logout',
            'App\Http\Controllers\ActivityLogController@index', // checks per subject type
            'App\Http\Controllers\ZipCodeController@show', // public address data, auth only
            'App\Http\Controllers\SettingsController@updatePreferences', // the user's own preferences
            'App\Http\Controllers\CustomerController@register', // public route, throttle:public
            // Bot integration: BotMiddleware (bot token + activated Telegram user), not user permissions.
            'App\Http\Controllers\Bot\BotController@activate',
            'App\Http\Controllers\Bot\BotController@me',
            'App\Http\Controllers\Bot\BotController@disconnect',
            'App\Http\Controllers\Bot\BotController@categories',
            'App\Http\Controllers\Bot\BotController@summary',
            'App\Http\Controllers\Bot\BotController@createTransaction',
        ];

        $unprotected = collect(Route::getRoutes()->getRoutes())
            // The restricted area (companies, status) is guarded by the `master` middleware instead.
            ->reject(fn ($route) => in_array('master', $route->gatherMiddleware(), true))
            ->map->getActionName()
            ->filter(fn (string $action) => str_starts_with($action, 'App\\Http\\Controllers\\'))
            ->reject(fn (string $action) => in_array($action, $authOnly, true))
            ->reject(fn (string $action) => CheckPermission::requiredPermission($action) !== null)
            ->values()
            ->all();

        $this->assertSame([], $unprotected);
    }

    public function test_invokable_controller_permission_is_enforced(): void
    {
        Route::middleware(['auth', 'permission'])->get('/api/test-invokable', InvokableFixtureController::class);
        [, $denied] = $this->login(['users.view']);
        [, $allowed] = $this->login(['dashboard.view']);

        $this->getJson('/api/test-invokable', $denied)->assertForbidden();
        $this->getJson('/api/test-invokable', $allowed)->assertOk();
    }

    public function test_every_declared_permission_exists_in_config(): void
    {
        $declared = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => CheckPermission::requiredPermission($route->getActionName()))
            ->filter()
            ->unique();

        $configured = collect(config('permissions'))
            ->flatMap(fn ($module, $name) => array_map(fn ($action) => "{$name}.{$action}", array_keys($module['actions'])));

        $this->assertSame([], $declared->diff($configured)->values()->all());
    }
}

class InvokableFixtureController
{
    #[\App\Attributes\Permission('dashboard.view')]
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
