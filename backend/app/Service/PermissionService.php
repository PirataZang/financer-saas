<?php

namespace App\Service;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Keeps config/permissions.php (declarative source) and the permissions table in sync.
 * Every write goes through here so the file and the database never diverge.
 */
class PermissionService
{
    private const SEGMENT = '[a-z][a-z0-9-]*';

    private string $configPath;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? config_path('permissions.php');
    }

    /**
     * @return array<string, array{name: string, actions: array<string, string>}>
     */
    public function definitions(): array
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->configPath, true);
        }

        return require $this->configPath;
    }

    /**
     * Upserts every configured permission and deletes the ones no longer declared
     * (their user assignments go with them via FK cascade).
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    public function sync(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        DB::transaction(function () use (&$result) {
            $keys = [];

            foreach ($this->definitions() as $module => $definition) {
                foreach ($definition['actions'] as $action => $description) {
                    $key = "{$module}.{$action}";
                    $keys[] = $key;

                    $permission = Permission::firstOrNew(['key' => $key]);
                    $permission->fill([
                        'module' => $module,
                        'module_name' => $definition['name'],
                        'action' => $action,
                        'description' => $description,
                    ]);

                    if (!$permission->exists) {
                        $result['created']++;
                    } elseif ($permission->isDirty()) {
                        $result['updated']++;
                    }

                    $permission->save();
                }
            }

            $obsolete = Permission::whereNotIn('key', $keys)->get();
            $this->forgetCacheFor($obsolete->pluck('id'));
            $result['deleted'] = Permission::whereIn('id', $obsolete->pluck('id'))->delete();
        });

        return $result;
    }

    /**
     * Permissions grouped by module, in config order, for the user permissions screen.
     */
    public function catalog(): Collection
    {
        return Permission::orderBy('id')->get()
            ->groupBy('module')
            ->map(fn (Collection $permissions, string $module) => [
                'module' => $module,
                'name' => $permissions->first()->module_name,
                'permissions' => $permissions->map->only(['key', 'action', 'description'])->values(),
            ])
            ->values();
    }

    public function list(): Collection
    {
        return Permission::withCount('users')->orderBy('module')->orderBy('id')->get();
    }

    public function create(string $module, string $action, string $description, ?string $moduleName = null): Permission
    {
        $this->assertValidKey($module, $action);
        $key = "{$module}.{$action}";
        $definitions = $this->definitions();

        if (isset($definitions[$module]['actions'][$action]) || Permission::where('key', $key)->exists()) {
            throw new InvalidArgumentException("A permissão {$key} já existe.");
        }

        $moduleName = $moduleName ?: ($definitions[$module]['name'] ?? null);
        if (!$moduleName) {
            throw new InvalidArgumentException("Módulo novo ({$module}): informe o nome de exibição do módulo.");
        }

        $definitions[$module]['name'] = $moduleName;
        $definitions[$module]['actions'][$action] = $description;

        return DB::transaction(function () use ($definitions, $key) {
            $this->write($definitions);
            $this->sync();

            return Permission::where('key', $key)->firstOrFail();
        });
    }

    /**
     * Changes description and/or key. A key change renames the existing row in place,
     * so users keep the permission under its new key.
     */
    public function update(string $key, ?string $newKey = null, ?string $description = null, ?string $moduleName = null): Permission
    {
        $permission = $this->findOrFail($key);
        [$module, $action] = [$permission->module, $permission->action];
        [$newModule, $newAction] = $newKey ? $this->splitKey($newKey) : [$module, $action];
        $newKey = "{$newModule}.{$newAction}";
        $definitions = $this->definitions();

        if ($newKey !== $key && (isset($definitions[$newModule]['actions'][$newAction]) || Permission::where('key', $newKey)->exists())) {
            throw new InvalidArgumentException("A permissão {$newKey} já existe.");
        }

        $description ??= $definitions[$module]['actions'][$action] ?? $permission->description;
        $moduleName = $moduleName ?: ($definitions[$newModule]['name'] ?? ($newModule === $module ? $permission->module_name : null));
        if (!$moduleName) {
            throw new InvalidArgumentException("Módulo novo ({$newModule}): informe o nome de exibição do módulo.");
        }

        unset($definitions[$module]['actions'][$action]);
        if ($module !== $newModule && empty($definitions[$module]['actions'])) {
            unset($definitions[$module]);
        }
        $definitions[$newModule]['name'] = $moduleName;
        $definitions[$newModule]['actions'][$newAction] = $description;

        return DB::transaction(function () use ($permission, $definitions, $newKey, $newModule, $newAction) {
            $permission->update(['key' => $newKey, 'module' => $newModule, 'action' => $newAction]);
            $this->write($definitions);
            $this->sync();
            $this->forgetCacheFor(collect([$permission->id]));

            return $permission->fresh();
        });
    }

    /**
     * Removes the permission from config and database, detaching it from every user.
     *
     * @return int number of users that had the permission
     */
    public function delete(string $key): int
    {
        $permission = $this->findOrFail($key);
        $definitions = $this->definitions();

        unset($definitions[$permission->module]['actions'][$permission->action]);
        if (empty($definitions[$permission->module]['actions'])) {
            unset($definitions[$permission->module]);
        }

        return DB::transaction(function () use ($permission, $definitions) {
            $affected = $permission->users()->count();
            $this->forgetCacheFor(collect([$permission->id]));
            $permission->delete();
            $this->write($definitions);

            return $affected;
        });
    }

    public function findOrFail(string $key): Permission
    {
        return Permission::where('key', $key)->first()
            ?? throw new InvalidArgumentException("A permissão {$key} não existe.");
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function splitKey(string $key): array
    {
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Chave inválida: {$key}. Use o formato modulo.acao.");
        }
        $this->assertValidKey(...$parts);

        return $parts;
    }

    private function assertValidKey(string $module, string $action): void
    {
        $segment = self::SEGMENT;
        if (!preg_match("/^{$segment}$/", $module) || !preg_match("/^{$segment}(\\.{$segment})*$/", $action)) {
            throw new InvalidArgumentException(
                "Chave inválida: {$module}.{$action}. Use letras minúsculas, números e hífen (ex.: leads.view)."
            );
        }
    }

    private function forgetCacheFor(Collection $permissionIds): void
    {
        DB::table('permission_user')->whereIn('permission_id', $permissionIds)->distinct()->pluck('user_id')
            ->each(fn (int $userId) => cache()->forget(User::permissionCacheKey($userId)));
    }

    private function write(array $definitions): void
    {
        $php = "<?php\n\n/*\n"
            ."| Source of truth for every permission in the system.\n"
            ."| Key format: \"{module}.{action}\". After editing by hand, run `php artisan permission sync`;\n"
            ."| `php artisan permission create|update|delete` edits this file and the database together.\n"
            ."*/\n\nreturn [\n";

        foreach ($definitions as $module => $definition) {
            $php .= '    '.var_export($module, true)." => [\n"
                .'        \'name\' => '.var_export($definition['name'], true).",\n"
                ."        'actions' => [\n";
            foreach ($definition['actions'] as $action => $description) {
                $php .= '            '.var_export($action, true).' => '.var_export($description, true).",\n";
            }
            $php .= "        ],\n    ],\n";
        }

        file_put_contents($this->configPath, $php."];\n", LOCK_EX);
    }
}
