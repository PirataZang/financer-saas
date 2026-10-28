<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Integration;
use App\Models\MercadoPagoPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const MP_USER = 777;

    /** The token the fake Mercado Pago accepts. */
    private const TOKEN = 'token-da-loja-aurora';

    /** What GET /v1/payments/search answers (each test sets it). */
    private array $payments = [];

    /** What GET /v1/account/release_report/list answers, and the CSV every listed file contains. */
    private array $reports = [];

    private bool $reportsDown = false;

    private string $reportCsv = "DATE;SOURCE_ID;DESCRIPTION;NET_CREDIT_AMOUNT;NET_DEBIT_AMOUNT\n";

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/users/me') => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                    ? Http::response(['id' => self::MP_USER, 'nickname' => 'LOJA_AURORA', 'email' => 'loja@aurora.test'])
                    : Http::response(['message' => 'invalid_token'], 401),
                str_contains($request->url(), '/release_report/list') => Http::response($this->reports, $this->reportsDown ? 500 : 200),
                str_contains($request->url(), '/release_report/') => Http::response($this->reportCsv),
                $request->method() === 'POST' && str_ends_with($request->url(), '/v1/account/release_report') => Http::response(['id' => 1], 202),
                str_contains($request->url(), '/v1/payments/search') => Http::response(['results' => $this->payments, 'paging' => ['total' => count($this->payments)]]),
                default => Http::response([], 404),
            };
        });
    }

    private function headers(array $permissions = ['settings.integrations', 'finance.dashboard', 'finance.category.delete']): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function payment(int $id, array $overrides = []): array
    {
        return [
            'id' => $id,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'payment_type_id' => 'bank_transfer',
            'payment_method_id' => 'pix',
            'description' => "Pedido {$id}",
            'transaction_amount' => 100.00,
            'transaction_amount_refunded' => 0,
            'fee_details' => [['type' => 'mercadopago_fee', 'amount' => 0.99, 'fee_payer' => 'collector']],
            'transaction_details' => ['net_received_amount' => 99.01],
            'installments' => 1,
            'collector_id' => self::MP_USER,
            'payer' => ['id' => 123],
            'date_created' => '2026-10-10T10:00:00.000-03:00',
            'date_approved' => '2026-10-10T10:01:00.000-03:00',
            'date_last_updated' => '2026-10-10T10:01:00.000-03:00',
            ...$overrides,
        ];
    }

    private function connect(array $headers): void
    {
        $this->postJson('/api/integrations/mercado_pago/connect', ['access_token' => self::TOKEN], $headers)
            ->assertOk()
            ->assertJson(['connected' => true, 'active' => true, 'account' => ['nickname' => 'LOJA_AURORA']]);
    }

    private function tx(string $externalId): ?Transaction
    {
        return Transaction::withTrashed()->where('source', 'mercado_pago')->where('external_id', $externalId)->first();
    }

    public function test_connecting_needs_permission_and_a_token_mercado_pago_accepts(): void
    {
        $headers = $this->headers();

        $this->getJson('/api/integrations', $headers)->assertOk()
            ->assertJsonPath('0.provider', 'mercado_pago')
            ->assertJsonPath('0.connected', false);
        $this->getJson('/api/integrations', $this->headers(['finance.dashboard']))->assertForbidden();
        $this->postJson('/api/integrations/mercado_pago/connect', [], $headers)->assertUnprocessable()->assertJsonValidationErrors('access_token');
        $this->postJson('/api/integrations/mercado_pago/connect', ['access_token' => 'token-errado'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('access_token');
        $this->assertSame(0, Integration::count());
    }

    public function test_connect_imports_payments_as_transactions_and_never_writes_to_mercado_pago(): void
    {
        $this->payments = [
            $this->payment(1),
            $this->payment(2, ['status' => 'pending', 'payment_type_id' => 'ticket', 'payment_method_id' => 'bolbradesco', 'fee_details' => [], 'date_approved' => null]),
            $this->payment(3, ['status' => 'refunded', 'payment_type_id' => 'credit_card', 'payment_method_id' => 'visa', 'transaction_amount_refunded' => 100]),
            $this->payment(4, ['status' => 'rejected', 'payment_type_id' => 'credit_card', 'payment_method_id' => 'master']),
            // The account paid someone else through Mercado Pago.
            $this->payment(5, ['collector_id' => 999, 'payer' => ['id' => self::MP_USER], 'transaction_amount' => 40, 'description' => 'Fornecedor']),
        ];
        $headers = $this->headers();
        $this->connect($headers);

        $this->assertEquals(['income', '100.00', 'paid'], [$this->tx('1')->type->value, $this->tx('1')->amount, $this->tx('1')->status->value]);
        $this->assertEquals(['expense', '0.99'], [$this->tx('1:fee')->type->value, $this->tx('1:fee')->amount]);
        $this->assertSame('pending', $this->tx('2')->status->value);
        $this->assertNull($this->tx('2:fee'));
        $this->assertEquals(['income', 'paid'], [$this->tx('3')->type->value, $this->tx('3')->status->value]);
        $this->assertEquals(['expense', '100.00'], [$this->tx('3:refund')->type->value, $this->tx('3:refund')->amount]);
        $this->assertNull($this->tx('3:fee'));
        $this->assertNull($this->tx('4'));
        $this->assertEquals(['expense', '40.00'], [$this->tx('5')->type->value, $this->tx('5')->amount]);
        $this->assertSame(5, MercadoPagoPayment::count());

        // Entries land in the integration's own categories.
        $this->assertSame('mercado_pago:sales', $this->tx('1')->category->integration_key);
        $this->assertSame('mercado_pago:fees', $this->tx('1:fee')->category->integration_key);
        $this->assertSame('mercado_pago:purchases', $this->tx('5')->category->integration_key);

        // Token encrypted at rest and never in a response.
        $raw = DB::table('integrations')->value('credentials');
        $this->assertStringNotContainsString(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, Integration::sole()->credentials['access_token']);
        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/integrations', $headers)->getContent());

        // Read-only towards the provider: GETs, plus asking for the movements report (a file, not money).
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && !str_ends_with($r->url(), '/v1/account/release_report'));
    }

    public function test_resync_follows_status_changes_and_keeps_user_edits(): void
    {
        $this->payments = [$this->payment(2, ['status' => 'pending', 'fee_details' => [], 'date_approved' => null])];
        $headers = $this->headers();
        $this->connect($headers);
        $this->tx('2')->update(['description' => 'Pedido da Ana']);

        // Approved later, then fully refunded.
        $this->payments = [$this->payment(2, ['date_last_updated' => '2026-10-11T09:00:00.000-03:00'])];
        $this->postJson('/api/integrations/mercado_pago/sync', [], $headers)->assertOk()->assertJsonPath('synced', 1);
        $this->assertEquals(['paid', 'Pedido da Ana'], [$this->tx('2')->status->value, $this->tx('2')->description]);
        $this->assertNotNull($this->tx('2:fee'));

        $this->payments = [$this->payment(2, ['status' => 'refunded', 'transaction_amount_refunded' => 100, 'date_last_updated' => '2026-10-12T09:00:00.000-03:00'])];
        $this->postJson('/api/integrations/mercado_pago/sync', [], $headers)->assertOk();
        $this->assertNull($this->tx('2:fee'));
        $this->assertSame('2026-10-12', $this->tx('2:refund')->date);

        // An older answer for the same payment changes nothing.
        $this->payments = [$this->payment(2, ['status' => 'approved'])];
        $this->postJson('/api/integrations/mercado_pago/sync', [], $headers)->assertOk();
        $this->assertNotNull($this->tx('2:refund'));
    }

    public function test_purchases_the_account_paid_as_buyer_are_outgoing(): void
    {
        // Mercado Pago hides collector and payer on what the account bought.
        $this->payments = [$this->payment(7, ['collector_id' => null, 'payer' => null, 'transaction_amount' => 29.9, 'fee_details' => [], 'description' => 'Sifão'])];
        $this->connect($this->headers());

        $this->assertEquals(['expense', '29.90'], [$this->tx('7')->type->value, $this->tx('7')->amount]);
        $this->assertSame('mercado_pago:purchases', $this->tx('7')->category->integration_key);
    }

    public function test_sent_pix_comes_from_the_release_report_and_only_once(): void
    {
        $this->reports = [['file_name' => 'release-1.csv', 'end_date' => '2026-10-27T23:00:00Z']];
        $this->reportCsv = "DATE;SOURCE_ID;DESCRIPTION;NET_CREDIT_AMOUNT;NET_DEBIT_AMOUNT\n"
            ."2026-10-25T17:19:34.000-03:00;150;payment;150.00;0.00\n"
            ."2026-10-26T19:56:31.000-03:00;186;reserve_for_payout;0.00;65.00\n"
            ."2026-10-26T19:56:32.000-03:00;186;payout;0.00;65.00\n";
        $headers = $this->headers();
        $this->connect($headers);

        $this->assertEquals(['expense', '65.00', 'paid', '2026-10-26'], [$this->tx('payout:186')->type->value, $this->tx('payout:186')->amount, $this->tx('payout:186')->status->value, $this->tx('payout:186')->date]);
        $this->assertSame('mercado_pago:transfers', $this->tx('payout:186')->category->integration_key);
        $this->assertSame(1, Transaction::where('source', 'mercado_pago')->count());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/account/release_report'));

        // Already read: the same file is not read again, and a deleted entry stays deleted.
        $this->tx('payout:186')->delete();
        $this->postJson('/api/integrations/mercado_pago/sync', [], $headers)->assertOk();
        $this->assertNull(Transaction::where('external_id', 'payout:186')->first());
        $this->assertNull(Integration::sole()->last_error);
    }

    public function test_a_failing_report_is_shown_on_the_card_without_losing_the_payments(): void
    {
        $this->payments = [$this->payment(1)];
        $this->reportsDown = true;
        $headers = $this->headers();
        $this->postJson('/api/integrations/mercado_pago/connect', ['access_token' => self::TOKEN], $headers)->assertOk();

        $this->assertNotNull($this->tx('1'));
        $this->assertStringContainsString('Pix enviados', Integration::sole()->last_error);
    }

    public function test_integration_entries_show_mercado_pago_facts_and_lock_what_it_owns(): void
    {
        $this->payments = [$this->payment(1, ['transaction_amount' => 200, 'installments' => 3, 'payment_type_id' => 'credit_card', 'payment_method_id' => 'visa'])];
        $this->reportCsv = "DATE;SOURCE_ID;DESCRIPTION;NET_CREDIT_AMOUNT;NET_DEBIT_AMOUNT\n2026-10-26T19:56:32.000-03:00;186;payout;0.00;65.00\n";
        $this->reports = [['file_name' => 'r.csv', 'end_date' => '2026-10-27T23:00:00Z']];
        $headers = $this->headers(['settings.integrations', 'finance.transaction.view', 'finance.transaction.update']);
        $this->connect($headers);

        $sale = $this->tx('1');
        $facts = collect($this->getJson("/api/finance/transactions/{$sale->id}", $headers)->assertOk()->json('integration.fields'))->pluck('value', 'label');
        $this->assertSame('Venda ou recebimento', $facts['Tipo no Mercado Pago']);
        $this->assertSame('1', $facts['ID do pagamento']);
        $this->assertSame('Aprovado', $facts['Status']);
        $this->assertSame('Cartão de crédito', $facts['Meio de pagamento']);
        $this->assertSame('3x', $facts['Parcelas']);
        $this->assertSame('Tarifa do Mercado Pago', collect($this->getJson('/api/finance/transactions/'.$this->tx('1:fee')->id, $headers)->json('integration.fields'))->firstWhere('label', 'Tipo no Mercado Pago')['value']);
        $this->assertSame('Pix ou transferência enviada', collect($this->getJson('/api/finance/transactions/'.$this->tx('payout:186')->id, $headers)->json('integration.fields'))->firstWhere('label', 'Tipo no Mercado Pago')['value']);

        // Value, type, date and status are the provider's; description and category stay editable.
        $this->putJson("/api/finance/transactions/{$sale->id}", ['amount' => '1.00'], $headers)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->putJson("/api/finance/transactions/{$sale->id}", ['status' => 'pending'], $headers)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson("/api/finance/transactions/{$sale->id}", ['amount' => '200', 'date' => $sale->date, 'description' => 'Pedido da Ana'], $headers)->assertOk();
        $this->assertSame('Pedido da Ana', $sale->fresh()->description);
        $this->patchJson('/api/finance/transactions/status', ['ids' => [$sale->id], 'status' => 'pending'], $headers)->assertOk()->assertJson(['updated' => 0]);

        // A manual entry has no integration section.
        $manual = Transaction::create(['type' => 'expense', 'amount' => 5, 'description' => 'Café', 'category_id' => Category::where('integration_key', 'mercado_pago:fees')->value('id'), 'date' => '2026-10-10']);
        $this->assertNull($this->getJson("/api/finance/transactions/{$manual->id}", $headers)->json('integration'));
    }

    public function test_a_company_cannot_swap_to_another_mercado_pago_account(): void
    {
        $headers = $this->headers();
        $this->connect($headers);

        Http::fake(['*/users/me' => Http::response(['id' => 888, 'nickname' => 'OUTRA'])]);
        $this->postJson('/api/integrations/mercado_pago/connect', ['access_token' => 'token-de-outra-conta'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('access_token');
        $this->assertSame(self::TOKEN, Integration::sole()->credentials['access_token']);
    }

    public function test_settings_switch_and_removal_wipe_everything_it_brought(): void
    {
        $this->payments = [$this->payment(1)];
        $headers = $this->headers();
        $this->connect($headers);

        $this->putJson('/api/integrations/mercado_pago', ['active' => false, 'settings' => ['fees' => false, 'import_from' => now()->subDays(30)->toDateString()]], $headers)
            ->assertOk()->assertJson(['active' => false, 'settings' => ['fees' => false, 'pending' => true]]);
        $this->putJson('/api/integrations/mercado_pago', ['settings' => ['token' => 'x']], $headers)->assertUnprocessable();
        $this->putJson('/api/integrations/mercado_pago', ['settings' => ['import_from' => '2020-01-01']], $headers)->assertUnprocessable();
        $this->postJson('/api/integrations/mercado_pago/sync', [], $headers)->assertUnprocessable();

        // The integration's category can't be deleted while connected.
        $sales = Category::where('integration_key', 'mercado_pago:sales')->sole();
        $this->deleteJson("/api/finance/categories/{$sales->id}", [], $headers)->assertUnprocessable();

        // A manual entry put in an integration category keeps that category (as an ordinary one).
        $fees = Category::where('integration_key', 'mercado_pago:fees')->sole();
        Transaction::create(['type' => 'expense', 'amount' => 5, 'description' => 'Manual', 'category_id' => $fees->id, 'date' => '2026-10-10']);

        $this->deleteJson('/api/integrations/mercado_pago', [], $headers)->assertOk();
        $this->assertSame(0, Integration::count());
        $this->assertSame(0, MercadoPagoPayment::count());
        $this->assertSame(0, Transaction::withTrashed()->where('source', 'mercado_pago')->count());
        $this->assertNull(Category::withTrashed()->find($sales->id));
        $this->assertNull($fees->fresh()->integration_key);
        $this->getJson('/api/integrations', $headers)->assertJsonPath('0.connected', false);
    }

    public function test_dashboard_shows_the_integration_panel_and_sources(): void
    {
        $this->payments = [
            $this->payment(1),
            $this->payment(3, ['payment_type_id' => 'credit_card', 'payment_method_id' => 'visa', 'transaction_amount' => 300, 'fee_details' => [['amount' => 10, 'fee_payer' => 'collector']]]),
        ];
        $headers = $this->headers();
        $this->connect($headers);

        $data = $this->getJson('/api/finance/dashboard?from=2026-10-01&to=2026-10-31', $headers)->assertOk()->json();
        $this->assertEquals(['gross' => 400, 'fees' => 10.99, 'refunded' => 0, 'net' => 389.01, 'pending' => 0, 'purchases' => 0, 'count' => 2], $data['mercado_pago']['totals']);
        $this->assertSame(['credit_card', 'pix'], array_column($data['mercado_pago']['methods'], 'key'));
        $this->assertSame('mercado_pago', $data['sources'][0]['source']);
        $this->assertEquals(400, $data['sources'][0]['income']);

        // Another company sees neither the panel nor the integration.
        $other = Company::create(['name' => 'Outra']);
        Company::as($other->id, fn () => $this->assertSame(0, Integration::count()));
    }
}
