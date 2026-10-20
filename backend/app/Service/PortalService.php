<?php

namespace App\Service;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Transaction;
use App\Notifications\PortalCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Portal do cliente: read-only access of a company's customers to their projects, bills and the
 * company's announcements. The company comes from the portal address (/portal/<slug>); the customer
 * proves they own the e-mail with a one-time code sent to it, then gets a bearer session
 * (PortalMiddleware), stored hashed like users.token. Data methods run scoped to the customer's company.
 */
class PortalService
{
    private const CODE_TTL_MINUTES = 10;

    private const CODE_MAX_ATTEMPTS = 5;

    private const TOKEN_TTL_DAYS = 30;

    /** Active company of a portal address; unknown, revoked or expired = the same plain 404. */
    public function company(string $slug): Company
    {
        $company = Company::where('portal_slug', $slug)->first();
        abort_unless($company?->isActive(), 404);

        return $company;
    }

    /** What the login screen shows before anyone is known: the company's name and color. */
    public function brand(Company $company): array
    {
        $appearance = SettingsService::for($company)['appearance'];

        return ['name' => self::brandName($company), 'primary_color' => $appearance['primary_color']];
    }

    /** First step: the e-mail must belong to an active customer of this company. Returns it masked. */
    public function sendCode(Company $company, string $email): string
    {
        $customer = $this->customerByEmail($company, $email);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);

        // A new code replaces the previous one (and its attempt count). Scalars only: Redis won't unserialize objects.
        Cache::put(self::codeKey($customer), ['hash' => hash('sha256', $code), 'attempts' => 0, 'expires_at' => $expiresAt->getTimestamp()], $expiresAt);

        try {
            $customer->notify(new PortalCode($code, self::brandName($company), self::CODE_TTL_MINUTES));
        } catch (Throwable $e) {
            Cache::forget(self::codeKey($customer));
            Log::error('portal.code.mail_failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['email' => ['Não foi possível enviar o código agora. Tente novamente em instantes.']]);
        }

        return self::maskEmail($customer->email);
    }

    /**
     * Second step: e-mail + code. A code works once, for CODE_TTL_MINUTES, and dies after
     * CODE_MAX_ATTEMPTS wrong tries. Logging in again (another device) ends the previous session.
     *
     * @return array{token: string, expire_at: string}
     */
    public function login(Company $company, string $email, string $code): array
    {
        $customer = $this->customerByEmail($company, $email);
        $key = self::codeKey($customer);
        $stored = Cache::get($key);
        $invalid = ['code' => ['Código inválido ou expirado. Confira ou peça um novo.']];

        if (!$stored) {
            throw ValidationException::withMessages($invalid);
        }

        if (!hash_equals($stored['hash'], hash('sha256', trim($code)))) {
            if (++$stored['attempts'] >= self::CODE_MAX_ATTEMPTS) {
                Cache::forget($key);
                throw ValidationException::withMessages(['code' => ['Muitas tentativas erradas. Peça um novo código.']]);
            }
            Cache::put($key, $stored, max($stored['expires_at'] - now()->getTimestamp(), 1));
            throw ValidationException::withMessages($invalid);
        }

        Cache::forget($key);
        $token = Str::random(64);
        $expireAt = now()->addDays(self::TOKEN_TTL_DAYS);
        $customer->forceFill(['portal_token' => hash('sha256', $token), 'portal_expire_at' => $expireAt])->save();

        return ['token' => $token, 'expire_at' => $expireAt->toIso8601String()];
    }

    /** Customer of a portal session (active, not deleted, not expired), or null. */
    public function resolve(string $token): ?Customer
    {
        $customer = Customer::withoutGlobalScope('company')->where('portal_token', hash('sha256', $token))->first();

        if (!$customer?->active || !$customer->portal_expire_at || $customer->portal_expire_at->isPast()) {
            return null;
        }

        return $customer;
    }

    public function logout(Customer $customer): void
    {
        $customer->forceFill(['portal_token' => null, 'portal_expire_at' => null])->save();
    }

    public function me(Customer $customer): array
    {
        $company = $customer->company;
        $settings = SettingsService::for($company);

        return [
            'customer' => $customer->only(['id', 'name', 'email']),
            'company' => [
                'name' => self::brandName($company),
                'slug' => $company->portal_slug,
                'primary_color' => $settings['appearance']['primary_color'],
                'payment' => $settings['portal'],
            ],
        ];
    }

    public function projects(Customer $customer): array
    {
        // Ongoing first: finished and cancelled projects sink to the bottom.
        return Project::with('steps')->where('customer_id', $customer->id)
            ->orderByRaw("case when status in ('done', 'cancelled') then 1 else 0 end")
            ->latest()->latest('id')->get()
            ->map(fn (Project $project) => $this->projectPayload($project))
            ->all();
    }

    /** One of the customer's own projects (anyone else's is a 404) with its steps and announcements. */
    public function project(Customer $customer, int $id): array
    {
        $project = Project::with(['steps', 'announcements' => fn ($q) => $q->latest()->latest('id')])
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        return [
            ...$this->projectPayload($project),
            'announcements' => $project->announcements->map(fn (Announcement $a) => $this->announcementPayload($a))->all(),
        ];
    }

    /** General announcements + those of the customer's projects, newest first. */
    public function announcements(Customer $customer): array
    {
        // ponytail: newest 50, no pagination; add a cursor when a company posts more than that.
        return Announcement::with('project:id,name')
            ->where(fn ($q) => $q->whereNull('project_id')->orWhereIn('project_id', Project::where('customer_id', $customer->id)->select('id')))
            ->latest()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Announcement $a) => $this->announcementPayload($a))
            ->all();
    }

    /**
     * Bills = incomes linked to the customer: pending ones (oldest due first, overdue flagged in the
     * company's timezone) and the latest paid ones.
     */
    public function bills(Customer $customer): array
    {
        $today = now(SettingsService::for($customer->company)['finance']['timezone'])->toDateString();
        $query = fn () => Transaction::where('customer_id', $customer->id)->where('type', TransactionType::Income);

        $pending = $query()->where('status', TransactionStatus::Pending)->orderBy('date')->orderBy('id')->get();
        $paid = $query()->where('status', TransactionStatus::Paid)->orderByDesc('date')->orderByDesc('id')->limit(24)->get();

        $bill = fn (Transaction $t) => [
            ...$t->only(['id', 'description', 'amount', 'date']),
            'status' => $t->status->value,
            'overdue' => $t->status === TransactionStatus::Pending && $t->date < $today,
        ];

        return [
            'total_pending' => number_format((float) $pending->sum('amount'), 2, '.', ''),
            'pending' => $pending->map($bill)->all(),
            'paid' => $paid->map($bill)->all(),
        ];
    }

    /** Only what the customer may see: no internal ids, timestamps or other customers' data. */
    private function projectPayload(Project $project): array
    {
        return [
            ...$project->only(['id', 'name', 'description', 'progress']),
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
            'status' => $project->status->value,
            'badge' => self::badge($project->status),
            'steps' => $project->steps->map(fn ($step) => [
                'name' => $step->name,
                'status' => $step->status->value,
                'due_date' => $step->due_date?->toDateString(),
                'badge' => self::badge($step->status),
            ])->all(),
        ];
    }

    private function announcementPayload(Announcement $announcement): array
    {
        return [
            ...$announcement->only(['id', 'title', 'body', 'created_at']),
            'project' => $announcement->project?->only(['id', 'name']),
        ];
    }

    private function customerByEmail(Company $company, string $email): Customer
    {
        $matches = Company::as($company->id, fn () => Customer::where('email', mb_strtolower(trim($email)))->where('active', true)->limit(2)->get());
        $name = self::brandName($company);

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['email' => ["E-mail não encontrado. Confira o endereço ou fale com {$name}."]]);
        }

        // ponytail: customer e-mails aren't unique per company; two active matches stop here instead of guessing.
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['email' => ["Há mais de um cadastro com este e-mail. Fale com {$name} para acertar."]]);
        }

        return $matches->first();
    }

    private static function codeKey(Customer $customer): string
    {
        return "portal:code:{$customer->id}";
    }

    private static function brandName(Company $company): string
    {
        return SettingsService::for($company)['appearance']['brand_name'] ?: ($company->trade_name ?: $company->name);
    }

    /** "joao.silva@x.com" → "jo•••••••@x.com": confirms where the code went without spelling it out. */
    private static function maskEmail(string $email): string
    {
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 2).str_repeat('•', max(mb_strlen($user) - 2, 1)).'@'.$domain;
    }

    private static function badge(\BackedEnum $status): array
    {
        return ['label' => $status->label(), 'color' => $status->color(), 'icon' => $status->icon()];
    }
}
