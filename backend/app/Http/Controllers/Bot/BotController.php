<?php

namespace App\Http\Controllers\Bot;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\BotMiddleware;
use App\Models\TelegramAccount;
use App\Service\BotActivationService;
use App\Service\CategoryService;
use App\Service\FinanceDashboardService;
use App\Service\SettingsService;
use App\Service\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * API used only by the Telegram bot (routes/api.php, prefix `bot`). BotMiddleware authenticates
 * the bot itself (BOT_API_TOKEN) and the Telegram user; no user permissions apply here, and
 * the bot can list categories, read the balancete and create transactions — no admin CRUD.
 */
class BotController extends Controller
{
    public function __construct(
        protected BotActivationService $activationService,
        protected CategoryService $categoryService,
        protected TransactionService $transactionService,
        protected FinanceDashboardService $dashboardService,
    ) {
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:64',
            'username' => 'nullable|string|max:64',
            'first_name' => 'nullable|string|max:128',
        ]);

        $account = $this->activationService->activate([
            'id' => $request->attributes->get(BotMiddleware::TELEGRAM_USER_ID),
            'username' => $validated['username'] ?? null,
            'first_name' => $validated['first_name'] ?? null,
        ], $validated['code']);

        return response()->json(['message' => 'Bot ativado.', 'account' => $account]);
    }

    /** The account plus its company: name (shown before disconnecting) and timezone (the bot's "today"). */
    public function me(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            ...$account->toArray(),
            'company' => $account->company->trade_name ?: $account->company->name,
            'timezone' => SettingsService::for($account->company)['finance']['timezone'],
        ]);
    }

    /**
     * The Telegram user unlinks themselves (same as "Remover" in Configurações › Telegram), so they can
     * send another code — e.g. another company's. Revoked accounts never get here (BotMiddleware).
     */
    public function disconnect(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $this->activationService->remove($account->id);
        Log::info('bot.disconnected', ['telegram_user_id' => $account->telegram_user_id]);

        return response()->json(['message' => 'Bot desconectado.']);
    }

    public function categories(Request $request): JsonResponse
    {
        $validated = $request->validate(['type' => ['required', Rule::enum(TransactionType::class)]]);

        return response()->json($this->categoryService->options(TransactionType::from($validated['type'])));
    }

    /**
     * Balancete: same aggregates as the web dashboard (paid only). Dates come from the bot, in the
     * user's timezone; without `from` it covers everything up to `to`.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($this->dashboardService->summary($validated['from'] ?? null, $validated['to']));
    }

    /**
     * Idempotent: the same idempotency_key from the same Telegram user returns the original
     * transaction (200) instead of creating another (201).
     */
    public function createTransaction(Request $request): JsonResponse
    {
        $rules = $this->transactionService->rules();
        unset($rules['status']);
        $validated = $request->validate([...$rules, 'idempotency_key' => 'required|string|max:100']);

        $account = $this->account($request);
        $key = $validated['idempotency_key'];
        unset($validated['idempotency_key']);

        // Scoped per Telegram user so one user's key can never match another's transaction.
        $transaction = $this->transactionService->create(
            $validated,
            TransactionSource::Telegram,
            "{$account->telegram_user_id}:{$key}",
        );

        Log::info($transaction->wasRecentlyCreated ? 'bot.transaction.created' : 'bot.transaction.replayed', [
            'telegram_user_id' => $account->telegram_user_id,
            'transaction_id' => $transaction->id,
        ]);

        return response()->json($transaction, $transaction->wasRecentlyCreated ? 201 : 200);
    }

    private function account(Request $request): TelegramAccount
    {
        return $request->attributes->get(BotMiddleware::ACCOUNT);
    }
}
