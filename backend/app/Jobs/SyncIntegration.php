<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Service\MercadoPagoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One company's sync (payments + the Pix/transfer reports that are ready). One job per integration:
 * a slow or failing company never holds back the others, and a second one for the same integration
 * is dropped while one is waiting (ShouldBeUnique). A provider error is recorded on the card by
 * the service (last_error), so only a crash is retried.
 */
class SyncIntegration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $integrationId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->integrationId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(MercadoPagoService $mercadoPago): void
    {
        $integration = Integration::runnable($this->integrationId);

        if ($integration) {
            $mercadoPago->syncQuietly($integration);
        }
    }
}
