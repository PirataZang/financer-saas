<?php

namespace App\Service;

use App\Enums\IntegrationProvider;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Integration;
use App\Models\MercadoPagoPayment;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Mercado Pago integration: each company links its own account with that account's Access Token
 * (encrypted in integrations.credentials; it has to be readable again, so encryption, not a hash),
 * periodic read-only sync of the account's
 * payments and the dashboard's integration figures. Every payment is mirrored in
 * mercado_pago_payments and turned into finance transactions (source mercado_pago, idempotent by
 * external_id "<payment id>[:fee|:refund]") in categories the integration owns.
 *
 * What the API gives: payments the account received (sales, Pix, boleto, card) with fees, refunds
 * and chargebacks, and payments it made through Mercado Pago when the API lists them. Card purchases
 * with the Mercado Pago card and Pix sent from the app are not in this API (they would need Open Finance).
 */
class MercadoPagoService
{
    public const DEFAULT_SETTINGS = [
        // First sync goes back to this day (Y-m-d); null = FIRST_SYNC_DAYS ago.
        'import_from' => null,
        // Mercado Pago fees as expenses ("Tarifas · Mercado Pago").
        'fees' => true,
        // Payments not approved yet (boleto issued, Pix waiting) as pending incomes.
        'pending' => true,
    ];

    private const FIRST_SYNC_DAYS = 90;

    private const MAX_IMPORT_DAYS = 365;

    private const PAGE_SIZE = 50;

    // ponytail: 2000 payments per run; a bigger backlog continues on the next run from the cursor.
    private const MAX_PAGES = 40;

    /** Categories the integration creates and owns (finance_categories.integration_key). */
    private const CATEGORIES = [
        'sales' => ['name' => 'Vendas · Mercado Pago', 'icon' => '🤝', 'color' => '#0284c7', 'type' => 'income'],
        'fees' => ['name' => 'Tarifas · Mercado Pago', 'icon' => '🧾', 'color' => '#64748b', 'type' => 'expense'],
        'refunds' => ['name' => 'Estornos · Mercado Pago', 'icon' => '↩️', 'color' => '#d97706', 'type' => 'expense'],
        'purchases' => ['name' => 'Compras · Mercado Pago', 'icon' => '🛒', 'color' => '#7c3aed', 'type' => 'expense'],
        'transfers' => ['name' => 'Transferências · Mercado Pago', 'icon' => '💸', 'color' => '#0f766e', 'type' => 'expense'],
    ];

    /** Money already moved (approved, held in a dispute, or moved and then returned). */
    private const SETTLED = ['approved', 'in_mediation', 'refunded', 'charged_back'];

    private const WAITING = ['pending', 'in_process', 'authorized'];

    private const METHODS = [
        'pix' => 'Pix',
        'credit_card' => 'Cartão de crédito',
        'debit_card' => 'Cartão de débito',
        'ticket' => 'Boleto',
        'account_money' => 'Saldo Mercado Pago',
        'prepaid_card' => 'Cartão pré-pago',
        'atm' => 'Lotérica / caixa',
        'other' => 'Outros',
    ];

    private const STATUSES = [
        'approved' => ['label' => 'Aprovados', 'tone' => 'success'],
        'pending' => ['label' => 'Aguardando', 'tone' => 'warning'],
        'refunded' => ['label' => 'Estornados / contestados', 'tone' => 'danger'],
        'rejected' => ['label' => 'Recusados / cancelados', 'tone' => 'neutral'],
    ];

    public function __construct(private MercadoPagoClient $client)
    {
    }

    /** Links the company to the account the Access Token belongs to. First sync runs right away. */
    public function connect(string $accessToken): Integration
    {
        try {
            $me = $this->client->get($accessToken, '/users/me');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access_token' => [$e->getMessage()]]);
        }

        $integration = Integration::for(IntegrationProvider::MercadoPago) ?? new Integration;
        $previous = $integration->account['user_id'] ?? null;
        if ($previous && $previous !== (int) $me['id']) {
            throw ValidationException::withMessages(['access_token' => ['Esta empresa já está ligada a outra conta do Mercado Pago. Remova a integração antes de conectar uma conta diferente.']]);
        }

        $integration->forceFill([
            'provider' => IntegrationProvider::MercadoPago,
            'active' => true,
            'credentials' => ['access_token' => $accessToken],
            'account' => [
                'user_id' => (int) $me['id'],
                'nickname' => $me['nickname'] ?? null,
                'email' => $me['email'] ?? null,
                'connected_at' => now()->toIso8601String(),
            ],
            'settings' => $integration->settings ?? self::DEFAULT_SETTINGS,
            'last_error' => null,
        ])->save();

        $this->syncQuietly($integration);

        return $integration;
    }

    /** What Configurações › Integrações shows. Nothing secret: no token, only who is connected. */
    public function card(): array
    {
        $integration = Integration::for(IntegrationProvider::MercadoPago);

        return [
            'provider' => IntegrationProvider::MercadoPago->value,
            'connected' => (bool) $integration,
            'active' => (bool) $integration?->active,
            'account' => $integration ? [
                'nickname' => $integration->account['nickname'] ?? null,
                'email' => $integration->account['email'] ?? null,
                'connected_at' => $integration->account['connected_at'] ?? null,
            ] : null,
            'settings' => $integration ? $this->settings($integration) : self::DEFAULT_SETTINGS,
            'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
            'last_error' => $integration?->last_error,
            'stats' => $integration ? [
                'payments' => MercadoPagoPayment::where('integration_id', $integration->id)->count(),
                'transactions' => Transaction::where('source', TransactionSource::MercadoPago)->count(),
            ] : null,
        ];
    }

    private const PAYMENT_STATUS_LABELS = [
        'approved' => 'Aprovado',
        'pending' => 'Pendente',
        'in_process' => 'Em análise',
        'authorized' => 'Autorizado',
        'in_mediation' => 'Em disputa',
        'rejected' => 'Recusado',
        'cancelled' => 'Cancelado',
        'refunded' => 'Estornado',
        'charged_back' => 'Contestado (chargeback)',
    ];

    /**
     * What the transaction edit screen shows in its locked "Mercado Pago" section: facts straight from
     * the mirrored payment (or the report row for a transfer), as label/value/format. Display only.
     *
     * @return array{provider: string, title: string, fields: list<array{label: string, value: mixed, format: string}>}
     */
    public function details(Transaction $transaction): array
    {
        $externalId = (string) $transaction->external_id;
        $transfer = str_starts_with($externalId, 'payout:');
        [$id, $part] = array_pad(explode(':', $transfer ? substr($externalId, 7) : $externalId), 2, null);
        $payment = $transfer ? null : MercadoPagoPayment::firstWhere('mp_id', $id);

        $kind = match (true) {
            $transfer => 'Pix ou transferência enviada',
            $part === 'fee' => 'Tarifa do Mercado Pago',
            $part === 'refund' => 'Estorno ou contestação',
            $payment?->direction === 'out' => 'Compra paga pela conta',
            default => 'Venda ou recebimento',
        };
        $fields = [
            ['label' => 'Tipo no Mercado Pago', 'value' => $kind, 'format' => 'text'],
            ['label' => $transfer ? 'ID da movimentação' : 'ID do pagamento', 'value' => $id, 'format' => 'text'],
        ];

        if ($payment) {
            $fields = [...$fields,
                ['label' => 'Status', 'value' => self::PAYMENT_STATUS_LABELS[$payment->status] ?? $payment->status, 'format' => 'text'],
                ['label' => 'Meio de pagamento', 'value' => self::METHODS[self::method($payment)], 'format' => 'text'],
                ...($payment->installments > 1 ? [['label' => 'Parcelas', 'value' => "{$payment->installments}x", 'format' => 'text']] : []),
                ['label' => 'Valor do pagamento', 'value' => $payment->amount, 'format' => 'money'],
                ...($payment->fee > 0 ? [['label' => 'Tarifa', 'value' => $payment->fee, 'format' => 'money']] : []),
                ...($payment->net !== null ? [['label' => 'Líquido', 'value' => $payment->net, 'format' => 'money']] : []),
                ...($payment->refunded > 0 ? [['label' => 'Estornado', 'value' => $payment->refunded, 'format' => 'money']] : []),
                ...($payment->description ? [['label' => 'Descrição no Mercado Pago', 'value' => $payment->description, 'format' => 'text']] : []),
                ['label' => 'Data do pagamento', 'value' => $payment->date, 'format' => 'date'],
                ['label' => 'Última atualização', 'value' => $payment->mp_updated_at->toIso8601String(), 'format' => 'datetime'],
            ];
        }

        return ['provider' => IntegrationProvider::MercadoPago->value, 'title' => 'Dados do Mercado Pago', 'fields' => $fields];
    }

    public function settings(Integration $integration): array
    {
        return [...self::DEFAULT_SETTINGS, ...($integration->settings ?? [])];
    }

    public function settingsRules(): array
    {
        return [
            'import_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(self::MAX_IMPORT_DAYS)->toDateString()],
            'fees' => ['sometimes', 'boolean'],
            'pending' => ['sometimes', 'boolean'],
        ];
    }

    /** Scheduler and the connect step: a failure is recorded on the card instead of thrown. */
    public function syncQuietly(Integration $integration): void
    {
        try {
            $this->sync($integration);
        } catch (RuntimeException) {
            // last_error already saved by sync().
        }
    }

    /**
     * Pulls every payment changed since the cursor (first run: settings.import_from) and applies it.
     * One run per integration at a time; audit log stays out of it (thousands of automatic rows).
     *
     * @return array{payments: int}
     */
    public function sync(Integration $integration): array
    {
        $lock = Cache::lock("mercadopago:sync:{$integration->id}", 600);
        if (!$lock->get()) {
            return ['payments' => 0];
        }

        try {
            return Company::as($integration->company_id, fn () => activity()->withoutLogs(function () use ($integration) {
                $result = $this->pull($integration);
                $this->pullTransfers($integration);

                return $result;
            }));
        } catch (RuntimeException $e) {
            $integration->forceFill(['last_error' => Str::limit($e->getMessage(), 250)])->save();
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Removes everything the integration brought: transactions, mirrored payments, its categories, the token. */
    public function purge(Integration $integration): void
    {
        DB::transaction(function () use ($integration) {
            Transaction::withTrashed()->where('source', TransactionSource::MercadoPago)->forceDelete();
            MercadoPagoPayment::where('integration_id', $integration->id)->delete();

            foreach (Category::withTrashed()->where('integration_key', 'like', 'mercado_pago:%')->get() as $category) {
                // A category someone reused for their own entries stays, as an ordinary category.
                $category->transactions()->withTrashed()->exists()
                    ? $category->forceFill(['integration_key' => null])->save()
                    : $category->forceDelete();
            }

            $integration->delete();
        });
    }

    /** Integration figures for the finance dashboard (same period as its filters). Null = not connected. */
    public function dashboard(string $from, string $to): ?array
    {
        $integration = Integration::for(IntegrationProvider::MercadoPago);
        if (!$integration?->active) {
            return null;
        }

        $payments = MercadoPagoPayment::whereBetween('date', [$from, $to])
            ->get(['direction', 'status', 'payment_type', 'payment_method', 'amount', 'fee', 'refunded']);
        [$in, $out] = $payments->partition(fn ($p) => $p->direction === 'in');
        $settled = $in->filter(fn ($p) => in_array($p->status, self::SETTLED, true));
        $gross = (float) $settled->sum('amount');
        $fees = (float) $settled->whereIn('status', ['approved', 'in_mediation'])->sum('fee');
        $refunded = (float) $settled->sum(fn ($p) => $p->status === 'charged_back' ? $p->amount : $p->refunded);

        return [
            'account' => [
                'nickname' => $integration->account['nickname'] ?? null,
                'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
                'last_error' => $integration->last_error,
            ],
            'totals' => [
                'gross' => round($gross, 2),
                'fees' => round($fees, 2),
                'refunded' => round($refunded, 2),
                'net' => round($gross - $fees - $refunded, 2),
                'pending' => round((float) $in->whereIn('status', self::WAITING)->sum('amount'), 2),
                'purchases' => round((float) $out->whereIn('status', self::SETTLED)->sum(fn ($p) => $p->amount - $p->refunded), 2),
                'count' => $settled->count(),
            ],
            'methods' => self::groupBy($settled, fn ($p) => self::method($p), self::METHODS),
            'statuses' => collect(self::STATUSES)->map(fn ($meta, $key) => [
                'key' => $key,
                ...$meta,
                'count' => $in->filter(fn ($p) => self::statusGroup($p->status) === $key)->count(),
            ])->filter(fn ($row) => $row['count'] > 0)->values()->all(),
            'composition' => array_values(array_filter([
                ['key' => 'net', 'label' => 'Líquido recebido', 'total' => round($gross - $fees - $refunded, 2)],
                ['key' => 'fees', 'label' => 'Tarifas', 'total' => round($fees, 2)],
                ['key' => 'refunded', 'label' => 'Estornos e contestações', 'total' => round($refunded, 2)],
            ], fn ($row) => $row['total'] > 0)),
            'purchases' => self::groupBy($out->whereIn('status', self::SETTLED), fn ($p) => self::method($p), self::METHODS),
        ];
    }

    private function pull(Integration $integration): array
    {
        $token = $this->accessToken($integration);
        $settings = $this->settings($integration);
        $timezone = SettingsService::for($integration->company)['finance']['timezone'];
        $begin = $integration->sync_cursor
            ?? CarbonImmutable::parse($settings['import_from'] ?? now()->subDays(self::FIRST_SYNC_DAYS)->toDateString(), $timezone)->startOfDay();
        $cursor = $integration->sync_cursor;
        $count = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->client->get($token, '/v1/payments/search', [
                'sort' => 'date_last_updated',
                'criteria' => 'asc',
                'range' => 'date_last_updated',
                'begin_date' => $begin->format('Y-m-d\TH:i:s.vP'),
                'end_date' => 'NOW',
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
            ]);
            $results = $data['results'] ?? [];

            foreach ($results as $payment) {
                $updatedAt = $this->apply($integration, $payment, $settings, $timezone);
                if ($updatedAt && (!$cursor || $updatedAt->gt($cursor))) {
                    $cursor = $updatedAt;
                }
                $count++;
            }

            if (count($results) < self::PAGE_SIZE) {
                break;
            }
        }

        $integration->forceFill(['sync_cursor' => $cursor, 'last_synced_at' => now(), 'last_error' => null])->save();

        return ['payments' => $count];
    }

    /**
     * Pix sent and transfers are not payments to the API: they only exist in the "Liberações" report.
     * Reads the reports that are ready, then asks for the next one (it takes about a minute to be
     * generated, so what you sent shows up in the following sync). A failure here is shown on the
     * card but never undoes the payments that were just synced.
     */
    private function pullTransfers(Integration $integration): void
    {
        $token = $this->accessToken($integration);
        $timezone = SettingsService::for($integration->company)['finance']['timezone'];
        $cursor = $integration->payouts_cursor;

        try {
            // A report only knows what existed when it was generated, even if its period ends later.
            // ponytail: the list labels date_created with a wrong offset (-04:00); its wall clock is UTC. Drop the offset if Mercado Pago fixes it.
            $knownUntil = fn (array $f) => Carbon::parse($f['end_date'])->min(Carbon::parse(substr($f['date_created'] ?? $f['end_date'], 0, 19), 'UTC'));
            $files = collect($this->client->get($token, '/v1/account/release_report/list'))
                ->filter(fn ($f) => !empty($f['file_name']) && !empty($f['end_date']) && (!$cursor || $knownUntil($f)->gt($cursor)))
                ->sortBy(fn ($f) => $knownUntil($f)->timestamp);

            foreach ($files as $file) {
                $this->applyTransfers($this->client->reportFile($token, $file['file_name']), $timezone);
                $cursor = $knownUntil($file);
                $integration->forceFill(['payouts_cursor' => $cursor])->save();
            }

            // A day of overlap: movements show up in the report a little after they happen (rows are idempotent).
            $settings = $this->settings($integration);
            $from = $cursor?->subDay() ?? CarbonImmutable::parse($settings['import_from'] ?? now()->subDays(self::FIRST_SYNC_DAYS)->toDateString(), $timezone)->startOfDay();
            $this->client->requestReport($token, $from->utc()->format('Y-m-d\TH:i:s\Z'), now()->utc()->format('Y-m-d\TH:i:s\Z'));
        } catch (RuntimeException $e) {
            $integration->forceFill(['last_error' => Str::limit('Pix enviados e transferências: '.$e->getMessage(), 250)])->save();
        }
    }

    /** One "payout" row of the report = one Pix/transfer the account sent. Idempotent by "payout:<id>"; a deleted one stays deleted. */
    private function applyTransfers(string $csv, string $timezone): void
    {
        $lines = preg_split('/\R/', trim($csv));
        $columns = array_flip(str_getcsv((string) array_shift($lines), ';'));
        foreach (['DATE', 'SOURCE_ID', 'DESCRIPTION', 'NET_DEBIT_AMOUNT'] as $required) {
            if (!isset($columns[$required])) {
                return;
            }
        }

        foreach ($lines as $line) {
            $row = str_getcsv($line, ';');
            $amount = (float) ($row[$columns['NET_DEBIT_AMOUNT']] ?? 0);
            $id = $row[$columns['SOURCE_ID']] ?? '';
            if (($row[$columns['DESCRIPTION']] ?? '') !== 'payout' || $amount <= 0 || $id === '') {
                continue;
            }

            $externalId = "payout:{$id}";
            if (Transaction::withTrashed()->where('source', TransactionSource::MercadoPago)->where('external_id', $externalId)->exists()) {
                continue;
            }
            (new Transaction([
                'source' => TransactionSource::MercadoPago,
                'external_id' => $externalId,
                'description' => "Transferência enviada · #{$id}",
                'category_id' => $this->category('transfers')->id,
                'type' => TransactionType::Expense,
                'amount' => $amount,
                'status' => TransactionStatus::Paid,
                'date' => Carbon::parse($row[$columns['DATE']])->setTimezone($timezone)->toDateString(),
            ]))->save();
        }
    }

    /** Mirrors one payment and brings its transactions in line. Returns its last update (the cursor). */
    private function apply(Integration $integration, array $payment, array $settings, string $timezone): ?Carbon
    {
        $userId = $integration->account['user_id'];
        // The search only returns payments of this account, and for the ones it paid as a buyer
        // (a store checkout, money from the balance) Mercado Pago hides collector and payer: not
        // being the collector is what makes it an outgoing payment.
        $direction = (int) ($payment['collector_id'] ?? 0) === $userId ? 'in' : 'out';
        if (!isset($payment['id'], $payment['status'], $payment['transaction_amount'], $payment['date_last_updated'])) {
            return null;
        }

        $updatedAt = Carbon::parse($payment['date_last_updated']);
        $stored = MercadoPagoPayment::firstWhere('mp_id', $payment['id']);
        if ($stored && $stored->mp_updated_at->gte($updatedAt)) {
            return $updatedAt;
        }

        $fee = $direction === 'in'
            ? collect($payment['fee_details'] ?? [])->where('fee_payer', 'collector')->sum('amount')
            : 0;

        $mirror = $stored ?? new MercadoPagoPayment(['mp_id' => $payment['id'], 'integration_id' => $integration->id]);
        $mirror->fill([
            'direction' => $direction,
            'status' => $payment['status'],
            'status_detail' => $payment['status_detail'] ?? null,
            'payment_type' => $payment['payment_type_id'] ?? null,
            'payment_method' => $payment['payment_method_id'] ?? null,
            'description' => Str::limit(trim((string) ($payment['description'] ?? '')), 200, '') ?: null,
            'amount' => $payment['transaction_amount'],
            'fee' => $fee,
            'net' => $payment['transaction_details']['net_received_amount'] ?? null,
            'refunded' => $payment['transaction_amount_refunded'] ?? 0,
            'installments' => max(1, (int) ($payment['installments'] ?? 1)),
            'date' => Carbon::parse($payment['date_approved'] ?? $payment['date_created'] ?? $payment['date_last_updated'])->setTimezone($timezone)->toDateString(),
            'mp_updated_at' => $updatedAt,
        ])->save();

        $this->syncTransactions($mirror, $settings, Carbon::parse($payment['date_last_updated'])->setTimezone($timezone)->toDateString());

        return $updatedAt;
    }

    /**
     * Wanted transactions of a payment vs. what exists. Amount, status and date follow Mercado Pago;
     * description and category stay as the user left them; one the user deleted stays deleted.
     */
    private function syncTransactions(MercadoPagoPayment $p, array $settings, string $updatedDay): void
    {
        $settled = in_array($p->status, self::SETTLED, true);
        $waiting = in_array($p->status, self::WAITING, true) && $settings['pending'];
        $label = trim(($p->description ?: self::METHODS[self::method($p)])." · #{$p->mp_id}");
        $wanted = [];

        if ($p->direction === 'in') {
            if ($settled || $waiting) {
                $wanted[$p->mp_id] = ['sales', TransactionType::Income, $p->amount, $settled, $p->date, $label];
            }
            if ($settings['fees'] && $p->fee > 0 && in_array($p->status, ['approved', 'in_mediation'], true)) {
                $wanted["{$p->mp_id}:fee"] = ['fees', TransactionType::Expense, $p->fee, true, $p->date, "Tarifa · {$label}"];
            }
            $refund = $p->status === 'charged_back' ? $p->amount : $p->refunded;
            if ($settled && $refund > 0) {
                $wanted["{$p->mp_id}:refund"] = ['refunds', TransactionType::Expense, $refund, true, $updatedDay, "Estorno · {$label}"];
            }
        } elseif (($settled && $p->amount - $p->refunded > 0) || $waiting) {
            $wanted[$p->mp_id] = ['purchases', TransactionType::Expense, $settled ? $p->amount - $p->refunded : $p->amount, $settled, $p->date, $label];
        }

        foreach (["{$p->mp_id}", "{$p->mp_id}:fee", "{$p->mp_id}:refund"] as $externalId) {
            $transaction = Transaction::withTrashed()->where('source', TransactionSource::MercadoPago)->where('external_id', $externalId)->first();

            if (!isset($wanted[$externalId])) {
                $transaction?->forceDelete();
                continue;
            }
            if ($transaction?->trashed()) {
                continue;
            }

            [$category, $type, $amount, $paid, $date, $description] = $wanted[$externalId];
            $transaction ??= new Transaction([
                'source' => TransactionSource::MercadoPago,
                'external_id' => $externalId,
                'description' => Str::limit($description, 250, ''),
                'category_id' => $this->category($category)->id,
            ]);
            $transaction->fill([
                'type' => $type,
                'amount' => $amount,
                'status' => $paid ? TransactionStatus::Paid : TransactionStatus::Pending,
                'date' => $date,
            ])->save();
        }
    }

    /** The integration's category for $key: found by key (even renamed), revived if needed, else created. */
    private function category(string $key): Category
    {
        $integrationKey = "mercado_pago:{$key}";
        $spec = self::CATEGORIES[$key];
        $category = Category::withTrashed()->where('integration_key', $integrationKey)->first()
            // Same name already created by hand: adopt it instead of failing on the unique name.
            ?? Category::where('name', $spec['name'])->whereNull('integration_key')->first()
            ?? new Category($spec);

        if ($category->trashed()) {
            $category->restore();
        }
        $category->forceFill(['integration_key' => $integrationKey, 'active' => true])->save();

        return $category;
    }

    private function accessToken(Integration $integration): string
    {
        return $integration->credentials['access_token'] ?? throw new RuntimeException('Integração sem credenciais. Conecte a conta de novo.', 401);
    }

    private static function method(MercadoPagoPayment $p): string
    {
        if ($p->payment_method === 'pix') {
            return 'pix';
        }

        return array_key_exists((string) $p->payment_type, self::METHODS) ? $p->payment_type : 'other';
    }

    private static function statusGroup(string $status): string
    {
        return match (true) {
            in_array($status, ['approved', 'in_mediation'], true) => 'approved',
            in_array($status, self::WAITING, true) => 'pending',
            in_array($status, ['refunded', 'charged_back'], true) => 'refunded',
            default => 'rejected',
        };
    }

    private static function groupBy($payments, callable $key, array $labels): array
    {
        return $payments->groupBy($key)
            ->map(fn ($group, $k) => ['key' => $k, 'label' => $labels[$k], 'total' => round((float) $group->sum('amount'), 2), 'count' => $group->count()])
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
