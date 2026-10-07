<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): array
    {
        $user = User::factory()->master()->create(['password' => 'secret123']);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->json('token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_authenticated_user_can_crud_users(): void
    {
        [, $headers] = $this->actingUser();

        $created = $this->postJson('/api/users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ], $headers)->assertCreated()->json();

        $this->assertNotEquals('secret123', $created['password'] ?? null, 'password should never be returned raw');

        $this->putJson("/api/users/{$created['id']}", ['name' => 'Jane Updated'], $headers)
            ->assertOk()
            ->assertJsonFragment(['name' => 'Jane Updated']);

        $this->deleteJson("/api/users/{$created['id']}", [], $headers)->assertOk();
        $this->getJson("/api/users/{$created['id']}", $headers)->assertNotFound();
    }

    public function test_updating_a_user_records_activity_log_without_password(): void
    {
        [$user, $headers] = $this->actingUser();

        $this->putJson("/api/users/{$user->id}", ['name' => 'Renamed'], $headers)->assertOk();

        $response = $this->getJson("/api/activity-log?subject_type=user&subject_id={$user->id}", $headers)
            ->assertOk()
            ->json();

        $this->assertNotEmpty($response);
        $fields = collect($response[0]['changes'])->pluck('field');
        $this->assertTrue($fields->contains('name'));
        $this->assertFalse($fields->contains('password'));
    }
}
