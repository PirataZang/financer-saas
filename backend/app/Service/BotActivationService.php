<?php

namespace App\Service;

use App\Models\Company;
use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The only place that knows how a Telegram user unlocks the bot. Today: one shared code from
 * TELEGRAM_ACTIVATION_CODE. Per-user/expiring/single-use codes or linking to a system user
 * replace codeIsValid() (and add a table); callers don't change.
 *
 * Runs before the tenant is known: lookups skip the company scope; BotMiddleware then scopes the
 * request to the account's company.
 */
class BotActivationService
{
    /**
     * @param  array{id: int, username?: ?string, first_name?: ?string}  $telegramUser
     */
    public function activate(array $telegramUser, string $code): TelegramAccount
    {
        $account = TelegramAccount::withoutGlobalScope('company')->firstWhere('telegram_user_id', $telegramUser['id']);

        // Revocation sticks: knowing the shared code again doesn't undo it.
        if ($account?->revoked_at) {
            Log::warning('bot.activation.revoked', ['telegram_user_id' => $telegramUser['id']]);
            throw ValidationException::withMessages(['code' => ['Acesso revogado. Fale com o administrador.']]);
        }

        if (!$this->codeIsValid($code)) {
            Log::warning('bot.activation.failed', ['telegram_user_id' => $telegramUser['id']]);
            throw ValidationException::withMessages(['code' => ['Código de ativação inválido.']]);
        }

        // ponytail: one shared code → the Empresa Padrão. Per-company codes when a second company uses the bot.
        $account = Company::as($account->company_id ?? Company::DEFAULT_ID, fn () => TelegramAccount::updateOrCreate(
            ['telegram_user_id' => $telegramUser['id']],
            [
                'username' => $telegramUser['username'] ?? null,
                'first_name' => $telegramUser['first_name'] ?? null,
                'activated_at' => $account?->activated_at ?? now(),
            ],
        ));

        Log::info('bot.activation.succeeded', ['telegram_user_id' => $account->telegram_user_id, 'account_id' => $account->id]);

        return $account;
    }

    /**
     * Activated and not revoked, or null.
     */
    public function resolve(int $telegramUserId): ?TelegramAccount
    {
        return TelegramAccount::withoutGlobalScope('company')->where('telegram_user_id', $telegramUserId)->whereNull('revoked_at')->first();
    }

    private function codeIsValid(string $code): bool
    {
        $expected = (string) config('services.telegram.activation_code');

        return $expected !== '' && hash_equals($expected, trim($code));
    }
}
