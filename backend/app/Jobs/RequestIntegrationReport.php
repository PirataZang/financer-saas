<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Service\MercadoPagoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks Mercado Pago for the report of sent Pix/transfers and schedules the sync that reads it
 * (it is ready in about a minute), so nobody has to come back and click again.
 */
class RequestIntegrationReport implements ShouldQueue
{
    use Queueable;

    /** Seconds Mercado Pago takes to generate the report. */
    public const READY_AFTER = 90;

    public int $tries = 1;

    public function __construct(public int $integrationId)
    {
    }

    public function handle(MercadoPagoService $mercadoPago): void
    {
        $integration = Integration::runnable($this->integrationId);

        if ($integration && $mercadoPago->requestReport($integration)) {
            SyncIntegration::dispatch($integration->id)->delay(now()->addSeconds(self::READY_AFTER));
        }
    }
}
