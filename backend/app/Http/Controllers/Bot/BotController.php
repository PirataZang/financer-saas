<?php

namespace App\Http\Controllers\Bot;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\BotMiddleware;
use App\Models\TelegramAccount;
use App\Service\BotActivationService;
use App\Service\CategoryService;
use App\Service\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * API used only by the Telegram bot (routes/api.php, prefix `bot`). BotMiddleware authenticates
 * the bot itself (BOT_API_TOKEN) and the Telegram user; no user permissions apply here, and
 * the bot can only list categories and create transactions — no admin CRUD.
 */
class BotController extends Controller
{
    public function __construct(
        protected BotActivationService $activationService,
        protected CategoryService $categoryService,
        protected TransactionService $transactionService,
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

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->account($request));
    }

    public function categories(Request $request): JsonResponse
    {
        $validated = $request->validate(['type' => ['required', Rule::enum(TransactionType::class)]]);

        return response()->json($this->categoryService->options(TransactionType::from($validated['type'])));
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
