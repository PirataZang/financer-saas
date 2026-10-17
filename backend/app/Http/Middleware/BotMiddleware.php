<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Service\BotActivationService;
use Closure;
use Illuminate\Http\Request;
use Spatie\Activitylog\Facades\CauserResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/bot/*. Two checks, neither trusting Telegram data alone:
 *  1. the caller is our bot container: Bearer BOT_API_TOKEN (constant-time compare, fails
 *     closed when unset);
 *  2. the Telegram user (X-Telegram-User-Id, taken by the bot from Telegram's update) has
 *     activated the bot and wasn't revoked — skipped only by `bot:activation`.
 * The account becomes the activity-log causer, so every bot change is attributed, and the request
 * is scoped to the account's company (blocked while its license is inactive, like the web).
 */
class BotMiddleware
{
    public const TELEGRAM_USER_ID = 'telegram_user_id';

    public const ACCOUNT = 'telegram_account';

    public function __construct(
        protected BotActivationService $activationService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $mode = 'activated'): Response
    {
        $expected = (string) config('services.telegram.bot_api_token');
        $token = (string) $request->bearerToken();

        if ($expected === '' || !hash_equals($expected, $token)) {
            return response()->json(['code' => 'INVALID_BOT_TOKEN', 'message' => 'Integração não autorizada.'], 401);
        }

        $telegramUserId = $request->header('X-Telegram-User-Id');
        if (!is_string($telegramUserId) || !ctype_digit($telegramUserId) || strlen($telegramUserId) > 20) {
            return response()->json(['code' => 'INVALID_TELEGRAM_USER', 'message' => 'Usuário do Telegram não informado.'], 422);
        }
        $request->attributes->set(self::TELEGRAM_USER_ID, (int) $telegramUserId);

        if ($mode !== 'activation') {
            $account = $this->activationService->resolve((int) $telegramUserId);

            if (!$account) {
                return response()->json(['code' => 'BOT_NOT_ACTIVATED', 'message' => 'Bot não ativado para este usuário.'], 403);
            }

            if (!$account->company?->isActive()) {
                return response()->json(['code' => 'COMPANY_INACTIVE', 'message' => 'O acesso da sua empresa está suspenso ou expirado.'], 403);
            }

            $request->attributes->set(self::ACCOUNT, $account);
            CauserResolver::setCauser($account);

            return Company::as($account->company_id, fn () => $next($request));
        }

        return $next($request);
    }
}
