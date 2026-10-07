<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZipCodeTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_found_zip_code_returns_the_address(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'cep' => '89160-000', 'logradouro' => 'Rua XV de Novembro', 'bairro' => 'Centro',
            'localidade' => 'Rio do Sul', 'uf' => 'SC',
        ])]);

        $this->getJson('/api/zip-codes/89160-000', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'zip_code' => '89160000', 'street' => 'Rua XV de Novembro', 'neighborhood' => 'Centro',
                'city' => 'Rio do Sul', 'state' => 'SC', 'country' => 'Brasil',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://viacep.com.br/ws/89160000/json/');
    }

    public function test_results_are_cached(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['cep' => '89160-000', 'localidade' => 'Rio do Sul', 'uf' => 'SC'])]);
        $headers = $this->headers();

        $this->getJson('/api/zip-codes/89160000', $headers)->assertOk();
        $this->getJson('/api/zip-codes/89160000', $headers)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_unknown_zip_code_returns_404(): void
    {
        $headers = $this->headers();

        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);
        $this->getJson('/api/zip-codes/00000000', $headers)->assertNotFound()->assertJsonStructure(['message']);

        // ViaCEP currently answers the flag as a string.
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => 'true'])]);
        $this->getJson('/api/zip-codes/00000001', $headers)->assertNotFound();
    }

    public function test_invalid_format_is_rejected_without_calling_the_provider(): void
    {
        Http::fake();
        $headers = $this->headers();

        $this->getJson('/api/zip-codes/1234', $headers)->assertUnprocessable();
        $this->getJson('/api/zip-codes/abcdefgh', $headers)->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_timeout_returns_503_without_leaking_details(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->getJson('/api/zip-codes/89160000', $this->headers())
            ->assertStatus(503)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('cURL');
    }

    public function test_provider_error_or_garbage_returns_503(): void
    {
        $headers = $this->headers();

        Http::fake(['viacep.com.br/*' => Http::response('<html>bad gateway</html>', 502)]);
        $this->getJson('/api/zip-codes/89160000', $headers)->assertStatus(503);

        Http::fake(['viacep.com.br/*' => Http::response(['unexpected' => 'shape'])]);
        $this->getJson('/api/zip-codes/89160001', $headers)->assertStatus(503);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/zip-codes/89160000')->assertUnauthorized();
    }
}
