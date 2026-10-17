<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\TelegramAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_api_token' => self::TOKEN, 'services.telegram.activation_code' => 'AVT-123']);
    }

    private function bot(int $telegramUserId = 42, string $token = self::TOKEN): array
    {
        return ['Authorization' => "Bearer {$token}", 'X-Telegram-User-Id' => (string) $telegramUserId];
    }

    private function activate(int $telegramUserId = 42): void
    {
        $this->postJson('/api/bot/activate', ['code' => 'AVT-123', 'username' => 'igor'], $this->bot($telegramUserId))->assertOk();
    }

    private function payload(int $categoryId, array $overrides = []): array
    {
        return ['type' => 'expense', 'amount' => '150.50', 'description' => 'Supermercado', 'category_id' => $categoryId,
            'date' => '2026-10-07', 'idempotency_key' => '1001', ...$overrides];
    }

    public function test_bot_token_is_required_and_fails_closed(): void
    {
        $this->getJson('/api/bot/me', $this->bot(token: 'wrong'))->assertUnauthorized()->assertJson(['code' => 'INVALID_BOT_TOKEN']);
        $this->getJson('/api/bot/me', ['X-Telegram-User-Id' => '42'])->assertUnauthorized();

        config(['services.telegram.bot_api_token' => '']);
        $this->getJson('/api/bot/me', $this->bot(token: ''))->assertUnauthorized();
    }

    public function test_bot_token_does_not_open_user_routes(): void
    {
        $this->getJson('/api/finance/transactions', $this->bot())->assertUnauthorized();
    }

    public function test_activation_flow(): void
    {
        $this->getJson('/api/bot/me', $this->bot())->assertForbidden()->assertJson(['code' => 'BOT_NOT_ACTIVATED']);
        $this->getJson('/api/bot/me', ['Authorization' => 'Bearer '.self::TOKEN, 'X-Telegram-User-Id' => 'abc'])->assertUnprocessable();

        $this->postJson('/api/bot/activate', ['code' => 'WRONG'], $this->bot())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/bot/activate', ['code' => ' AVT-123 ', 'username' => 'igor'], $this->bot())->assertOk();

        $this->getJson('/api/bot/me', $this->bot())->assertOk()->assertJson(['telegram_user_id' => 42, 'username' => 'igor']);
        // Activation is per Telegram user.
        $this->getJson('/api/bot/me', $this->bot(7))->assertForbidden();
    }

    public function test_bot_works_inside_the_account_company_only(): void
    {
        $this->activate();
        $this->assertSame(Company::DEFAULT_ID, TelegramAccount::first()->company_id);

        $mine = Category::create(['name' => 'Mercado', 'type' => 'expense']);
        $other = Company::create(['name' => 'Outra']);
        $foreign = Company::as($other->id, fn () => Category::create(['name' => 'Mercado', 'type' => 'expense']));

        $this->getJson('/api/bot/finance/categories?type=expense', $this->bot())->assertOk()->assertJsonCount(1)->assertJsonPath('0.value', $mine->id);
        $this->postJson('/api/bot/finance/transactions', $this->payload($foreign->id), $this->bot())
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');

        Company::find(Company::DEFAULT_ID)->revokeToken();
        $this->getJson('/api/bot/me', $this->bot())->assertForbidden()->assertJson(['code' => 'COMPANY_INACTIVE']);
    }

    public function test_revoked_account_is_blocked_even_with_the_code(): void
    {
        $this->activate();
        TelegramAccount::where('telegram_user_id', 42)->update(['revoked_at' => now()]);

        $this->getJson('/api/bot/me', $this->bot())->assertForbidden();
        $this->postJson('/api/bot/activate', ['code' => 'AVT-123'], $this->bot())->assertUnprocessable();
    }

    public function test_activation_attempts_are_rate_limited_per_telegram_user(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/bot/activate', ['code' => 'nope'], $this->bot())->assertUnprocessable();
        }
        $this->postJson('/api/bot/activate', ['code' => 'AVT-123'], $this->bot())->assertTooManyRequests();
        $this->postJson('/api/bot/activate', ['code' => 'AVT-123'], $this->bot(7))->assertOk();
    }

    public function test_categories_come_from_the_backend_filtered_by_type(): void
    {
        $this->activate();
        $salary = Category::create(['name' => 'Salário', 'type' => 'income']);
        $market = Category::create(['name' => 'Mercado', 'type' => 'expense']);
        $other = Category::create(['name' => 'Outros', 'type' => 'both']);
        Category::create(['name' => 'Velha', 'type' => 'expense', 'active' => false]);

        $ids = fn (string $type) => collect($this->getJson("/api/bot/finance/categories?type={$type}", $this->bot())->assertOk()->json())->pluck('value')->sort()->values()->all();

        $this->assertSame([$market->id, $other->id], $ids('expense'));
        $this->assertSame([$salary->id, $other->id], $ids('income'));
        $this->getJson('/api/bot/finance/categories', $this->bot())->assertUnprocessable();
    }

    public function test_creates_telegram_transaction_idempotently_and_audits_the_account(): void
    {
        $this->activate();
        $category = Category::create(['name' => 'Mercado', 'type' => 'expense']);

        $id = $this->postJson('/api/bot/finance/transactions', $this->payload($category->id), $this->bot())
            ->assertCreated()->assertJson(['source' => 'telegram', 'amount' => '150.50', 'status' => 'paid'])->json('id');

        // Telegram retry / double tap: same key → same transaction, nothing new.
        $this->postJson('/api/bot/finance/transactions', $this->payload($category->id), $this->bot())->assertOk()->assertJson(['id' => $id]);
        $this->assertSame(1, Transaction::count());

        // Same key from another Telegram user is a different operation.
        $this->activate(7);
        $this->postJson('/api/bot/finance/transactions', $this->payload($category->id), $this->bot(7))->assertCreated();
        $this->assertSame(2, Transaction::count());

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $id, 'event' => 'created', 'causer_type' => TelegramAccount::class,
        ]);
    }

    public function test_bot_cannot_set_status_and_is_validated_like_the_web(): void
    {
        $this->activate();
        $income = Category::create(['name' => 'Salário', 'type' => 'income']);

        $this->postJson('/api/bot/finance/transactions', $this->payload($income->id), $this->bot())
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/bot/finance/transactions', $this->payload($income->id, ['amount' => '1,50', 'idempotency_key' => null]), $this->bot())
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'idempotency_key']);

        $id = $this->postJson('/api/bot/finance/transactions', $this->payload($income->id, ['type' => 'income', 'status' => 'pending']), $this->bot())
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('finance_transactions', ['id' => $id, 'status' => 'paid']);
    }

    public function test_transaction_creation_is_capped_per_telegram_user(): void
    {
        config(['rate-limit.bot-transactions.max_attempts' => 2]);
        $this->refreshApplicationRateLimiters();
        $this->activate();
        $category = Category::create(['name' => 'Mercado', 'type' => 'expense']);

        $this->postJson('/api/bot/finance/transactions', $this->payload($category->id, ['idempotency_key' => 'a']), $this->bot())->assertCreated();
        $this->postJson('/api/bot/finance/transactions', $this->payload($category->id, ['idempotency_key' => 'b']), $this->bot())->assertCreated();
        $this->postJson('/api/bot/finance/transactions', $this->payload($category->id, ['idempotency_key' => 'c']), $this->bot())->assertTooManyRequests();
    }

    public function test_summary_is_the_balancete_and_defaults_to_all_time(): void
    {
        $this->getJson('/api/bot/finance/summary?to=2026-10-31', $this->bot())->assertForbidden();
        $this->activate();
        $salary = Category::create(['name' => 'Salário', 'type' => 'income']);
        $market = Category::create(['name' => 'Mercado', 'type' => 'expense']);
        foreach ([['income', '1000.00', $salary, '2025-03-10'], ['expense', '100.00', $market, '2026-10-01'], ['expense', '50.50', $market, '2026-10-02']] as [$type, $amount, $category, $date]) {
            Transaction::create(['type' => $type, 'amount' => $amount, 'description' => 'x', 'category_id' => $category->id, 'date' => $date]);
        }

        $all = $this->getJson('/api/bot/finance/summary?to=2026-10-31', $this->bot())->assertOk()->json();
        $this->assertSame('2025-03-10', $all['period']['from']);
        $this->assertEquals(['income' => 1000, 'expense' => 150.5, 'balance' => 849.5], $all['totals']);
        $this->assertSame(['income' => 1, 'expense' => 2], $all['counts']);
        $this->assertSame(2, $all['categories']['expense'][0]['count']);

        $october = $this->getJson('/api/bot/finance/summary?from=2026-10-01&to=2026-10-31', $this->bot())->assertOk()->json();
        $this->assertEquals(['income' => 0, 'expense' => 150.5, 'balance' => -150.5], $october['totals']);
        $this->assertEquals(1000, $october['opening_balance']);

        $this->getJson('/api/bot/finance/summary', $this->bot())->assertUnprocessable();
        $this->getJson('/api/bot/finance/summary?from=2026-11-01&to=2026-10-01', $this->bot())->assertUnprocessable();
    }

    public function test_unactivated_user_cannot_create(): void
    {
        $category = Category::create(['name' => 'Mercado', 'type' => 'expense']);

        $this->postJson('/api/bot/finance/transactions', $this->payload($category->id), $this->bot())->assertForbidden();
        $this->assertSame(0, Transaction::count());
    }

    private function refreshApplicationRateLimiters(): void
    {
        (new \App\Providers\AppServiceProvider($this->app))->boot();
    }
}
