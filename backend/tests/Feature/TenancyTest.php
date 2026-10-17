<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\Model;
use App\Models\Permission;
use App\Models\User;
use App\Service\CompanyService;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private const EVERYTHING = [
        'dashboard.view', 'users.view', 'users.create', 'users.update', 'users.delete', 'users.permissions',
        'customers.view', 'customers.create', 'customers.update', 'customers.delete',
    ];

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();

        $this->other = Company::create(['name' => 'Outra Empresa']);
        $this->other->regenerateToken();
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function login(int $companyId, array $permissions = self::EVERYTHING, bool $master = false): array
    {
        $user = Company::as($companyId, fn () => ($master ? User::factory()->master() : User::factory())->create(['password' => 'secret123']));
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk()->json('token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_every_tenant_table_has_a_required_company_id_and_its_model_is_scoped(): void
    {
        // Framework/global tables. A new table that is NOT listed here must have company_id.
        $global = ['migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
            'failed_jobs', 'companies', 'permissions', 'permission_user'];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, $global, true)) {
                continue;
            }
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'company_id');
            $this->assertNotNull($column, "Tabela {$table} sem company_id.");
            $this->assertFalse($column['nullable'], "{$table}.company_id deve ser NOT NULL.");
        }

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (in_array($class, [Model::class, Company::class, Permission::class], true)) {
                continue;
            }
            $this->assertContains(BelongsToCompany::class, class_uses_recursive($class), "{$class} sem BelongsToCompany.");
        }
    }

    public function test_records_of_another_company_are_invisible_and_untouchable(): void
    {
        $theirs = Customer::factory()->create(['name' => 'Cliente da Padrão']);
        $theirUser = User::factory()->create();
        [, $headers] = $this->login($this->other->id);
        $mine = Company::as($this->other->id, fn () => Customer::factory()->create(['name' => 'Meu cliente']));

        $this->assertSame([$mine->id], collect($this->getJson('/api/customers', $headers)->assertOk()->json('data'))->pluck('id')->all());
        $this->assertNotContains($theirUser->id, collect($this->getJson('/api/users', $headers)->json('data'))->pluck('id'));

        // Same answer as an id that never existed: no hint the record is someone else's.
        $missing = 999999;
        foreach ([$theirs->id, $missing] as $id) {
            $this->getJson("/api/customers/{$id}", $headers)->assertNotFound();
            $this->putJson("/api/customers/{$id}", ['name' => 'Hack'], $headers)->assertNotFound();
            $this->deleteJson("/api/customers/{$id}", [], $headers)->assertNotFound();
            $this->getJson("/api/activity-log?subject_type=customer&subject_id={$id}", $headers)->assertNotFound();
        }
        $this->putJson("/api/users/{$theirUser->id}", ['name' => 'Hack'], $headers)->assertNotFound();
        $this->putJson("/api/users/{$theirUser->id}/permissions", ['permissions' => []], $headers)->assertNotFound();

        $this->assertSame('Cliente da Padrão', $theirs->fresh()->name);
        $this->assertNull($theirs->fresh()->deleted_at);
    }

    public function test_company_id_comes_from_the_session_never_from_the_request(): void
    {
        [, $headers] = $this->login($this->other->id);
        $headers['X-Company-Id'] = (string) Company::DEFAULT_ID; // ignored: not a master

        $id = $this->postJson('/api/customers', ['name' => 'Novo', 'email' => 'n@x.com', 'company_id' => Company::DEFAULT_ID], $headers)
            ->assertCreated()->json('id');
        $this->putJson("/api/customers/{$id}", ['company_id' => Company::DEFAULT_ID], $headers)->assertOk();
        $this->getJson('/api/customers', $headers)->assertJsonCount(1, 'data');

        $this->assertSame($this->other->id, DB::table('customers')->where('id', $id)->value('company_id'));
    }

    public function test_the_same_data_can_exist_in_two_companies_and_duplicate_login_email_reveals_nothing(): void
    {
        Customer::factory()->create(['email' => 'mesmo@x.com']);
        User::factory()->create(['email' => 'ocupado@x.com']);
        [, $headers] = $this->login($this->other->id);

        $this->postJson('/api/customers', ['name' => 'Mesmo', 'email' => 'mesmo@x.com'], $headers)->assertCreated();

        $response = $this->postJson('/api/users', ['name' => 'X', 'email' => 'ocupado@x.com', 'password' => 'secret123'], $headers)
            ->assertUnprocessable();
        $this->assertSame('Não foi possível usar este e-mail. Informe outro.', $response->json('errors.email.0'));
    }

    public function test_an_inactive_company_blocks_its_users_immediately(): void
    {
        [$user, $headers] = $this->login($this->other->id);
        $this->getJson('/api/customers', $headers)->assertOk();

        $this->other->forceFill(['expires_at' => now()->subDay()->toDateString()])->save();
        $this->getJson('/api/customers', $headers)->assertForbidden()->assertJson(['code' => 'COMPANY_INACTIVE']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'O acesso da sua empresa está suspenso ou expirado. Fale com o suporte.');

        $this->other->forceFill(['expires_at' => now()->toDateString()])->save(); // the whole last day counts
        $this->getJson('/api/customers', $headers)->assertOk();

        $this->other->revokeToken();
        $this->getJson('/api/customers', $headers)->assertForbidden();

        $this->other->regenerateToken();
        $this->other->delete();
        $this->getJson('/api/customers', $headers)->assertForbidden();
    }

    public function test_master_switches_companies_including_inactive_ones(): void
    {
        [, $headers] = $this->login(Company::DEFAULT_ID, [], master: true);
        Company::as($this->other->id, fn () => Customer::factory()->create(['name' => 'Da outra']));
        $this->other->revokeToken();

        $switched = [...$headers, 'X-Company-Id' => (string) $this->other->id];
        $this->getJson('/api/customers', $switched)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Da outra');
        $this->getJson('/api/auth/me', $switched)->assertJsonPath('company.id', $this->other->id)->assertJsonPath('company.active', false);

        $id = $this->postJson('/api/customers', ['name' => 'Criado pelo master', 'phone' => '47999990000'], $switched)->json('id');
        $this->assertSame($this->other->id, DB::table('customers')->where('id', $id)->value('company_id'));

        // Unknown company: back to the master's own.
        $this->getJson('/api/auth/me', [...$headers, 'X-Company-Id' => '999'])->assertJsonPath('company.id', Company::DEFAULT_ID);
        $this->getJson('/api/customers', $headers)->assertJsonCount(0, 'data');
    }

    public function test_session_payload_lists_companies_only_for_masters_and_never_tokens(): void
    {
        [, $user] = $this->login($this->other->id);
        [, $master] = $this->login(Company::DEFAULT_ID, [], master: true);

        $me = $this->getJson('/api/auth/me', $user)->assertJsonPath('company.id', $this->other->id)->assertJsonPath('companies', [])->json();
        $this->assertArrayNotHasKey('token', $me['company']);

        $companies = $this->getJson('/api/auth/me', $master)->json('companies');
        $this->assertCount(2, $companies);
        $this->assertArrayNotHasKey('token', $companies[0]);
    }

    public function test_session_tokens_are_stored_hashed(): void
    {
        [$user, $headers] = $this->login(Company::DEFAULT_ID);
        $plain = substr($headers['Authorization'], 7);

        $this->assertSame(hash('sha256', $plain), DB::table('users')->where('id', $user->id)->value('token'));
    }

    public function test_only_masters_reach_the_restricted_area(): void
    {
        [, $headers] = $this->login(Company::DEFAULT_ID); // every permission key, but not master

        $this->getJson('/api/companies', $headers)->assertForbidden();
        $this->postJson('/api/companies', ['name' => 'X'], $headers)->assertForbidden();
        $this->getJson('/api/status', $headers)->assertForbidden();
        $this->getJson("/api/activity-log?subject_type=company&subject_id={$this->other->id}", $headers)->assertForbidden();
    }

    public function test_master_manages_companies_and_licenses(): void
    {
        [, $headers] = $this->login(Company::DEFAULT_ID, [], master: true);

        $created = $this->postJson('/api/companies', [
            'name' => 'Nova Ltda', 'trade_name' => 'Nova', 'document' => '11.222.333/0001-81',
            'zip_code' => '89160-000', 'city' => 'Rio do Sul', 'state' => 'sc', 'expires_at' => '2030-12-31',
        ], $headers)->assertCreated()->assertJson(['document' => '11222333000181', 'state' => 'SC', 'active' => true])->json();
        $this->assertSame(40, strlen($created['token']));

        $this->postJson('/api/companies', ['name' => 'Dup', 'document' => '11222333000181'], $headers)->assertJsonValidationErrors('document');
        $this->postJson('/api/companies', ['name' => 'Bad', 'document' => '11222333000180'], $headers)->assertJsonValidationErrors('document');
        $this->postJson('/api/companies', ['name' => 'Cpf', 'document' => '529.982.247-25'], $headers)->assertCreated();

        $this->assertArrayNotHasKey('token', $this->getJson('/api/companies', $headers)->json('data.0'));

        $revoked = $this->deleteJson("/api/companies/{$created['id']}/token", [], $headers)->assertOk()->json();
        $this->assertNull($revoked['token']);
        $this->assertFalse($revoked['active']);
        $renewed = $this->postJson("/api/companies/{$created['id']}/token", [], $headers)->assertOk()->json();
        $this->assertTrue($renewed['active']);
        $this->assertSame(today()->addDays(30)->toDateString(), $renewed['expires_at']); // had a date: +30 days from today

        $this->postJson('/api/companies/'.Company::DEFAULT_ID.'/token', [], $headers)
            ->assertOk()->assertJsonPath('expires_at', null); // no expiry: stays without

        $this->putJson("/api/companies/{$created['id']}", ['expires_at' => '2020-01-01'], $headers)->assertOk()->assertJson(['active' => false]);
        $fields = collect($this->getJson("/api/activity-log?subject_type=company&subject_id={$created['id']}", $headers)->assertOk()->json())
            ->flatMap(fn ($entry) => collect($entry['changes'])->pluck('field'));
        $this->assertTrue($fields->contains('expires_at'));

        $this->deleteJson("/api/companies/{$created['id']}", [], $headers)->assertOk();
        $this->assertSoftDeleted('companies', ['id' => $created['id']]);
        $this->deleteJson('/api/companies/'.Company::DEFAULT_ID, [], $headers)->assertForbidden();
        $this->assertNotSoftDeleted('companies', ['id' => Company::DEFAULT_ID]);
    }

    public function test_without_a_current_company_scoped_queries_return_nothing(): void
    {
        Customer::factory()->create();
        Company::use(null);

        $this->assertSame(0, Customer::count());
        $this->assertSame(1, DB::table('customers')->count());
    }

    public function test_customer_command_runs_inside_the_given_company(): void
    {
        $this->artisan('customer', ['operation' => 'create', '--name' => 'Via CLI', '--email' => 'cli@x.com', '--company' => $this->other->id])
            ->assertSuccessful();
        $this->artisan('customer', ['operation' => 'list', '--company' => 999])->assertFailed();

        $this->assertSame($this->other->id, DB::table('customers')->where('email', 'cli@x.com')->value('company_id'));
    }

    public function test_document_check_digits(): void
    {
        foreach (['52998224725', '11222333000181'] as $valid) {
            $this->assertTrue(CompanyService::validDocument($valid), $valid);
        }
        foreach (['52998224724', '11222333000180', '11111111111', '123'] as $invalid) {
            $this->assertFalse(CompanyService::validDocument($invalid), $invalid);
        }
    }
}
