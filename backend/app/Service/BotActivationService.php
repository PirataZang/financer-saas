<?php

namespace App\Service;

use App\Models\Company;
use App\Models\TelegramAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The only place that knows how a Telegram user unlocks the bot: each company has its own link
 * code (companies.telegram_code), managed in Configurações › Telegram. Sending the code — or opening
 * t.me/<bot>?start=<code> — links that Telegram user to the company. A new code invalidates the old
 * one for new links only; accounts already linked keep working until revoked.
 *
 * Activation runs before the tenant is known: lookups skip the company scope; BotMiddleware then
 * scopes every bot request to the account's company.
 */
class BotActivationService
{
    // No 0/O/1/I/L: the code is also read off a screen and typed.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @param  array{id: int, username?: ?string, first_name?: ?string}  $telegramUser
     */
    public function activate(array $telegramUser, string $code): TelegramAccount
    {
        $company = $this->companyForCode($code);
        $account = TelegramAccount::withoutGlobalScope('company')->firstWhere('telegram_user_id', $telegramUser['id']);

        if (!$company) {
            Log::warning('bot.activation.failed', ['telegram_user_id' => $telegramUser['id']]);
            throw ValidationException::withMessages(['code' => ['Código de ativação inválido.']]);
        }

        // One company per Telegram user: the other company has to remove the link first.
        if ($account && $account->company_id !== $company->id) {
            Log::warning('bot.activation.other_company', ['telegram_user_id' => $telegramUser['id']]);
            throw ValidationException::withMessages(['code' => ['Este Telegram já está vinculado a outra empresa. Peça para removerem o vínculo lá.']]);
        }

        // Revocation sticks: knowing the code again doesn't undo it (an admin reactivates it).
        if ($account?->revoked_at) {
            Log::warning('bot.activation.revoked', ['telegram_user_id' => $telegramUser['id']]);
            throw ValidationException::withMessages(['code' => ['Acesso revogado. Fale com o administrador.']]);
        }

        $account = Company::as($company->id, fn () => TelegramAccount::updateOrCreate(
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

    /** What Configurações › Telegram shows: the code, its t.me link and the linked accounts. */
    public function overview(Company $company): array
    {
        $code = $company->telegram_code;
        $username = config('services.telegram.bot_username');

        return [
            'code' => $code,
            'bot_username' => $username ?: null,
            'link' => $code && $username ? "https://t.me/{$username}?start={$code}" : null,
            'accounts' => $this->accounts(),
        ];
    }

    public function regenerateCode(Company $company): string
    {
        $code = implode('-', str_split(collect(range(1, 12))->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode(''), 4));
        $company->forceFill(['telegram_code' => $code])->save();

        return $code;
    }

    /** No code = nobody new can link; linked accounts keep working. */
    public function revokeCode(Company $company): void
    {
        $company->forceFill(['telegram_code' => null])->save();
    }

    public function accounts(): Collection
    {
        return TelegramAccount::orderByDesc('activated_at')->get();
    }

    public function setRevoked(int $id, bool $revoked): TelegramAccount
    {
        $account = TelegramAccount::findOrFail($id);
        $account->update(['revoked_at' => $revoked ? now() : null]);

        return $account;
    }

    /** Frees the Telegram user to link to another company (or to this one again, with the code). */
    public function remove(int $id): void
    {
        TelegramAccount::findOrFail($id)->delete();
    }

    /** "abcd efgh-ijkl" → "ABCD-EFGH-IJKL": typed codes are forgiving about case, spaces and dashes. */
    private function companyForCode(string $code): ?Company
    {
        $chars = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
        if (strlen($chars) !== 12) {
            return null;
        }

        $company = Company::firstWhere('telegram_code', implode('-', str_split($chars, 4)));

        return $company?->isActive() ? $company : null;
    }
}
