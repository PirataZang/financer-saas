<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
        Date::setTestNow('2026-10-21 12:00:00');
    }

    private function summary(): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->syncPermissions(['dashboard.view']);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return $this->getJson('/api/dashboard/summary', ['Authorization' => "Bearer {$token}"])->assertOk()->json();
    }

    private function bill(Customer $customer, string $date, string $status = 'pending'): void
    {
        $category = Category::firstOrCreate(['name' => 'Serviços'], ['type' => 'income']);
        Transaction::create(['type' => 'income', 'amount' => '100.00', 'description' => 'Mensalidade', 'category_id' => $category->id,
            'customer_id' => $customer->id, 'date' => $date, 'status' => $status]);
    }

    public function test_customers_are_late_when_they_have_a_pending_bill_past_due(): void
    {
        $late = Customer::factory()->create(['status' => 'customer']);
        $onTime = Customer::factory()->create(['status' => 'customer']);
        $cancelled = Customer::factory()->create(['status' => 'cancelled']);
        $inactive = Customer::factory()->create(['active' => false]);

        $this->bill($late, '2026-10-05');
        $this->bill($late, '2026-09-05'); // two bills, one customer
        $this->bill($onTime, '2026-10-05', 'paid');
        $this->bill($onTime, '2026-10-30'); // pending but not due yet
        $this->bill($cancelled, '2026-10-05');
        $this->bill($inactive, '2026-10-05');

        $this->assertSame(['late' => 1, 'on_time' => 1], $this->summary()['customers']);
    }

    public function test_projects_split_open_ones_by_due_date_and_chart_the_delay(): void
    {
        $customer = Customer::factory()->create();
        $project = fn (string $status, ?string $due) => Project::create(['customer_id' => $customer->id, 'name' => 'P', 'status' => $status, 'due_date' => $due]);

        $project('in_progress', '2026-10-15'); // 6 days late
        $project('paused', '2026-09-01');      // 50 days late
        $project('planning', '2026-08-01');    // 81 days late
        $project('in_progress', '2025-01-01'); // way late
        $project('in_progress', '2026-10-21'); // due today = still on time
        $project('planning', null);            // no date = on time
        $project('done', '2026-01-01');        // finished: never late
        $project('cancelled', '2026-01-01');

        $summary = $this->summary();

        $this->assertSame(['late' => 4, 'on_time' => 2], $summary['projects']);
        $this->assertSame([1, 0, 2, 1], array_column($summary['projects_late'], 'value'));
    }

    public function test_monthly_series_cover_twelve_zero_filled_months(): void
    {
        Customer::factory()->create(['created_at' => '2026-10-02']);
        Customer::factory()->create(['created_at' => '2026-10-20']);
        Customer::factory()->create(['created_at' => '2025-11-30']);
        Customer::factory()->create(['created_at' => '2025-10-31']); // before the window

        $customer = Customer::factory()->create();
        Project::create(['customer_id' => $customer->id, 'name' => 'Feito', 'status' => 'done']);
        Project::create(['customer_id' => $customer->id, 'name' => 'Aberto', 'status' => 'in_progress']);

        $summary = $this->summary();

        $joined = array_column($summary['customers_joined'], 'value', 'month');
        $this->assertCount(12, $joined);
        $this->assertSame('2025-11', array_key_first($joined));
        $this->assertSame('2026-10', array_key_last($joined));
        $this->assertSame(1, $joined['2025-11']);
        $this->assertSame(0, $joined['2026-03']);
        $this->assertSame(3, $joined['2026-10']); // 2 + the project's customer

        $this->assertSame(1, array_column($summary['projects_done'], 'value', 'month')['2026-10']);
    }

    public function test_customers_by_status_lists_every_status_and_stays_in_the_company(): void
    {
        Customer::factory()->count(2)->create(['status' => 'lead']);
        Customer::factory()->create(['status' => 'customer']);
        Company::as(Company::create(['name' => 'Outra'])->id, fn () => Customer::factory()->count(5)->create(['status' => 'lead']));

        $byStatus = array_column($this->summary()['customers_by_status'], 'value', 'key');

        $this->assertSame(['lead' => 2, 'warming_up' => 0, 'customer' => 1, 'cancelled' => 0], $byStatus);
    }

    public function test_attention_lists_due_soon_projects_stale_leads_and_customers_without_project(): void
    {
        $withProject = Customer::factory()->create(['status' => 'customer', 'name' => 'Com projeto']);
        $project = fn (string $status, ?string $due, string $name) => Project::create(['customer_id' => $withProject->id, 'name' => $name, 'status' => $status, 'due_date' => $due]);

        $project('in_progress', '2026-10-28', 'Em 7 dias');
        $project('planning', '2026-10-21', 'Hoje');
        $project('in_progress', '2026-10-29', 'Em 8 dias');   // outside the window
        $project('in_progress', '2026-10-20', 'Ontem');       // late, not "due soon"
        $project('done', '2026-10-25', 'Concluído');

        Customer::factory()->create(['status' => 'lead', 'name' => 'Lead velho', 'updated_at' => '2026-09-01']);
        Customer::factory()->create(['status' => 'warming_up', 'name' => 'Morno velho', 'updated_at' => '2026-09-20']);
        Customer::factory()->create(['status' => 'lead', 'name' => 'Lead novo', 'updated_at' => '2026-10-10']);
        Customer::factory()->create(['status' => 'customer', 'name' => 'Cliente velho', 'updated_at' => '2026-01-01']); // not a lead
        Customer::factory()->create(['status' => 'lead', 'name' => 'Inativo', 'active' => false, 'updated_at' => '2026-01-01']);

        Customer::factory()->create(['status' => 'customer', 'name' => 'Sem projeto']);

        $attention = $this->summary()['attention'];

        $this->assertSame(['Hoje', 'Em 7 dias'], array_column($attention['projects_due_soon']['items'], 'name'));
        $this->assertSame([0, 7], array_column($attention['projects_due_soon']['items'], 'days'));
        $this->assertSame('Com projeto', $attention['projects_due_soon']['items'][0]['customer']);

        $this->assertSame(2, $attention['stale_leads']['total']);
        $this->assertSame(['Lead velho', 'Morno velho'], array_column($attention['stale_leads']['items'], 'name'));
        $this->assertSame(50, $attention['stale_leads']['items'][0]['days']);

        $this->assertSame(['Cliente velho', 'Sem projeto'], array_column($attention['customers_without_project']['items'], 'name')); // 'Com projeto' is out
    }

    public function test_attention_lists_show_five_rows_but_count_them_all(): void
    {
        Customer::factory()->count(7)->create(['status' => 'customer']);

        $list = $this->summary()['attention']['customers_without_project'];

        $this->assertSame(7, $list['total']);
        $this->assertCount(5, $list['items']);
    }
}
