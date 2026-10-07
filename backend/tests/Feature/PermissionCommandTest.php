<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Work on a copy so tests never rewrite the real config/permissions.php.
        $this->configPath = tempnam(sys_get_temp_dir(), 'permissions').'.php';
        copy(config_path('permissions.php'), $this->configPath);
        $this->app->instance(PermissionService::class, new PermissionService($this->configPath));
        app(PermissionService::class)->sync();
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        parent::tearDown();
    }

    private function config(): array
    {
        return app(PermissionService::class)->definitions();
    }

    public function test_permission_without_operation_lists(): void
    {
        $this->artisan('permission')
            ->expectsOutputToContain('users.view')
            ->expectsOutputToContain('Usuários')
            ->assertSuccessful();
    }

    public function test_create_writes_config_and_database(): void
    {
        $this->artisan('permission', [
            'operation' => 'create',
            'key' => 'leads.view',
            '--module-name' => 'Leads',
            '--description' => 'Visualizar leads',
        ])->assertSuccessful();

        $this->assertSame('Leads', $this->config()['leads']['name']);
        $this->assertSame('Visualizar leads', $this->config()['leads']['actions']['view']);
        $this->assertDatabaseHas('permissions', ['key' => 'leads.view', 'module_name' => 'Leads']);
    }

    public function test_create_asks_module_name_for_new_module(): void
    {
        $this->artisan('permission', ['operation' => 'create', 'key' => 'leads.view'])
            ->expectsQuestion('Módulo novo "leads". Nome de exibição (ex.: Leads)', 'Leads')
            ->expectsQuestion('Descrição', 'Ver leads')
            ->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['key' => 'leads.view', 'description' => 'Ver leads']);
    }

    public function test_create_rejects_duplicates_and_invalid_keys(): void
    {
        $before = file_get_contents($this->configPath);

        $this->artisan('permission', ['operation' => 'create', 'key' => 'users.view', '--description' => 'x'])
            ->expectsOutputToContain('já existe')
            ->assertFailed();

        $this->artisan('permission', ['operation' => 'create', 'key' => 'Users View', '--description' => 'x'])
            ->assertFailed();

        $this->assertSame($before, file_get_contents($this->configPath));
        $this->assertSame(1, Permission::where('key', 'users.view')->count());
    }

    public function test_update_renames_key_and_keeps_user_assignments(): void
    {
        $user = User::factory()->create();
        $user->givePermission('users.view');
        $this->assertTrue($user->hasPermission('users.view'));

        $this->artisan('permission', [
            'operation' => 'update',
            'key' => 'users.view',
            '--rename' => 'users.list',
            '--description' => 'Listar usuários',
        ])->assertSuccessful();

        $this->assertArrayNotHasKey('view', $this->config()['users']['actions']);
        $this->assertSame('Listar usuários', $this->config()['users']['actions']['list']);
        $this->assertDatabaseMissing('permissions', ['key' => 'users.view']);
        // Cache was invalidated: the user sees the new key, not the stale one.
        $this->assertTrue($user->hasPermission('users.list'));
        $this->assertFalse($user->hasPermission('users.view'));
    }

    public function test_delete_requires_confirmation_and_detaches_users(): void
    {
        $user = User::factory()->create();
        $user->givePermission('users.delete');

        $this->artisan('permission', ['operation' => 'delete', 'key' => 'users.delete'])
            ->expectsConfirmation('Excluir users.delete do config e do banco?', 'no')
            ->assertSuccessful();
        $this->assertDatabaseHas('permissions', ['key' => 'users.delete']);

        $this->artisan('permission', ['operation' => 'delete', 'key' => 'users.delete'])
            ->expectsConfirmation('Excluir users.delete do config e do banco?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('permissions', ['key' => 'users.delete']);
        $this->assertArrayNotHasKey('delete', $this->config()['users']['actions']);
        $this->assertDatabaseCount('permission_user', 0);
        $this->assertFalse($user->hasPermission('users.delete'));
    }

    public function test_delete_unknown_permission_fails(): void
    {
        $this->artisan('permission', ['operation' => 'delete', 'key' => 'nope.view', '--force' => true])
            ->expectsOutputToContain('não existe')
            ->assertFailed();
    }

    public function test_sync_removes_permissions_dropped_from_config(): void
    {
        $user = User::factory()->create();
        $user->givePermission('dashboard.view');

        $config = $this->config();
        unset($config['dashboard']);
        file_put_contents($this->configPath, '<?php return '.var_export($config, true).';');

        $this->artisan('permission', ['operation' => 'sync'])->assertSuccessful();

        $this->assertDatabaseMissing('permissions', ['key' => 'dashboard.view']);
        $this->assertFalse($user->hasPermission('dashboard.view'));
    }
}
