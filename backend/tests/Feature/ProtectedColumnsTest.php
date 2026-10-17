<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Model;
use App\Models\User;
use App\Service\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ProtectedColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** Runs $callback as if serving an HTTP request (tests run in the console). */
    private function duringRequest(callable $callback): void
    {
        $property = new \ReflectionProperty(app(), 'isRunningInConsole');
        $property->setValue(app(), false);

        try {
            $callback();
        } finally {
            $property->setValue(app(), true);
        }
    }

    public function test_every_model_extends_the_base_model(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if ($class !== Model::class) {
                $this->assertTrue(is_subclass_of($class, Model::class), "{$class} deve estender App\\Models\\Model.");
            }
        }
    }

    public function test_protected_columns_never_leave_in_responses(): void
    {
        $user = User::factory()->master()->create();
        $user->forceFill(['token' => hash('sha256', 'x')])->save();
        $company = Company::find(Company::DEFAULT_ID);

        $this->assertArrayNotHasKey('token', $user->toArray());
        $this->assertArrayNotHasKey('master', $user->toArray());
        $this->assertArrayNotHasKey('token', $company->toArray());

        // Only on purpose: the backend may reveal one explicitly (company token for masters).
        $this->assertArrayHasKey('token', $company->makeVisible('token')->toArray());
    }

    public function test_request_data_cannot_set_protected_columns(): void
    {
        $user = new User;
        $user->fill(['name' => 'X', 'master' => true, 'token' => 'forged', 'company_id' => 99]);
        $this->assertSame('X', $user->name);
        $this->assertNull($user->master);
        $this->assertNull($user->token);

        $company = Company::create(['name' => 'Nova', 'token' => 'forged']);
        $this->assertNull($company->fresh()->token);

        // Backend code writes them on purpose.
        $company->regenerateToken();
        $this->assertSame(40, strlen($company->fresh()->token));
    }

    public function test_api_cannot_promote_or_read_master(): void
    {
        app(PermissionService::class)->sync();
        [$admin, $other] = User::factory(2)->create(['password' => 'secret123']);
        $admin->syncPermissions(['users.view', 'users.update']);
        $token = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->putJson("/api/users/{$other->id}", ['name' => 'Y', 'master' => true, 'token' => 'forged'], $headers)
            ->assertOk()->assertJsonMissingPath('master')->assertJsonMissingPath('token');

        $this->assertFalse($other->fresh()->master);
        $this->assertNull($other->fresh()->token);
        $this->getJson('/api/auth/me', $headers)->assertJsonPath('master', false); // own flag only
    }

    public function test_locked_columns_cannot_change_during_a_request_by_any_code_path(): void
    {
        $user = User::factory()->create();

        $this->duringRequest(function () use ($user) {
            $this->assertThrows(fn () => $user->forceFill(['master' => true])->save(), AccessDeniedHttpException::class);
            $this->assertThrows(fn () => User::factory()->master()->create(), AccessDeniedHttpException::class);
            // The rejected change stays dirty on that instance; other columns save fine.
            User::find($user->id)->forceFill(['name' => 'Ok'])->save();
        });

        $this->assertFalse($user->fresh()->master);

        // Console (seeders, artisan, tinker) is the only way in besides the database itself.
        $user->forceFill(['master' => true])->save();
        $this->assertTrue($user->fresh()->master);
    }

    public function test_protected_columns_stay_out_of_the_audit_log(): void
    {
        $company = Company::create(['name' => 'Auditada']);
        $company->regenerateToken();
        $company->update(['name' => 'Renomeada']);

        $logged = Activity::forSubject($company)->get()
            ->flatMap(fn (Activity $a) => array_keys([...$a->changes->get('attributes', []), ...$a->changes->get('old', [])]));

        $this->assertContains('name', $logged);
        $this->assertNotContains('token', $logged);
    }
}
