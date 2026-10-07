<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
    }

    /**
     * @return array<string, string>
     */
    private function headers(array $permissions = [], bool $master = false): array
    {
        $user = ($master ? User::factory()->master() : User::factory())->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);

        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Maria Souza',
            'email' => 'maria@example.com',
            'phone' => '(47) 99999-0000',
            'status' => 'warming_up',
            'active' => true,
            'zip_code' => '89160-000',
            'street' => 'Rua XV',
            'number' => '120A',
            'complement' => 'Sala 3',
            'neighborhood' => 'Centro',
            'reference' => 'Ao lado da praça',
            'city' => 'Rio do Sul',
            'state' => 'sc',
            'country' => 'Brasil',
            ...$overrides,
        ];
    }

    public function test_user_with_permissions_can_crud_customers(): void
    {
        $headers = $this->headers(['customers.view', 'customers.create', 'customers.update', 'customers.delete']);

        $created = $this->postJson('/api/customers', $this->payload(), $headers)
            ->assertCreated()
            ->assertJson([
                'status' => 'warming_up', 'status_label' => 'Em aquecimento', 'active' => true,
                'number' => '120A', 'complement' => 'Sala 3', 'reference' => 'Ao lado da praça',
            ])
            ->json();

        $this->getJson("/api/customers/{$created['id']}", $headers)->assertOk()->assertJson(['name' => 'Maria Souza']);

        $this->putJson("/api/customers/{$created['id']}", ['status' => 'customer'], $headers)
            ->assertOk()
            ->assertJson(['status' => 'customer', 'status_label' => 'Cliente', 'name' => 'Maria Souza']);

        $this->deleteJson("/api/customers/{$created['id']}", [], $headers)->assertOk();
        $this->getJson("/api/customers/{$created['id']}", $headers)->assertNotFound();
        $this->assertSoftDeleted('customers', ['id' => $created['id']]);
    }

    public function test_input_is_normalized_before_persisting(): void
    {
        $headers = $this->headers(['customers.create']);

        $id = $this->postJson('/api/customers', $this->payload(['email' => '  Maria@Example.COM ']), $headers)
            ->assertCreated()
            ->json('id');

        $this->assertDatabaseHas('customers', [
            'id' => $id,
            'email' => 'maria@example.com',
            'phone' => '47999990000',
            'zip_code' => '89160000',
            'state' => 'SC',
        ]);
    }

    public function test_new_customer_defaults_to_active_lead(): void
    {
        $headers = $this->headers(['customers.create']);

        $this->postJson('/api/customers', ['name' => 'Só Email', 'email' => 'so@email.com'], $headers)
            ->assertCreated()
            ->assertJson(['status' => 'lead', 'active' => true]);
    }

    public function test_email_or_phone_is_required(): void
    {
        $headers = $this->headers(['customers.create', 'customers.update']);

        $this->postJson('/api/customers', $this->payload(['email' => null, 'phone' => null]), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
        $this->postJson('/api/customers', $this->payload(['email' => '', 'phone' => '']), $headers)
            ->assertUnprocessable();

        $this->postJson('/api/customers', $this->payload(['email' => null]), $headers)->assertCreated();
        $this->postJson('/api/customers', $this->payload(['phone' => null]), $headers)->assertCreated();
        $id = $this->postJson('/api/customers', $this->payload(), $headers)->assertCreated()->json('id');

        // Clearing both on update is rejected too.
        $this->putJson("/api/customers/{$id}", ['email' => null, 'phone' => null], $headers)->assertUnprocessable();
        $this->putJson("/api/customers/{$id}", ['phone' => null], $headers)->assertOk();
        $this->putJson("/api/customers/{$id}", ['email' => null], $headers)->assertUnprocessable();
        $this->assertDatabaseHas('customers', ['id' => $id, 'email' => 'maria@example.com']);
    }

    public function test_model_guards_email_or_phone_outside_the_api(): void
    {
        $this->expectException(ValidationException::class);

        Customer::create(['name' => 'Sem contato', 'email' => '', 'phone' => '']);
    }

    public function test_invalid_fields_are_rejected(): void
    {
        $headers = $this->headers(['customers.create']);

        $this->postJson('/api/customers', $this->payload([
            'name' => 'A',
            'email' => 'not-an-email',
            'phone' => '123',
            'status' => 'vip',
            'active' => 'maybe',
            'zip_code' => '1234',
            'number' => str_repeat('9', 21),
        ]), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'status', 'active', 'zip_code', 'number']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_list_searches_name_email_and_phone_and_filters_status(): void
    {
        Customer::factory()->create(['name' => 'Ana Lima', 'email' => 'ana@x.com', 'phone' => '47911112222', 'status' => 'lead']);
        Customer::factory()->create(['name' => 'Bruno Reis', 'email' => 'bruno@x.com', 'phone' => '47933334444', 'status' => 'customer']);
        $headers = $this->headers(['customers.view']);

        $names = fn (string $query) => collect($this->getJson("/api/customers?{$query}", $headers)->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Ana Lima'], $names('search=Ana'));
        $this->assertSame(['Bruno Reis'], $names('search=BRUNO@X'));
        $this->assertSame(['Bruno Reis'], $names('search='.urlencode('(47) 93333')));
        $this->assertSame(['Bruno Reis'], $names('status=customer'));
        $this->getJson('/api/customers?status=vip', $headers)->assertUnprocessable();
    }

    public function test_status_options_come_from_the_enum(): void
    {
        $this->getJson('/api/customers/statuses', $this->headers(['customers.view']))
            ->assertOk()
            ->assertExactJson(\App\Enums\CustomerStatus::options())
            ->assertJsonPath('1', ['value' => 'warming_up', 'label' => 'Em aquecimento', 'color' => 'warning', 'icon' => 'fa-solid fa-fire']);
    }

    public function test_each_operation_requires_its_permission_through_the_api(): void
    {
        $customer = Customer::factory()->create();
        $headers = $this->headers(['customers.view']);

        $this->getJson('/api/customers', $headers)->assertOk();
        $this->postJson('/api/customers', $this->payload(), $headers)->assertForbidden();
        $this->putJson("/api/customers/{$customer->id}", ['name' => 'Hacked'], $headers)->assertForbidden();
        $this->deleteJson("/api/customers/{$customer->id}", [], $headers)->assertForbidden();

        $none = $this->headers();
        $this->getJson('/api/customers', $none)->assertForbidden();
        $this->getJson("/api/customers/{$customer->id}", $none)->assertForbidden();
        $this->getJson('/api/customers/statuses', $none)->assertForbidden();
        $this->getJson("/api/activity-log?subject_type=customer&subject_id={$customer->id}", $none)->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => $customer->name, 'deleted_at' => null]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->postJson('/api/customers', $this->payload())->assertUnauthorized();
    }

    public function test_master_has_full_access(): void
    {
        $headers = $this->headers(master: true);

        $id = $this->postJson('/api/customers', $this->payload(), $headers)->assertCreated()->json('id');
        $this->deleteJson("/api/customers/{$id}", [], $headers)->assertOk();
    }

    public function test_changes_are_recorded_in_the_activity_log(): void
    {
        $headers = $this->headers(['customers.view', 'customers.update']);
        $customer = Customer::factory()->create(['status' => 'lead']);

        $this->putJson("/api/customers/{$customer->id}", ['status' => 'customer'], $headers)->assertOk();

        $latest = $this->getJson("/api/activity-log?subject_type=customer&subject_id={$customer->id}", $headers)
            ->assertOk()
            ->json('0');
        $this->assertSame('updated', $latest['event']);
        $this->assertContains(['field' => 'status', 'before' => 'lead', 'after' => 'customer'], $latest['changes']);
    }
}
