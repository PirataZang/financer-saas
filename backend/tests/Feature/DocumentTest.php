<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Service\DocumentService;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['documents.view', 'documents.create', 'documents.update', 'documents.delete', 'documents.emit'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
    }

    private function headers(array $permissions = self::ALL): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Ana <b>Souza</b>', 'email' => 'ana@x.com', 'street' => 'Rua A', 'number' => '10', 'city' => 'Campinas', 'state' => 'SP']);
    }

    public function test_crud_and_permissions(): void
    {
        $headers = $this->headers();

        $id = $this->postJson('/api/documents', ['name' => 'Contrato', 'content' => '<p>Olá {{Cliente.name}}</p>'], $headers)
            ->assertCreated()->assertJsonPath('active', true)->json('id');
        $this->getJson("/api/documents/{$id}", $headers)->assertOk()->assertJsonPath('name', 'Contrato');
        $this->putJson("/api/documents/{$id}", ['active' => false, 'description' => 'Contrato de serviço'], $headers)->assertOk()->assertJsonPath('active', false);
        $this->getJson('/api/documents?active=0&search=Contra', $headers)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/documents?active=1', $headers)->assertOk()->assertJsonPath('total', 0);
        $this->putJson("/api/documents/{$id}", ['description' => str_repeat('x', 26)], $headers)->assertUnprocessable();
        $this->deleteJson("/api/documents/{$id}", [], $headers)->assertOk();
        $this->assertSame(0, Document::count());

        $viewer = $this->headers(['documents.view']);
        $this->getJson('/api/documents', $viewer)->assertOk();
        $this->postJson('/api/documents', ['name' => 'X'], $viewer)->assertForbidden();
        $this->getJson('/api/documents/1/emit', $viewer)->assertForbidden();
        $this->getJson('/api/documents', $this->headers(['dashboard.view']))->assertForbidden();
    }

    public function test_saved_html_is_sanitized_to_what_the_toolbar_can_produce(): void
    {
        $dirty = '<p onclick="x()">Oi <b>você</b><script>alert(1)</script><img src="javascript:alert(1)"></p><a href="javascript:alert(2)">l</a>';
        $id = $this->postJson('/api/documents', ['name' => 'Sujo', 'content' => $dirty], $this->headers())->assertCreated()->json('id');

        $content = Document::find($id)->content;
        $this->assertStringContainsString('<b>você</b>', $content);
        $this->assertStringNotContainsString('script', $content);
        $this->assertStringNotContainsString('onclick', $content);
        $this->assertStringNotContainsString('javascript:', $content);
    }

    public function test_variables_are_listed_for_the_editor(): void
    {
        $list = $this->getJson('/api/documents/variables', $this->headers(['documents.view']))->assertOk()->json();

        $this->assertSame(['key' => 'Cliente.name', 'label' => 'Cliente - Nome'], $list[0]);
        $this->assertSame(array_keys(DocumentService::VARIABLES), array_column($list, 'key'));
    }

    public function test_emitting_fills_every_variable_and_escapes_values(): void
    {
        $customer = $this->customer();
        $project = Project::create(['customer_id' => $customer->id, 'name' => 'Site novo', 'due_date' => '2026-12-31']);
        $keys = array_keys(DocumentService::VARIABLES);
        $document = Document::create(['name' => 'Tudo', 'content' => '<p>'.implode(' | ', array_map(fn ($k) => '{{'.$k.'}}', $keys)).'</p>']);

        $html = app(DocumentService::class)->render($document, ['customer_id' => $customer->id, 'project_id' => $project->id, 'value' => '1500.5']);

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringContainsString('Ana &lt;b&gt;Souza&lt;/b&gt;', $html);   // escaped, never markup
        $this->assertStringContainsString('Rua A, 10, Campinas - SP', $html);
        $this->assertStringContainsString('31/12/2026', $html);
        $this->assertStringContainsString('R$ 1.500,50', $html);
        $this->assertStringContainsString(now()->format('d/m/Y'), $html);
    }

    public function test_a_variable_without_data_stops_the_emission_and_inactive_documents_cannot_be_issued(): void
    {
        $headers = $this->headers();
        $id = $this->postJson('/api/documents', ['name' => 'Contrato', 'content' => '<p>{{Cliente.name}} {{Valor}}</p>'], $headers)->json('id');

        $this->getJson("/api/documents/{$id}/emit", $headers)->assertUnprocessable()
            ->assertJsonPath('errors.variables.0', fn ($m) => str_contains($m, 'Cliente - Nome') && str_contains($m, 'Valor'));

        $response = $this->get("/api/documents/{$id}/emit?customer_id={$this->customer()->id}&value=10", $headers);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent() ?: $response->streamedContent());

        $this->putJson("/api/documents/{$id}", ['active' => false], $headers)->assertOk();
        $this->getJson("/api/documents/{$id}/emit?customer_id={$this->customer()->id}&value=10", $headers)->assertUnprocessable()->assertJsonValidationErrors('active');
    }

    public function test_documents_and_emission_targets_stay_inside_the_company(): void
    {
        $headers = $this->headers();
        $id = $this->postJson('/api/documents', ['name' => 'Meu', 'content' => '<p>{{Cliente.name}}</p>'], $headers)->json('id');
        $other = Company::create(['name' => 'Outra']);
        $foreign = Company::as($other->id, fn () => Customer::create(['name' => 'De outra', 'email' => 'o@x.com']));

        $this->getJson("/api/documents/{$id}/emit?customer_id={$foreign->id}", $headers)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        Company::as($other->id, fn () => $this->assertSame(0, Document::count()));
    }
}
