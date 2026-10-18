<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Service\BotActivationService;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['settings.company', 'settings.appearance', 'settings.finance', 'settings.telegram'];

    private const BOT_TOKEN = 'test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
        config(['services.telegram.bot_api_token' => self::BOT_TOKEN, 'services.telegram.bot_username' => 'fin_bot']);
    }

    private function headers(array $permissions = self::ALL, int $companyId = Company::DEFAULT_ID): array
    {
        $user = Company::as($companyId, fn () => User::factory()->create(['password' => 'secret123']));
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function activate(string $code, int $telegramUserId = 42)
    {
        return $this->postJson('/api/bot/activate', ['code' => $code], ['Authorization' => 'Bearer '.self::BOT_TOKEN, 'X-Telegram-User-Id' => (string) $telegramUserId]);
    }

    public function test_each_tab_requires_its_permission_and_preferences_need_none(): void
    {
        $none = $this->headers([]);

        $this->getJson('/api/settings/company', $none)->assertForbidden();
        $this->putJson('/api/settings/appearance', ['primary_color' => '#059669'], $none)->assertForbidden();
        $this->putJson('/api/settings/finance', ['default_status' => 'pending'], $none)->assertForbidden();
        $this->getJson('/api/settings/telegram', $none)->assertForbidden();
        $this->putJson('/api/settings/preferences', ['table_density' => 'compact'], $none)->assertOk()->assertJson(['table_density' => 'compact']);
    }

    public function test_company_edits_its_own_data_but_never_the_license(): void
    {
        $other = Company::create(['name' => 'Outra']);
        $headers = $this->headers();

        $this->putJson('/api/settings/company', ['name' => 'Minha Empresa', 'expires_at' => '2000-01-01', 'token' => 'x', 'id' => $other->id], $headers)
            ->assertOk()->assertJson(['id' => Company::DEFAULT_ID, 'name' => 'Minha Empresa'])->assertJsonMissingPath('token');

        $company = Company::find(Company::DEFAULT_ID);
        $this->assertNull($company->expires_at);
        $this->assertTrue($company->isActive());
        $this->assertSame('Outra', $other->fresh()->name);
        $this->putJson('/api/settings/company', ['document' => '111.111.111-11'], $headers)->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_appearance_and_finance_reach_the_session_with_defaults(): void
    {
        $headers = $this->headers();

        $this->getJson('/api/auth/me', $headers)->assertOk()
            ->assertJsonPath('company.settings.appearance.primary_color', '#2563eb')
            ->assertJsonPath('company.settings.finance.default_period', 'month')
            ->assertJsonPath('preferences.table_density', 'comfortable')
            ->assertJsonMissingPath('company.telegram_code');

        $this->putJson('/api/settings/appearance', ['primary_color' => '#059669', 'brand_name' => 'Minhas Finanças'], $headers)->assertOk();
        $this->getJson('/api/settings/finance', $headers)->assertOk()->assertJsonPath('values.default_status', 'paid')->assertJsonCount(2, 'statuses');
        $this->putJson('/api/settings/finance', ['default_status' => 'pending', 'timezone' => 'America/Manaus'], $headers)->assertOk()
            ->assertJsonPath('finance.default_period', 'month');

        $this->getJson('/api/auth/me', $headers)
            ->assertJsonPath('company.settings.appearance.primary_color', '#059669')
            ->assertJsonPath('company.settings.appearance.brand_name', 'Minhas Finanças')
            ->assertJsonPath('company.settings.finance.default_status', 'pending')
            ->assertJsonPath('company.settings.finance.timezone', 'America/Manaus');

        $this->putJson('/api/settings/appearance', ['primary_color' => 'red'], $headers)->assertJsonValidationErrors('primary_color');
        $this->putJson('/api/settings/finance', ['default_period' => 'decade', 'timezone' => 'Mars/Base', 'default_status' => 'x'], $headers)
            ->assertJsonValidationErrors(['default_period', 'timezone', 'default_status']);
    }

    public function test_preferences_are_per_user_and_validated(): void
    {
        $mine = $this->headers([]);
        $theirs = $this->headers([]);

        $this->putJson('/api/settings/preferences', ['sidebar_expanded' => false, 'start_page' => '/finance/transactions'], $mine)->assertOk();
        $this->getJson('/api/auth/me', $mine)->assertJsonPath('preferences.sidebar_expanded', false)->assertJsonPath('preferences.start_page', '/finance/transactions');
        $this->getJson('/api/auth/me', $theirs)->assertJsonPath('preferences.sidebar_expanded', null);

        $this->putJson('/api/settings/preferences', ['start_page' => 'https://evil.example', 'table_density' => 'tiny'], $mine)
            ->assertJsonValidationErrors(['start_page', 'table_density']);
    }

    public function test_telegram_code_links_users_to_its_company(): void
    {
        $headers = $this->headers();

        $this->getJson('/api/settings/telegram', $headers)->assertOk()->assertJson(['code' => null, 'link' => null, 'accounts' => []]);
        $code = $this->postJson('/api/settings/telegram/code', [], $headers)->assertOk()->json('code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        $this->getJson('/api/settings/telegram', $headers)->assertJsonPath('link', "https://t.me/fin_bot?start={$code}");

        $this->activate($code)->assertOk();
        $this->assertSame(Company::DEFAULT_ID, TelegramAccount::withoutGlobalScope('company')->first()->company_id);

        // New code: the old one stops linking, the linked account keeps working.
        $newCode = $this->postJson('/api/settings/telegram/code', [], $headers)->json('code');
        $this->activate($code, 7)->assertUnprocessable();
        $this->activate($newCode, 7)->assertOk();
        $this->getJson('/api/settings/telegram', $headers)->assertJsonCount(2, 'accounts');

        $this->deleteJson('/api/settings/telegram/code', [], $headers)->assertOk()->assertJson(['code' => null]);
        $this->activate($newCode, 8)->assertUnprocessable();
    }

    public function test_another_company_code_links_there_and_accounts_stay_separate(): void
    {
        $other = Company::create(['name' => 'Outra']);
        $other->regenerateToken();
        $otherCode = app(BotActivationService::class)->regenerateCode($other);
        $mineCode = app(BotActivationService::class)->regenerateCode(Company::find(Company::DEFAULT_ID));
        $headers = $this->headers();

        $this->activate($otherCode, 99)->assertOk();
        $this->getJson('/api/settings/telegram', $headers)->assertJsonCount(0, 'accounts');
        $foreign = TelegramAccount::withoutGlobalScope('company')->first();
        $this->putJson("/api/settings/telegram/accounts/{$foreign->id}", ['revoked' => true], $headers)->assertNotFound();
        $this->deleteJson("/api/settings/telegram/accounts/{$foreign->id}", [], $headers)->assertNotFound();

        // Linked elsewhere: can't jump companies until that company removes the link.
        $this->activate($mineCode, 99)->assertUnprocessable();
        $this->assertSame($other->id, $foreign->fresh()->company_id);

        // Inactive company: its code doesn't link anyone.
        $other->revokeToken();
        $this->activate($otherCode, 100)->assertUnprocessable();
    }

    public function test_admin_revokes_reactivates_and_removes_linked_accounts(): void
    {
        $code = app(BotActivationService::class)->regenerateCode(Company::find(Company::DEFAULT_ID));
        $headers = $this->headers();
        $bot = ['Authorization' => 'Bearer '.self::BOT_TOKEN, 'X-Telegram-User-Id' => '42'];

        $this->activate($code)->assertOk();
        $id = TelegramAccount::first()->id;

        $this->putJson("/api/settings/telegram/accounts/{$id}", ['revoked' => true], $headers)->assertOk();
        $this->getJson('/api/bot/me', $bot)->assertForbidden();
        $this->activate($code)->assertUnprocessable();

        $this->putJson("/api/settings/telegram/accounts/{$id}", ['revoked' => false], $headers)->assertOk();
        $this->getJson('/api/bot/me', $bot)->assertOk();

        $this->deleteJson("/api/settings/telegram/accounts/{$id}", [], $headers)->assertOk();
        $this->getJson('/api/bot/me', $bot)->assertForbidden()->assertJson(['code' => 'BOT_NOT_ACTIVATED']);
    }
}
