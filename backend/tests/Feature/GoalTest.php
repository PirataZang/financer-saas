<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'finance.goal.view', 'finance.goal.create', 'finance.goal.update', 'finance.goal.delete',
        'finance.category.view', 'finance.category.update', 'finance.category.delete',
        'finance.transaction.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionService::class)->sync();
        Date::setTestNow('2026-10-21 12:00:00');
    }

    private function headers(array $permissions = self::ALL): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->syncPermissions($permissions);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function goal(array $headers, array $overrides = []): array
    {
        return $this->postJson('/api/finance/goals', [
            'name' => 'Reserva', 'icon' => '🏦', 'color' => '#059669', 'type' => 'saving',
            'target_amount' => '1000.00', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', ...$overrides,
        ], $headers)->assertCreated()->json();
    }

    private function entry(array $headers, int $categoryId, string $type, string $amount, array $overrides = []): void
    {
        $this->postJson('/api/finance/transactions', [
            'type' => $type, 'amount' => $amount, 'description' => 'Meta', 'category_id' => $categoryId, 'date' => '2026-10-10', ...$overrides,
        ], $headers)->assertCreated();
    }

    public function test_saving_goal_creates_its_category_and_counts_aportes_minus_resgates(): void
    {
        $headers = $this->headers();
        $goal = $this->goal($headers);

        $this->assertDatabaseHas('finance_categories', ['id' => $goal['category_id'], 'name' => 'Reserva', 'type' => 'both']);
        $this->assertSame('in_progress', $goal['progress']['status']);

        $this->entry($headers, $goal['category_id'], 'expense', '700.00');
        $this->entry($headers, $goal['category_id'], 'income', '100.00');
        $this->entry($headers, $goal['category_id'], 'expense', '50.00', ['status' => 'pending']);
        // Outside the goal's period: not counted.
        $this->entry($headers, $goal['category_id'], 'expense', '999.00', ['date' => '2026-09-30']);

        $this->getJson("/api/finance/goals/{$goal['id']}", $headers)->assertOk()
            ->assertJsonPath('progress.current', 600)
            ->assertJsonPath('progress.pending', 50)
            ->assertJsonPath('progress.percent', 60)
            ->assertJsonPath('category.name', 'Reserva');

        $this->entry($headers, $goal['category_id'], 'expense', '400.00');
        $this->getJson('/api/finance/goals', $headers)->assertOk()->assertJsonPath('0.progress.status', 'reached');
    }

    public function test_spending_goal_is_a_ceiling(): void
    {
        $headers = $this->headers();
        $goal = $this->goal($headers, ['name' => 'Mercado', 'type' => 'spending', 'target_amount' => '500.00', 'end_date' => '2026-10-15']);
        $this->assertDatabaseHas('finance_categories', ['id' => $goal['category_id'], 'type' => 'expense']);

        // Deadline passed within the ceiling = reached.
        $this->entry($headers, $goal['category_id'], 'expense', '500.00');
        $this->getJson("/api/finance/goals/{$goal['id']}", $headers)->assertJsonPath('progress.status', 'reached');

        $this->entry($headers, $goal['category_id'], 'expense', '0.01');
        $this->getJson("/api/finance/goals/{$goal['id']}", $headers)->assertJsonPath('progress.status', 'missed');
    }

    public function test_saving_goal_missed_after_deadline(): void
    {
        $headers = $this->headers();
        $goal = $this->goal($headers, ['end_date' => '2026-10-20']);

        $this->getJson("/api/finance/goals/{$goal['id']}", $headers)->assertJsonPath('progress.status', 'missed');
    }

    public function test_update_syncs_the_category_and_delete_removes_both(): void
    {
        $headers = $this->headers();
        $goal = $this->goal($headers);

        $this->putJson("/api/finance/goals/{$goal['id']}", ['name' => 'Viagem', 'type' => 'spending', 'target_amount' => '2000', 'start_date' => '2026-10-01'], $headers)
            ->assertOk()->assertJsonPath('category.name', 'Viagem')->assertJsonPath('type', 'spending');
        $this->assertDatabaseHas('finance_categories', ['id' => $goal['category_id'], 'name' => 'Viagem', 'type' => 'expense']);

        // Its own name doesn't collide with itself; another category's does.
        $this->putJson("/api/finance/goals/{$goal['id']}", ['name' => 'Viagem'], $headers)->assertOk();
        $other = $this->goal($headers, ['name' => 'Carro']);
        $this->putJson("/api/finance/goals/{$other['id']}", ['name' => 'Viagem'], $headers)->assertJsonValidationErrors('name');

        $this->deleteJson("/api/finance/goals/{$goal['id']}", [], $headers)->assertOk();
        $this->assertSoftDeleted('finance_goals', ['id' => $goal['id']]);
        $this->assertSoftDeleted('finance_categories', ['id' => $goal['category_id']]);
    }

    public function test_category_of_a_goal_cannot_be_deleted_or_retyped_directly(): void
    {
        $headers = $this->headers();
        $goal = $this->goal($headers);

        $this->deleteJson("/api/finance/categories/{$goal['category_id']}", [], $headers)->assertJsonValidationErrors('category');
        $this->putJson("/api/finance/categories/{$goal['category_id']}", ['type' => 'income'], $headers)->assertJsonValidationErrors('category');
        // Cosmetic edits still work.
        $this->putJson("/api/finance/categories/{$goal['category_id']}", ['color' => '#2563eb'], $headers)->assertOk();
    }

    public function test_validation_and_permissions(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/finance/goals', ['name' => 'X', 'type' => 'other', 'target_amount' => '0', 'start_date' => '2026-10-10', 'end_date' => '2026-10-01'], $headers)
            ->assertJsonValidationErrors(['name', 'type', 'target_amount', 'end_date']);

        $viewer = $this->headers(['finance.goal.view']);
        $this->getJson('/api/finance/goals', $viewer)->assertOk();
        $this->postJson('/api/finance/goals', [], $viewer)->assertForbidden();
        $this->assertSame(0, Transaction::count());
    }
}
