<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PortalCode;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
        Notification::fake();

        $this->company = Company::find(Company::DEFAULT_ID);
        $this->customer = Customer::factory()->create(['name' => 'Ana', 'email' => 'ana@cliente.com', 'active' => true]);
    }

    private function slug(?Company $company = null): string
    {
        return ($company ?? $this->company)->portalSlug();
    }

    /** Requests a code and returns it (taken from the e-mail notification). */
    private function requestCode(string $email = 'ana@cliente.com', ?Company $company = null): string
    {
        $this->postJson("/api/portal/{$this->slug($company)}/code", ['email' => $email])->assertOk();
        $code = null;
        Notification::assertSentTo(Customer::withoutGlobalScope('company')->where('email', $email)->latest('id')->first(), PortalCode::class, function (PortalCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function portalHeaders(): array
    {
        $code = $this->requestCode();
        $token = $this->postJson("/api/portal/{$this->slug()}/login", ['email' => 'ANA@cliente.com ', 'code' => $code])->assertOk()->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function staffHeaders(array $permissions): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function income(Customer $customer, array $overrides = []): Transaction
    {
        $category = Category::firstOrCreate(['name' => 'Serviços'], ['type' => 'income']);

        return Transaction::create(['type' => 'income', 'amount' => '100.00', 'description' => 'Mensalidade', 'category_id' => $category->id,
            'customer_id' => $customer->id, 'date' => '2026-10-05', 'status' => 'pending', ...$overrides]);
    }

    public function test_login_checks_the_email_in_the_company_and_requires_the_emailed_code(): void
    {
        $this->getJson("/api/portal/{$this->slug()}")->assertOk()->assertJson(['name' => 'Empresa Padrão']);
        $this->getJson('/api/portal/nao-existe-99')->assertNotFound();

        $this->postJson("/api/portal/{$this->slug()}/code", ['email' => 'ninguem@x.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $code = $this->requestCode();
        $this->postJson("/api/portal/{$this->slug()}/code", ['email' => 'ana@cliente.com'])->assertJsonPath('sent_to', 'an•@cliente.com');
        $this->postJson("/api/portal/{$this->slug()}/login", ['email' => 'ana@cliente.com', 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code'); // superseded by the 2nd code

        $headers = $this->portalHeaders();
        $this->getJson('/api/portal/me', $headers)->assertOk()
            ->assertJsonPath('customer.name', 'Ana')
            ->assertJsonPath('company.slug', $this->slug())
            ->assertJsonMissingPath('customer.portal_token');

        // Session stored hashed; a code works once.
        $plain = substr($headers['Authorization'], 7);
        $this->assertSame(hash('sha256', $plain), DB::table('customers')->where('id', $this->customer->id)->value('portal_token'));

        $this->postJson('/api/portal/logout', [], $headers)->assertOk();
        $this->getJson('/api/portal/me', $headers)->assertUnauthorized();
    }

    public function test_wrong_codes_are_limited(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // the code's own attempt limit is under test, not the IP's
        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $_) {
            $this->postJson("/api/portal/{$this->slug()}/login", ['email' => 'ana@cliente.com', 'code' => $wrong])->assertUnprocessable();
        }
        $this->postJson("/api/portal/{$this->slug()}/login", ['email' => 'ana@cliente.com', 'code' => $wrong])
            ->assertJsonPath('errors.code.0', 'Muitas tentativas erradas. Peça um novo código.');
        // Burned: even the right code no longer works.
        $this->postJson("/api/portal/{$this->slug()}/login", ['email' => 'ana@cliente.com', 'code' => $code])->assertUnprocessable();
    }

    public function test_a_customer_only_enters_the_portal_of_their_own_company(): void
    {
        $other = Company::create(['name' => 'Outra Empresa']);
        $other->regenerateToken();

        $this->postJson("/api/portal/{$this->slug($other)}/code", ['email' => 'ana@cliente.com'])->assertUnprocessable();

        Customer::factory()->create(['email' => 'inativo@x.com', 'active' => false]);
        $this->postJson("/api/portal/{$this->slug()}/code", ['email' => 'inativo@x.com'])->assertUnprocessable();

        // Inactive license: the portal address disappears and open sessions are blocked.
        $headers = $this->portalHeaders();
        $this->company->revokeToken();
        $this->getJson("/api/portal/{$this->slug()}")->assertNotFound();
        $this->getJson('/api/portal/me', $headers)->assertForbidden()->assertJson(['code' => 'COMPANY_INACTIVE']);
    }

    public function test_the_customer_sees_only_their_own_projects_bills_and_announcements(): void
    {
        $bob = Customer::factory()->create(['email' => 'bob@cliente.com']);
        $mine = Project::create(['customer_id' => $this->customer->id, 'name' => 'Site novo', 'status' => 'in_progress']);
        $mine->steps()->createMany([['name' => 'Briefing', 'status' => 'done'], ['name' => 'Layout', 'status' => 'in_progress']]);
        $theirs = Project::create(['customer_id' => $bob->id, 'name' => 'App do Bob']);

        Announcement::create(['title' => 'Feriado', 'body' => 'Fechados dia 2.']);
        Announcement::create(['title' => 'Layout pronto', 'body' => 'Veja a prévia.', 'project_id' => $mine->id]);
        Announcement::create(['title' => 'Do Bob', 'body' => 'x', 'project_id' => $theirs->id]);

        $this->income($this->customer, ['date' => '2020-01-10']); // overdue
        $this->income($this->customer, ['amount' => '50.25', 'date' => '2099-01-10']);
        $this->income($this->customer, ['status' => 'paid', 'description' => 'Paga']);
        $this->income($bob, ['description' => 'Do Bob']);

        $headers = $this->portalHeaders();

        $this->getJson('/api/portal/projects', $headers)->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Site novo')->assertJsonPath('0.progress', 50)->assertJsonPath('0.steps.0.name', 'Briefing');
        $this->getJson("/api/portal/projects/{$mine->id}", $headers)->assertOk()->assertJsonPath('announcements.0.title', 'Layout pronto');
        $this->getJson("/api/portal/projects/{$theirs->id}", $headers)->assertNotFound();

        $titles = collect($this->getJson('/api/portal/announcements', $headers)->assertOk()->json())->pluck('title')->sort()->values()->all();
        $this->assertSame(['Feriado', 'Layout pronto'], $titles);

        $this->getJson('/api/portal/bills', $headers)->assertOk()
            ->assertJsonPath('total_pending', '150.25')
            ->assertJsonCount(2, 'pending')
            ->assertJsonPath('pending.0.overdue', true)
            ->assertJsonPath('pending.1.overdue', false)
            ->assertJsonPath('paid.0.description', 'Paga');

        // A staff token isn't a portal session, and the portal has no write routes.
        $this->getJson('/api/portal/me', $this->staffHeaders([]))->assertUnauthorized();
        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('portal', $route->gatherMiddleware(), true))
            ->flatMap->methods()->unique()->diff(['GET', 'HEAD'])->values()->all();
        $this->assertSame(['POST'], $writes); // logout only
    }

    public function test_staff_manage_projects_with_steps_and_announcements(): void
    {
        $headers = $this->staffHeaders(['projects.view', 'projects.create', 'projects.update', 'projects.delete',
            'announcements.view', 'announcements.create', 'customers.view']);
        $foreign = Company::as(Company::create(['name' => 'Outra'])->id, fn () => Customer::factory()->create());

        $this->postJson('/api/projects', ['customer_id' => $foreign->id, 'name' => 'X'], $headers)->assertJsonValidationErrors('customer_id');

        $id = $this->postJson('/api/projects', [
            'customer_id' => $this->customer->id, 'name' => 'Site novo', 'start_date' => '2026-10-01',
            'steps' => [['name' => 'Briefing', 'status' => 'done'], ['name' => 'Layout', 'status' => 'pending', 'due_date' => '2026-11-01']],
        ], $headers)->assertCreated()->assertJsonPath('progress', 50)->assertJsonPath('steps.1.name', 'Layout')->json('id');

        $this->putJson("/api/projects/{$id}", ['steps' => [['name' => 'Layout', 'status' => 'done']]], $headers)
            ->assertOk()->assertJsonCount(1, 'steps')->assertJsonPath('progress', 100);
        $this->putJson("/api/projects/{$id}", ['start_date' => '2026-10-01', 'due_date' => '2026-09-01'], $headers)->assertJsonValidationErrors('due_date');

        $this->postJson('/api/announcements', ['title' => 'Novidade', 'body' => 'Texto', 'project_id' => $id], $headers)
            ->assertCreated()->assertJsonPath('project.name', 'Site novo');
        $this->getJson('/api/customers/options', $headers)->assertOk()->assertJsonPath('0.value', $this->customer->id);

        $this->deleteJson("/api/projects/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $id]);
    }

    public function test_income_links_to_a_customer_of_the_company_only(): void
    {
        $headers = $this->staffHeaders(['finance.transaction.create']);
        $category = Category::create(['name' => 'Serviços', 'type' => 'income']);
        $foreign = Company::as(Company::create(['name' => 'Outra'])->id, fn () => Customer::factory()->create());
        $payload = ['type' => 'income', 'amount' => '10', 'description' => 'X', 'category_id' => $category->id, 'date' => '2026-10-05'];

        $this->postJson('/api/finance/transactions', [...$payload, 'customer_id' => $foreign->id], $headers)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/finance/transactions', [...$payload, 'customer_id' => $this->customer->id], $headers)
            ->assertCreated()->assertJsonPath('customer_id', $this->customer->id);
    }
}
