<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionService::class)->sync();
        $this->seedFinanceCategories();

        // Runs on every container boot (entrypoint), so skip if already seeded.
        if (User::where('email', 'test@example.com')->exists()) {
            return;
        }

        User::factory()->master()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '1234',
        ]);

        // A few extra users so the Users grid isn't empty on first run.
        User::factory(6)->create();
    }

    /**
     * Starter categories, only on an empty table (runs on every boot). They are ordinary
     * records afterwards: edit, deactivate or delete them in Financeiro > Categorias.
     */
    private function seedFinanceCategories(): void
    {
        if (Category::withTrashed()->exists()) {
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
            Category::create(['name' => $name, 'icon' => $icon, 'color' => $color, 'type' => $type, 'sort_order' => $order]);
        }
    }
}
