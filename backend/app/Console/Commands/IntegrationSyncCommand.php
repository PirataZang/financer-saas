<?php

namespace App\Console\Commands;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use Illuminate\Console\Command;

/**
 * Queues one SyncIntegration job per active integration (scheduled in bootstrap/app.php; the `queue`
 * container runs them). A failing company is recorded on its card (last_error) and doesn't stop the others.
 */
class IntegrationSyncCommand extends Command
{
    protected $signature = 'integrations:sync {--now : Sincroniza aqui mesmo, sem passar pela fila}';

    protected $description = 'Enfileira a sincronização das integrações ativas (Mercado Pago) de todas as empresas';

    public function handle(): int
    {
        $integrations = Integration::runnable();

        foreach ($integrations as $integration) {
            $this->option('now') ? SyncIntegration::dispatchSync($integration->id) : SyncIntegration::dispatch($integration->id);
        }

        $this->line("{$integrations->count()} sincronização(ões) ".($this->option('now') ? 'executada(s)' : 'enfileirada(s)'));

        return self::SUCCESS;
    }
}
