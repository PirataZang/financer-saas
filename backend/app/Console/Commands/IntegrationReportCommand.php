<?php

namespace App\Console\Commands;

use App\Jobs\RequestIntegrationReport;
use App\Models\Integration;
use Illuminate\Console\Command;

/**
 * Queues one RequestIntegrationReport job per active integration (the job itself skips companies that
 * did not turn on Pix sent). Scheduled a few times a day in bootstrap/app.php.
 */
class IntegrationReportCommand extends Command
{
    protected $signature = 'integrations:report';

    protected $description = 'Enfileira o pedido do relatório de Pix e transferências enviados do Mercado Pago';

    public function handle(): int
    {
        $integrations = Integration::runnable();
        $integrations->each(fn (Integration $integration) => RequestIntegrationReport::dispatch($integration->id));

        $this->line("{$integrations->count()} pedido(s) de relatório enfileirado(s)");

        return self::SUCCESS;
    }
}
