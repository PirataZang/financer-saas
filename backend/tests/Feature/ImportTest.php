<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Service\ImportService;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
    }

    /** @return array<string, string> */
    private function headers(array $permissions = ['imports.view', 'imports.create']): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_parse_reads_a_semicolon_csv_maps_columns_and_drops_the_erro_column(): void
    {
        $csv = "\xEF\xBB\xBFcliente_name;E-mail;Telefone;Erro\r\nMaria;maria@x.com;(47) 99999-0000;Telefone inválido\r\n;;;\r\nJoão;;47988887777;\r\n";

        $response = $this->postJson('/api/imports/customer/parse', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)], $this->headers())
            ->assertOk()
            ->assertJsonPath('headers', ['cliente_name', 'E-mail', 'Telefone'])
            ->assertJsonPath('mapping.name', 0)
            ->assertJsonPath('mapping.email', 1)
            ->assertJsonPath('mapping.phone', 2)
            ->assertJsonPath('mapping.city', null)
            ->assertJsonPath('fields.0', ['key' => 'name', 'label' => 'Nome', 'required' => true, 'secret' => false])
            ->assertJsonPath('requires', ['all' => ['name'], 'any' => ['email', 'phone']]);

        $this->assertSame([['Maria', 'maria@x.com', '(47) 99999-0000'], ['João', '', '47988887777']], $response->json('rows'));
    }

    public function test_the_template_is_read_back_with_every_column_mapped(): void
    {
        $path = app(ImportService::class)->template('customer');
        $file = new UploadedFile($path, 'modelo.xlsx', null, null, true);

        $mapping = app(ImportService::class)->parse('customer', $file)['mapping'];

        $this->assertNotContains(null, $mapping);
        $this->assertCount(14, $mapping);
        unlink($path);
    }

    public function test_validate_applies_the_registration_rules_and_flags_the_customer_it_would_update(): void
    {
        Customer::create(['name' => 'Ana', 'email' => 'ana@x.com']);

        $result = $this->postJson('/api/imports/customer/validate', ['rows' => [
            ['name' => 'Bia', 'email' => 'bia@x.com', 'phone' => '123', 'status' => 'Em aquecimento', 'active' => 'não'],
            ['name' => 'Ana Nova', 'email' => 'ANA@x.com'],
            ['name' => '', 'email' => 'sem-arroba'],
        ]], $this->headers())->assertOk()->json();

        $this->assertArrayHasKey('phone', $result[0]['errors']);
        $bad = $this->postJson('/api/imports/customer/validate', ['rows' => [['name' => 'X', 'email' => 'x@x.com', 'status' => 'Foo']]], $this->headers())->json();
        $this->assertSame('Use Lead, Em aquecimento, Cliente ou Cancelado.', $bad[0]['errors']['status']);
        $this->assertNull($result[0]['existing']);
        $this->assertSame([], $result[1]['errors']);
        $this->assertSame('Ana', $result[1]['existing']['name']);
        $this->assertSame(['name', 'email'], array_keys($result[2]['errors']));
    }

    public function test_import_creates_updates_keeping_blank_cells_and_records_failures(): void
    {
        $ana = Customer::create(['name' => 'Ana', 'email' => 'ana@x.com', 'city' => 'Blumenau']);
        $headers = $this->headers();

        $import = $this->postJson('/api/imports/customer', [
            'filename' => 'clientes.csv',
            'rows' => [
                ['line' => 1, 'data' => ['name' => 'Bia', 'phone' => '(47) 99999-0000', 'status' => 'Cliente', 'active' => 'não']],
                ['line' => 2, 'data' => ['name' => 'Ana Souza', 'email' => 'ana@x.com', 'city' => '']],
                ['line' => 3, 'data' => ['name' => 'Sem contato']],
                ['line' => 4, 'data' => ['name' => 'Telefone ruim', 'phone' => '12']],
            ],
        ], $headers)->assertCreated()->json();

        $this->assertSame(['completed', 4, 1, 1, 2], [$import['status'], $import['total'], $import['created_count'], $import['updated_count'], $import['failed_count']]);

        $bia = Customer::where('email', null)->where('phone', '47999990000')->sole();
        $this->assertSame(['customer', false], [$bia->status->value, $bia->active]);
        $ana->refresh();
        $this->assertSame(['Ana Souza', 'Blumenau'], [$ana->name, $ana->city]); // em branco não apaga
        $this->assertSame(2, Customer::count());

        $imported = $this->getJson("/api/imports/{$import['id']}/imported", $headers)->assertOk();
        $this->assertSame([[1, 'created'], [2, 'updated']], array_map(fn ($r) => [$r['line'], $r['status']], $imported->json('data')));

        $errors = $this->getJson("/api/imports/{$import['id']}/errors", $headers)->assertOk();
        $this->assertSame([3, 4], array_column($errors->json('data'), 'line'));

        $csv = $this->get("/api/imports/{$import['id']}/errors/csv", $headers)->assertOk()->streamedContent();
        $this->assertStringContainsString('Nome;E-mail;Telefone;Status;Ativo;CEP;Rua;Número;Complemento;Bairro;Referência;Cidade;Estado;País;Erro', $csv);
        $this->assertStringContainsString('Telefone ruim', $csv);
        $this->assertStringContainsString('Telefone: O telefone deve ter DDD', $csv);
    }

    public function test_email_and_phone_of_different_customers_is_an_error_not_a_silent_merge(): void
    {
        Customer::create(['name' => 'Ana', 'email' => 'ana@x.com']);
        Customer::create(['name' => 'Bia', 'phone' => '47999990000']);

        $result = $this->postJson('/api/imports/customer/validate', ['rows' => [
            ['name' => 'X', 'email' => 'ana@x.com', 'phone' => '47999990000'],
        ]], $this->headers())->json();

        $this->assertArrayHasKey('email', $result[0]['errors']);
    }

    public function test_it_requires_the_import_permissions(): void
    {
        $this->getJson('/api/imports?type=customer', $this->headers([]))->assertForbidden();
        $this->postJson('/api/imports/customer/validate', ['rows' => []], $this->headers(['imports.view']))->assertForbidden();
    }

    /** @return array<string, string> */
    private function userAdmin(array $extra = ['users.view', 'users.create', 'users.update']): array
    {
        return $this->headers(['imports.view', 'imports.create', ...$extra]);
    }

    public function test_user_import_needs_the_users_permissions_too(): void
    {
        $rows = ['filename' => 'u.csv', 'rows' => [['line' => 2, 'data' => ['name' => 'Ana', 'email' => 'ana@x.com', 'password' => 'secret123']]]];

        $this->postJson('/api/imports/user', $rows, $this->headers(['imports.view', 'imports.create']))->assertForbidden();
        $this->postJson('/api/imports/user', $rows, $this->headers(['imports.view', 'imports.create', 'users.view', 'users.create']))->assertForbidden();
        $this->getJson('/api/imports?type=user', $this->headers(['imports.view']))->assertForbidden();
        $this->postJson('/api/imports/user', $rows, $this->userAdmin())->assertCreated();
    }

    public function test_user_import_creates_updates_and_never_exposes_or_keeps_the_password(): void
    {
        $existing = User::factory()->create(['name' => 'Velho', 'email' => 'velho@x.com', 'password' => 'oldpass123']);
        $master = User::factory()->master()->create(['email' => 'root@x.com']);
        $outra = \App\Models\Company::create(['name' => 'Outra']);
        \App\Models\Company::as($outra->id, fn () => User::factory()->create(['email' => 'fora@x.com']));
        $headers = $this->userAdmin();

        $import = $this->postJson('/api/imports/user', [
            'filename' => 'usuarios.csv',
            'rows' => [
                ['line' => 2, 'data' => ['name' => 'Nova Pessoa', 'email' => 'NOVA@x.com', 'password' => 'secret123', 'active' => 'não']],
                ['line' => 3, 'data' => ['name' => 'Velho Renomeado', 'email' => 'velho@x.com']],
                ['line' => 4, 'data' => ['name' => 'Sem senha', 'email' => 'semsenha@x.com']],
                ['line' => 5, 'data' => ['name' => 'Master', 'email' => 'root@x.com', 'password' => 'secret123']],
                ['line' => 6, 'data' => ['name' => 'De fora', 'email' => 'fora@x.com', 'password' => 'secret123']],
            ],
        ], $headers)->assertCreated()->json();

        $this->assertSame([1, 1, 3], [$import['created_count'], $import['updated_count'], $import['failed_count']]);

        $new = User::where('email', 'nova@x.com')->sole();
        $this->assertSame(['Nova Pessoa', false], [$new->name, $new->active]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret123', $new->password));
        $existing->refresh();
        $this->assertSame('Velho Renomeado', $existing->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('oldpass123', $existing->password)); // sem coluna de senha: mantém
        $this->assertNotSame('Master', $master->fresh()->name); // o master não foi tocado

        $errors = $this->getJson("/api/imports/{$import['id']}/errors", $headers)->assertOk()->json('data');
        $this->assertSame([4, 5, 6], array_column($errors, 'line'));
        $this->assertStringContainsString('Senha: Obrigatório.', $errors[0]['error']);
        $this->assertStringContainsString('master', $errors[1]['error']);
        $this->assertStringContainsString('Não foi possível usar este e-mail', $errors[2]['error']);

        // O segredo não sai por nenhuma resposta nem sobra no banco depois de processado.
        $imported = $this->getJson("/api/imports/{$import['id']}/imported", $headers)->assertOk()->getContent();
        $this->assertStringNotContainsString('secret123', $imported.json_encode($errors));
        $this->assertStringNotContainsString('secret', $imported);
        $this->assertSame(0, \App\Models\ImportRow::whereNotNull('secret')->count());
    }

    public function test_the_template_has_dropdown_lists_where_only_some_options_are_valid(): void
    {
        $path = app(ImportService::class)->template('customer');
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('sqref="D2:D5001"><formula1>"Lead,Em aquecimento,Cliente,Cancelado"</formula1>', $sheet);
        $this->assertStringContainsString('sqref="E2:E5001"><formula1>"Sim,Não"</formula1>', $sheet);
        $this->assertStringContainsString('<dataValidations count="2">', $sheet);
        $zip->close();
        unlink($path);

        // E o arquivo continua legível.
        $this->assertNotContains(null, app(ImportService::class)->parse('customer', new UploadedFile(app(ImportService::class)->template('customer'), 'm.xlsx', null, null, true))['mapping']);
    }
}
