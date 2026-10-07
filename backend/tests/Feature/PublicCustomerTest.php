<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = [], array $server = [])
    {
        return $this->withServerVariables($server)->postJson('/api/public/customers', [
            'name' => 'Visitante',
            'email' => 'visitante@example.com',
            ...$overrides,
        ]);
    }

    public function test_public_registration_creates_an_active_lead_and_returns_only_a_confirmation(): void
    {
        $this->register(['phone' => '(47) 3333-4444', 'status' => 'customer', 'active' => false])
            ->assertCreated()
            ->assertExactJson(['message' => 'Cadastro recebido com sucesso.']);

        $customer = Customer::sole();
        $this->assertSame('lead', $customer->status->value);
        $this->assertTrue($customer->active);
        $this->assertSame('4733334444', $customer->phone);
    }

    public function test_public_registration_applies_the_same_validation(): void
    {
        $this->register(['email' => null])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);
        $this->register(['name' => '', 'email' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->register(['zip_code' => '12'])->assertUnprocessable()->assertJsonValidationErrors(['zip_code']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_public_route_blocks_the_sixth_request_with_429(): void
    {
        $limit = config('rate-limit.public');
        $ip = ['REMOTE_ADDR' => '203.0.113.10'];

        for ($i = 1; $i <= $limit['max_attempts']; $i++) {
            $this->register(['email' => "v{$i}@example.com"], $ip)->assertCreated();
        }

        $this->register(['email' => 'v6@example.com'], $ip)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', $limit['max_attempts'])
            ->assertJson(['code' => 'TOO_MANY_REQUESTS'])
            ->assertJsonMissingPath('exception');

        // Another client is not affected.
        $this->register(['email' => 'other@example.com'], ['REMOTE_ADDR' => '203.0.113.11'])->assertCreated();

        // After the decay window the client may try again.
        $this->travel($limit['decay_seconds'] + 1)->seconds();
        $this->register(['email' => 'v7@example.com'], $ip)->assertCreated();
    }

    public function test_direct_client_cannot_dodge_the_limit_by_spoofing_x_forwarded_for(): void
    {
        for ($i = 1; $i <= config('rate-limit.public.max_attempts'); $i++) {
            $this->register(['email' => "s{$i}@example.com"], ['REMOTE_ADDR' => '203.0.113.20', 'HTTP_X_FORWARDED_FOR' => "198.51.100.{$i}"])
                ->assertCreated();
        }

        $this->register(['email' => 's9@example.com'], ['REMOTE_ADDR' => '203.0.113.20', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99'])
            ->assertStatus(429);
    }

    public function test_clients_behind_a_trusted_proxy_get_separate_limits(): void
    {
        // Nginx/Docker gateway on a private network forwards the real client IP.
        $proxy = fn (string $client) => ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => $client];

        for ($i = 1; $i <= config('rate-limit.public.max_attempts'); $i++) {
            $this->register(['email' => "p{$i}@example.com"], $proxy('198.51.100.1'))->assertCreated();
        }

        $this->register(['email' => 'p6@example.com'], $proxy('198.51.100.1'))->assertStatus(429);
        $this->register(['email' => 'q1@example.com'], $proxy('198.51.100.2'))->assertCreated();
    }

    public function test_login_is_rate_limited(): void
    {
        $max = config('rate-limit.authentication.max_attempts');

        for ($i = 1; $i <= $max; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
