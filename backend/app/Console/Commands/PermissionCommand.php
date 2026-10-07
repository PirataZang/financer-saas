<?php

namespace App\Console\Commands;

use App\Service\PermissionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PermissionCommand extends Command
{
    protected $signature = 'permission
        {operation=list : list | create | update | delete | sync}
        {key? : Chave da permissão (modulo.acao)}
        {--description= : Descrição exibida na tela de permissões}
        {--module-name= : Nome de exibição do módulo (obrigatório para módulo novo)}
        {--rename= : Nova chave (update)}
        {--force : Não pede confirmação (delete)}';

    protected $description = 'Gerencia as permissões do sistema (config/permissions.php + banco)';

    public function handle(PermissionService $permissions): int
    {
        try {
            return match ($this->argument('operation')) {
                'list' => $this->list($permissions),
                'create' => $this->create($permissions),
                'update' => $this->update($permissions),
                'delete' => $this->delete($permissions),
                'sync' => $this->sync($permissions),
                default => throw new InvalidArgumentException(
                    'Operação inválida. Use: list, create, update, delete ou sync.'
                ),
            };
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(PermissionService $permissions): int
    {
        $rows = $permissions->list()->map(fn ($p) => [
            $p->key, $p->module_name, $p->description, $p->users_count,
        ]);

        if ($rows->isEmpty()) {
            $this->warn('Nenhuma permissão no banco. Rode `php artisan permission sync`.');

            return self::SUCCESS;
        }

        $this->table(['Chave', 'Módulo', 'Descrição', 'Usuários'], $rows);

        return self::SUCCESS;
    }

    private function create(PermissionService $permissions): int
    {
        $key = $this->argument('key') ?? $this->ask('Chave da permissão (modulo.acao, ex.: leads.view)');
        [$module, $action] = $permissions->splitKey((string) $key);

        $known = $permissions->definitions()[$module]['name'] ?? null;
        $moduleName = $this->option('module-name') ?? $known
            ?? $this->ask("Módulo novo \"{$module}\". Nome de exibição (ex.: Leads)");
        $description = $this->option('description') ?? $this->ask('Descrição', ucfirst("{$action} {$moduleName}"));

        $permission = $permissions->create($module, $action, (string) $description, $moduleName);
        $this->info("Permissão {$permission->key} criada no config e no banco.");

        return self::SUCCESS;
    }

    private function update(PermissionService $permissions): int
    {
        $key = $this->argument('key') ?? $this->ask('Chave da permissão a alterar');
        $current = $permissions->findOrFail((string) $key);

        $rename = $this->option('rename');
        $description = $this->option('description');
        if ($rename === null && $description === null) {
            $rename = $this->ask('Nova chave (enter mantém)', $current->key);
            $description = $this->ask('Nova descrição (enter mantém)', $current->description);
        }

        $permission = $permissions->update($current->key, $rename, $description, $this->option('module-name'));
        $this->info("Permissão {$permission->key} atualizada (usuários vinculados preservados).");

        return self::SUCCESS;
    }

    private function delete(PermissionService $permissions): int
    {
        $key = $this->argument('key') ?? $this->ask('Chave da permissão a excluir');
        $permission = $permissions->findOrFail((string) $key);
        $users = $permission->users()->count();

        $this->warn("{$permission->key} está atribuída a {$users} usuário(s); o vínculo será removido.");
        if (!$this->option('force') && !$this->confirm("Excluir {$permission->key} do config e do banco?")) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $permissions->delete($permission->key);
        $this->info("Permissão {$permission->key} excluída.");

        return self::SUCCESS;
    }

    private function sync(PermissionService $permissions): int
    {
        $result = $permissions->sync();
        $this->info("Sincronizado: {$result['created']} criada(s), {$result['updated']} atualizada(s), {$result['deleted']} removida(s).");

        return self::SUCCESS;
    }
}
