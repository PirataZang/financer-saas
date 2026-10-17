<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'finance.dashboard', 'finance.export',
        'finance.category.view', 'finance.category.create', 'finance.category.update', 'finance.category.delete',
        'finance.transaction.view', 'finance.transaction.create', 'finance.transaction.update', 'finance.transaction.delete',
    ];

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

    private function category(array $overrides = []): Category
    {
        return Category::create(['name' => 'Mercado '.uniqid(), 'icon' => '🛒', 'color' => '#d97706', 'type' => 'expense', ...$overrides]);
    }

    private function payload(array $overrides = []): array
    {
        return ['type' => 'expense', 'amount' => '150.50', 'description' => 'Compra do mês', 'date' => '2026-10-05', ...$overrides];
    }

    public function test_category_crud(): void
    {
        $headers = $this->headers();

        $id = $this->postJson('/api/finance/categories', ['name' => 'Pets', 'icon' => '🐶', 'color' => '#2563eb', 'type' => 'expense'], $headers)
            ->assertCreated()->assertJson(['name' => 'Pets', 'active' => true, 'type' => 'expense'])->json('id');

        $this->putJson("/api/finance/categories/{$id}", ['active' => false], $headers)->assertOk()->assertJson(['active' => false, 'name' => 'Pets']);
        $this->getJson('/api/finance/categories?active=0', $headers)->assertOk()->assertJsonPath('0.name', 'Pets');

        $this->postJson('/api/finance/categories', ['name' => 'Pets'], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
        // Unique per company: another company's name neither blocks nor shows up.
        Company::as(Company::create(['name' => 'Outra'])->id, fn () => Category::create(['name' => 'Animais', 'type' => 'expense']));
        $this->postJson('/api/finance/categories', ['name' => 'Animais'], $headers)->assertCreated();
        $this->postJson('/api/finance/categories', ['name' => 'X1', 'color' => 'red', 'type' => 'transfer'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['color', 'type']);

        $this->deleteJson("/api/finance/categories/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('finance_categories', ['id' => $id]);
        // Deleted name can be reused.
        $this->postJson('/api/finance/categories', ['name' => 'Pets'], $headers)->assertCreated();
    }

    public function test_transaction_crud_is_manual_and_keeps_money_exact(): void
    {
        $headers = $this->headers();
        $category = $this->category();

        $tx = $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $category->id]), $headers)
            ->assertCreated()
            ->assertJson(['amount' => '150.50', 'source' => 'manual', 'status' => 'paid', 'date' => '2026-10-05', 'category' => ['name' => $category->name]])
            ->json();

        $this->putJson("/api/finance/transactions/{$tx['id']}", ['amount' => '0.30', 'status' => 'pending'], $headers)
            ->assertOk()->assertJson(['amount' => '0.30', 'status' => 'pending', 'description' => 'Compra do mês']);

        // A client can't choose the source or an external id.
        $this->putJson("/api/finance/transactions/{$tx['id']}", ['source' => 'open_finance', 'external_id' => 'x'], $headers)->assertOk();
        $this->assertDatabaseHas('finance_transactions', ['id' => $tx['id'], 'source' => 'manual', 'external_id' => null]);

        $this->deleteJson("/api/finance/transactions/{$tx['id']}", [], $headers)->assertOk();
        $this->assertSoftDeleted('finance_transactions', ['id' => $tx['id']]);
    }

    public function test_invalid_amounts_and_fields_are_rejected(): void
    {
        $headers = $this->headers();
        $category = $this->category();

        foreach (['0', '-5', '10.999', 'abc', '1e3', '99999999999999'] as $amount) {
            $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $category->id, 'amount' => $amount]), $headers)
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }

        $this->postJson('/api/finance/transactions', ['type' => 'transfer', 'date' => '05/10/2026'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'amount', 'description', 'category_id', 'date']);

        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_category_must_be_active_and_accept_the_type(): void
    {
        $headers = $this->headers();
        $income = $this->category(['type' => 'income']);
        $inactive = $this->category(['active' => false]);
        $both = $this->category(['type' => 'both']);

        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $income->id]), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $inactive->id]), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => 999]), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $id = $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $both->id]), $headers)->assertCreated()->json('id');

        // Deactivating a category later doesn't lock its existing transactions.
        $both->update(['active' => false]);
        $this->putJson("/api/finance/transactions/{$id}", ['description' => 'Editada'], $headers)->assertOk();
        $this->putJson("/api/finance/transactions/{$id}", ['type' => 'income'], $headers)->assertUnprocessable();
    }

    public function test_list_filters(): void
    {
        $headers = $this->headers();
        $food = $this->category(['type' => 'both']);
        $other = $this->category(['type' => 'both']);
        Transaction::create(['type' => 'expense', 'amount' => '10.00', 'description' => 'Padaria', 'category_id' => $food->id, 'date' => '2026-09-01']);
        Transaction::create(['type' => 'income', 'amount' => '500.00', 'description' => 'Freela', 'category_id' => $other->id, 'date' => '2026-10-01', 'source' => 'telegram']);
        Transaction::create(['type' => 'expense', 'amount' => '80.00', 'description' => 'Mercado', 'category_id' => $food->id, 'date' => '2026-10-02']);

        $descriptions = fn (string $query) => collect($this->getJson("/api/finance/transactions?{$query}", $headers)->assertOk()->json('data'))->pluck('description')->all();

        $this->assertSame(['Mercado', 'Freela', 'Padaria'], $descriptions(''));
        $this->assertSame(['Mercado', 'Freela'], $descriptions('from=2026-10-01&to=2026-10-31'));
        $this->assertSame(['Freela'], $descriptions('type=income'));
        $this->assertSame(['Freela'], $descriptions('source=telegram'));
        $this->assertSame(['Mercado', 'Padaria'], $descriptions("category_id={$food->id}"));
        $this->assertSame(['Mercado'], $descriptions('min_amount=50&max_amount=100'));
        $this->assertSame(['Padaria'], $descriptions('search=PADA'));
        $this->getJson('/api/finance/transactions?from=2026-10-05&to=2026-10-01', $headers)->assertUnprocessable();
    }

    public function test_list_totals_cover_every_filtered_row_not_just_the_page(): void
    {
        $headers = $this->headers();
        $category = $this->category(['type' => 'both']);
        Transaction::create(['type' => 'income', 'amount' => '1000.00', 'description' => 'Salário', 'category_id' => $category->id, 'date' => '2026-10-01']);
        Transaction::create(['type' => 'expense', 'amount' => '300.10', 'description' => 'Aluguel', 'category_id' => $category->id, 'date' => '2026-10-02', 'status' => 'pending']);
        Transaction::create(['type' => 'expense', 'amount' => '50.00', 'description' => 'Antiga', 'category_id' => $category->id, 'date' => '2026-09-01']);

        $this->getJson('/api/finance/transactions?from=2026-10-01&to=2026-10-31&per_page=1', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('totals', ['income' => 1000, 'expense' => 300.1, 'balance' => 699.9, 'pending' => 1]);
    }

    public function test_installments_split_the_total_and_future_ones_are_pending(): void
    {
        $this->travelTo('2026-10-15');
        $headers = $this->headers();
        $category = $this->category();

        $this->postJson('/api/finance/transactions', $this->payload([
            'category_id' => $category->id, 'amount' => '100.00', 'date' => '2026-01-31', 'repeat' => 'installments', 'repeat_count' => 3,
        ]), $headers)->assertCreated()->assertJson(['description' => 'Compra do mês (1/3)', 'amount' => '33.34']);

        $this->assertSame(
            [
                ['Compra do mês (1/3)', '33.34', '2026-01-31', 'paid'],
                ['Compra do mês (2/3)', '33.33', '2026-02-28', 'paid'],
                ['Compra do mês (3/3)', '33.33', '2026-03-31', 'paid'],
            ],
            Transaction::orderBy('date')->get()->map(fn ($t) => [$t->description, $t->amount, $t->date, $t->status->value])->all(),
        );

        // Monthly: same amount each month; only what's after today is pending.
        $this->postJson('/api/finance/transactions', $this->payload([
            'category_id' => $category->id, 'amount' => '1200.00', 'description' => 'Aluguel', 'date' => '2026-10-10', 'repeat' => 'monthly', 'repeat_count' => 3,
        ]), $headers)->assertCreated();

        $this->assertSame(
            [['1200.00', '2026-10-10', 'paid'], ['1200.00', '2026-11-10', 'pending'], ['1200.00', '2026-12-10', 'pending']],
            Transaction::where('description', 'Aluguel')->orderBy('date')->get()->map(fn ($t) => [$t->amount, $t->date, $t->status->value])->all(),
        );
    }

    public function test_series_is_all_or_nothing_and_validated(): void
    {
        $headers = $this->headers();
        $category = $this->category();

        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $category->id, 'amount' => '0.02', 'repeat' => 'installments', 'repeat_count' => 3]), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_count');
        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $category->id, 'repeat' => 'weekly', 'repeat_count' => 200]), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['repeat', 'repeat_count']);
        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $category->id, 'repeat' => 'monthly']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_count');
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_bulk_status_change(): void
    {
        $headers = $this->headers();
        $category = $this->category();
        $pending = fn (string $description) => Transaction::create($this->payload(['description' => $description, 'category_id' => $category->id, 'status' => 'pending']))->id;
        $ids = [$pending('A'), $pending('B')];
        $paid = Transaction::create($this->payload(['category_id' => $category->id]))->id;

        $this->patchJson('/api/finance/transactions/status', ['ids' => [...$ids, $paid], 'status' => 'paid'], $headers)
            ->assertOk()->assertJson(['updated' => 2]);
        $this->assertSame(0, Transaction::where('status', 'pending')->count());
        $this->getJson("/api/activity-log?subject_type=transaction&subject_id={$ids[0]}", $headers)->assertOk()->assertJsonPath('0.event', 'updated');

        $this->patchJson('/api/finance/transactions/status', ['ids' => [], 'status' => 'done'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['ids', 'status']);
        $this->patchJson('/api/finance/transactions/status', ['ids' => $ids, 'status' => 'pending'], $this->headers(['finance.transaction.view']))
            ->assertForbidden();
    }

    public function test_dashboard_aggregates_paid_transactions_only(): void
    {
        $headers = $this->headers(['finance.dashboard']);
        $salary = $this->category(['name' => 'Salário', 'type' => 'income']);
        $market = $this->category(['name' => 'Mercado', 'type' => 'expense']);
        $tx = fn (string $type, string $amount, int $category, string $date, string $status = 'paid') => Transaction::create(
            ['type' => $type, 'amount' => $amount, 'description' => 'x', 'category_id' => $category, 'date' => $date, 'status' => $status],
        );

        $tx('income', '100.00', $salary->id, '2026-09-30');            // before the period: opening balance
        $tx('income', '1000.10', $salary->id, '2026-10-01');
        $tx('expense', '200.05', $market->id, '2026-10-01');
        $tx('expense', '50.00', $market->id, '2026-10-03');
        $tx('expense', '999.00', $market->id, '2026-10-02', 'pending'); // ignored

        $data = $this->getJson('/api/finance/dashboard?from=2026-10-01&to=2026-10-03', $headers)->assertOk()->json();

        $this->assertEquals(['income' => 1000.10, 'expense' => 250.05, 'balance' => 750.05], $data['totals']);
        $this->assertEquals(100, $data['opening_balance']);
        $this->assertEquals(850.05, $data['closing_balance']);
        $this->assertSame('day', $data['period']['granularity']);
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_column($data['timeline'], 'period'));
        $this->assertEquals([900.05, 900.05, 850.05], array_column($data['timeline'], 'balance'));
        $this->assertSame('Mercado', $data['categories']['expense'][0]['name']);
        $this->assertEquals(250.05, $data['categories']['expense'][0]['total']);

        $yearly = $this->getJson('/api/finance/dashboard?from=2026-01-01&to=2026-12-31', $headers)->assertOk()->json();
        $this->assertSame('month', $yearly['period']['granularity']);
        $this->assertCount(12, $yearly['timeline']);
        $this->assertSame(['2026-09', '2026-10'], [$yearly['timeline'][8]['period'], $yearly['timeline'][9]['period']]);
        $this->assertEquals([100, 850.05], [$yearly['timeline'][8]['balance'], $yearly['timeline'][9]['balance']]);
    }

    public function test_dashboard_filters_by_category_source_and_status(): void
    {
        $headers = $this->headers(['finance.dashboard']);
        $market = $this->category(['type' => 'expense']);
        $food = $this->category(['type' => 'expense']);
        $tx = fn (array $data) => Transaction::create(['type' => 'expense', 'description' => 'x', 'date' => '2026-10-02', ...$data]);
        $tx(['amount' => '10.00', 'category_id' => $market->id, 'date' => '2026-09-01']); // opening, market
        $tx(['amount' => '20.00', 'category_id' => $market->id]);
        $tx(['amount' => '30.00', 'category_id' => $food->id, 'source' => 'telegram']);
        $tx(['amount' => '40.00', 'category_id' => $food->id, 'status' => 'pending']);

        $expense = fn (string $query) => $this->getJson("/api/finance/dashboard?from=2026-10-01&to=2026-10-31&{$query}", $headers)
            ->assertOk()->json();

        $this->assertEquals(50, $expense('')['totals']['expense']); // paid only by default
        $this->assertEquals(90, $expense('status=all')['totals']['expense']);
        $this->assertEquals(40, $expense('status=pending')['totals']['expense']);
        $this->assertEquals(30, $expense('source=telegram')['totals']['expense']);
        $marketOnly = $expense("category_ids[]={$market->id}");
        $this->assertEquals([20, -10], [$marketOnly['totals']['expense'], $marketOnly['opening_balance']]);
        $this->assertEquals(70, $expense("status=all&category_ids[]={$food->id}")['totals']['expense']);
        $this->getJson('/api/finance/dashboard?status=lost', $headers)->assertUnprocessable();
    }

    public function test_export_is_an_xlsx_whose_summary_is_formulas_and_keeps_text_as_text(): void
    {
        $headers = $this->headers();
        $salary = $this->category(['name' => 'Salário', 'type' => 'income']);
        $market = $this->category(['name' => 'Mercado', 'type' => 'expense']);
        Transaction::create(['type' => 'income', 'amount' => '1000.00', 'description' => 'Pagamento', 'category_id' => $salary->id, 'date' => '2026-10-01']);
        Transaction::create(['type' => 'expense', 'amount' => '150.50', 'description' => '=HYPERLINK("http://evil","x")', 'category_id' => $market->id, 'date' => '2026-10-02']);

        $response = $this->get('/api/finance/dashboard/export?from=2026-10-01&to=2026-10-31', $headers)->assertOk();
        $this->assertStringContainsString('financeiro_2026-10-01_a_2026-10-31.xlsx', $response->headers->get('content-disposition'));

        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        foreach (['Resumo', 'Mensal', 'Transações', 'Categorias'] as $name) {
            $this->assertStringContainsString("name=\"{$name}\"", $workbook);
        }

        $summary = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString("<f>SUMIFS('Transações'!\$F:\$F,'Transações'!\$B:\$B,\"Receita\"", html_entity_decode($summary, ENT_QUOTES | ENT_XML1));
        $this->assertMatchesRegularExpression('/<f>SUMIFS\(/', $summary);
        $this->assertMatchesRegularExpression('/<f>B\d+-B\d+<\/f>/', $summary); // resultado líquido

        $transactions = $zip->getFromName('xl/worksheets/sheet3.xml');
        $this->assertStringNotContainsString('<f>HYPERLINK', $transactions);
        $this->assertStringContainsString('HYPERLINK', $transactions.$zip->getFromName('xl/sharedStrings.xml'));
        $zip->close();
    }

    public function test_export_requires_its_own_permission(): void
    {
        $this->get('/api/finance/dashboard/export', $this->headers(['finance.dashboard']))->assertForbidden();
    }

    public function test_each_operation_requires_its_permission(): void
    {
        $category = $this->category();
        $headers = $this->headers(['finance.transaction.view']);

        $this->getJson('/api/finance/transactions', $headers)->assertOk();
        $this->getJson('/api/finance/categories/options?type=expense', $headers)->assertOk()->assertJsonPath('0.value', $category->id);
        $this->postJson('/api/finance/transactions', $this->payload(['category_id' => $category->id]), $headers)->assertForbidden();
        $this->getJson('/api/finance/categories', $headers)->assertForbidden();
        $this->postJson('/api/finance/categories', ['name' => 'X'], $headers)->assertForbidden();
        $this->getJson('/api/finance/dashboard', $headers)->assertForbidden();
        $this->getJson('/api/finance/transactions')->assertUnauthorized();
    }

    public function test_changes_are_audited(): void
    {
        $headers = $this->headers();
        $category = $this->category();

        $this->putJson("/api/finance/categories/{$category->id}", ['color' => '#000000'], $headers)->assertOk();

        $this->getJson("/api/activity-log?subject_type=category&subject_id={$category->id}", $headers)
            ->assertOk()->assertJsonPath('0.event', 'updated');
    }
}
