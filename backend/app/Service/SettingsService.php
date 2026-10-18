<?php

namespace App\Service;

use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Configurações. Company settings (appearance, finance defaults) live in companies.settings and
 * user preferences in users.preferences, both JSON: this class owns their keys, defaults and rules.
 * Everything acts on the current company (Company::current()), never on an id from the request.
 */
class SettingsService
{
    public const COMPANY_DEFAULTS = [
        'appearance' => [
            'primary_color' => '#2563eb',
            // Shown in the menu and the browser tab instead of the app name. Null = app name.
            'brand_name' => null,
        ],
        'finance' => [
            // New transactions on the web form start with this status.
            'default_status' => 'paid',
            // Period the transaction list and the finance dashboard open with (frontend utils/period.js).
            'default_period' => 'month',
            // "Today" for entries and balancetes made through the Telegram bot.
            'timezone' => 'America/Sao_Paulo',
        ],
    ];

    public const PREFERENCE_DEFAULTS = [
        // Null = the screen decides (open on wide screens).
        'sidebar_expanded' => null,
        'table_density' => 'comfortable',
        // Route opened after login; null (or one the user can't open) = first menu entry.
        'start_page' => null,
    ];

    private const PERIODS = ['today', '7d', '30d', 'month', 'lastMonth', '3m', 'year'];

    public function company(): Company
    {
        return Company::findOrFail(Company::current());
    }

    /** Settings with defaults filled in: the shape the SPA (session payload) and the bot read. */
    public static function for(Company $company): array
    {
        return self::merge(self::COMPANY_DEFAULTS, $company->settings ?? []);
    }

    public static function preferences(User $user): array
    {
        return self::merge(self::PREFERENCE_DEFAULTS, $user->preferences ?? []);
    }

    public function updateCompany(array $data): Company
    {
        $company = $this->company();
        $company->update($data);

        return $company;
    }

    public function updateSection(string $section, array $data): array
    {
        $company = $this->company();
        $settings = self::for($company);
        $settings[$section] = [...$settings[$section], ...$data];
        $company->forceFill(['settings' => $settings])->save();

        return $settings;
    }

    public function updatePreferences(User $user, array $data): array
    {
        $user->forceFill(['preferences' => [...self::preferences($user), ...$data]])->save();

        return self::preferences($user);
    }

    /** Company data editable by the company itself: CompanyService's rules minus the license. */
    public function companyRules(): array
    {
        $rules = app(CompanyService::class)->rules(Company::current());
        unset($rules['expires_at']);

        return $rules;
    }

    public function sectionRules(string $section): array
    {
        return match ($section) {
            'appearance' => [
                'primary_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'brand_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            ],
            'finance' => [
                'default_status' => ['sometimes', Rule::enum(TransactionStatus::class)],
                'default_period' => ['sometimes', Rule::in(self::PERIODS)],
                'timezone' => ['sometimes', 'timezone:all'],
            ],
        };
    }

    public function preferenceRules(): array
    {
        return [
            'sidebar_expanded' => ['sometimes', 'nullable', 'boolean'],
            'table_density' => ['sometimes', Rule::in(['comfortable', 'compact'])],
            'start_page' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:#^/[a-z0-9/_-]*$#'],
        ];
    }

    /** Known keys only: a key removed from the defaults disappears from stored settings too. */
    private static function merge(array $defaults, array $stored): array
    {
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $stored)) {
                $defaults[$key] = is_array($value) ? self::merge($value, (array) $stored[$key]) : $stored[$key];
            }
        }

        return $defaults;
    }
}
