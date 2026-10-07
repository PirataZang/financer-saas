<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_without_operation_lists(): void
    {
        Customer::factory()->create(['name' => 'Ana Lima', 'status' => 'warming_up']);

        // One table row = one output line, and each expectation consumes the line it matches.
        $this->artisan('customer')
            ->expectsOutputToContain('Em aquecimento')
            ->assertSuccessful();
    }

    public function test_list_filters_by_search_and_status(): void
    {
        Customer::factory()->create(['name' => 'Ana Lima', 'status' => 'lead']);
        Customer::factory()->create(['name' => 'Bruno Reis', 'status' => 'customer']);

        $this->artisan('customer', ['--filter-status' => 'customer'])
            ->expectsOutputToContain('Bruno Reis')
            ->doesntExpectOutputToContain('Ana Lima')
            ->assertSuccessful();

        $this->artisan('customer', ['--filter-status' => 'vip'])->assertFailed();
    }

    public function test_create_applies_api_rules_and_fills_address_from_zip_code(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP',
        ])]);

        $this->artisan('customer', [
            'operation' => 'create',
            '--name' => 'Maria Souza',
            '--phone' => '(11) 98888-7777',
            '--zip-code' => '01001-000',
            '--number' => '10',
        ])->expectsOutputToContain('criado como Lead')->assertSuccessful();

        $this->assertDatabaseHas('customers', [
            'name' => 'Maria Souza', 'phone' => '11988887777', 'zip_code' => '01001000',
            'street' => 'Praça da Sé', 'number' => '10', 'city' => 'São Paulo', 'state' => 'SP', 'status' => 'lead',
        ]);
    }

    public function test_create_asks_for_missing_name_and_contact(): void
    {
        $this->artisan('customer', ['operation' => 'create'])
            ->expectsQuestion('Nome', 'Interativo')
            ->expectsQuestion('E-mail (enter para informar telefone)', '')
            ->expectsQuestion('Telefone com DDD', '47999990000')
            ->assertSuccessful();

        $this->assertDatabaseHas('customers', ['name' => 'Interativo', 'phone' => '47999990000', 'email' => null]);
    }

    public function test_create_rejects_invalid_data(): void
    {
        $this->artisan('customer', ['operation' => 'create', '--name' => 'Sem Contato', '--email' => 'bad', '--status' => 'vip'])
            ->assertFailed();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_update_changes_only_given_fields(): void
    {
        $customer = Customer::factory()->create(['status' => 'lead', 'name' => 'Ana Lima']);

        $this->artisan('customer', ['operation' => 'update', 'id' => $customer->id, '--status' => 'customer', '--active' => '0'])
            ->assertSuccessful();

        $customer->refresh();
        $this->assertSame('customer', $customer->status->value);
        $this->assertFalse($customer->active);
        $this->assertSame('Ana Lima', $customer->name);

        $this->artisan('customer', ['operation' => 'update', 'id' => $customer->id])->assertFailed();
        $this->artisan('customer', ['operation' => 'update', 'id' => 999, '--name' => 'X'])
            ->expectsOutputToContain('não encontrado')
            ->assertFailed();
    }

    public function test_delete_confirms_and_soft_deletes(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ana Lima']);

        $this->artisan('customer', ['operation' => 'delete', 'id' => $customer->id])
            ->expectsConfirmation("Excluir o cliente #{$customer->id} (Ana Lima)?", 'no')
            ->assertSuccessful();
        $this->assertNotSoftDeleted($customer);

        $this->artisan('customer', ['operation' => 'delete', 'id' => $customer->id, '--force' => true])->assertSuccessful();
        $this->assertSoftDeleted($customer);
    }
}
