<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionService::class)->sync();
        $default = Company::find(Company::DEFAULT_ID);
        $this->seedFinanceCategories($default);

        // Runs on every container boot (entrypoint), so skip if already seeded.
        // No current company in a seeder: look across all of them.
        if (User::withoutGlobalScope('company')->where('email', 'test@example.com')->exists()) {
            return;
        }

        // WithoutModelEvents also skips BelongsToCompany's creating hook: every row names its
        // company explicitly (->for). Company 1 (Empresa Padrão) comes from its migration;
        // masters live there and are only ever created/changed in the database.
        User::factory()->master()->for($default)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '1234',
        ]);

        // A few extra users so the Users grid isn't empty on first run.
        User::factory(6)->for($default)->create();

        // A second, active company so the company switcher has something to switch to.
        $demo = Company::create([
            'name' => 'Empresa Exemplo Ltda',
            'trade_name' => 'Empresa Exemplo',
            'country' => 'Brasil',
            'expires_at' => now()->addDays(30)->toDateString(),
        ]);
        $demo->forceFill(['token' => Str::random(40)])->save();

        User::factory()->for($demo)->create([
            'name' => 'Usuário Exemplo',
            'email' => 'exemplo@example.com',
            'password' => '1234',
        ])->syncPermissions([
            'dashboard.view', 'customers.view', 'customers.create', 'customers.update',
            'finance.dashboard', 'finance.category.view', 'finance.transaction.view', 'finance.transaction.create',
        ]);

        User::factory(2)->for($demo)->create();
        $this->seedFinanceCategories($demo);
    }

    /**
     * Starter categories, only for a company that has none (runs on every boot). They are ordinary
     * records afterwards: edit, deactivate or delete them in Financeiro > Categorias.
     */
    private function seedFinanceCategories(Company $company): void
    {
        if (Category::withoutGlobalScope('company')->withTrashed()->whereBelongsTo($company)->exists()) {
            return;
        }

        $categories = [
            ['Salário', '💼', '#059669', 'income'],
            ['Investimentos', '📈', '#0891b2', 'both'],
            ['Mercado', '🛒', '#d97706', 'expense'],
            ['Alimentação', '🍔', '#ea580c', 'expense'],
            ['Moradia', '🏠', '#4f46e5', 'expense'],
            ['Contas', '🧾', '#2563eb', 'expense'],
            ['Transporte', '🚗', '#0d9488', 'expense'],
            ['Entretenimento', '🎮', '#9333ea', 'expense'],
            ['Saúde', '🩺', '#db2777', 'expense'],
            ['Educação', '📚', '#65a30d', 'expense'],
            ['Outros', '📦', '#64748b', 'both'],
        ];

        foreach ($categories as $order => [$name, $icon, $color, $type]) {
            Category::make(['name' => $name, 'icon' => $icon, 'color' => $color, 'type' => $type, 'sort_order' => $order])
                ->company()->associate($company)->save();
        }
    }
}
