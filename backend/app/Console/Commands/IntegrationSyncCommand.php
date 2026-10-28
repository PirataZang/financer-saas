<?php

namespace App\Console\Commands;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Service\MercadoPagoService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Pulls every active integration of every company (scheduled in bootstrap/app.php). A failing
 * company is recorded on its card (last_error) and doesn't stop the others.
 */
class IntegrationSyncCommand extends Command
{
    protected $signature = 'integrations:sync';

    protected $description = 'Sincroniza as integrações ativas (Mercado Pago) de todas as empresas';

    public function handle(MercadoPagoService $mercadoPago): int
    {
        $integrations = Integration::withoutGlobalScope('company')
            ->where('provider', IntegrationProvider::MercadoPago)
            ->where('active', true)
            ->with('company')
            ->get()
            ->filter(fn (Integration $integration) => $integration->company?->isActive());

        foreach ($integrations as $integration) {
            try {
                $result = $mercadoPago->sync($integration);
                $this->line("empresa {$integration->company_id}: {$result['payments']} pagamento(s)");
            } catch (RuntimeException $e) {
                $this->warn("empresa {$integration->company_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
