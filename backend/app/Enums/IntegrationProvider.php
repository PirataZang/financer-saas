<?php

namespace App\Enums;

/**
 * Providers of Configurações › Integrações. A new integration = a case here, its service and its
 * card in the frontend catalog (frontend/src/integrations/catalog.js).
 */
enum IntegrationProvider: string
{
    case MercadoPago = 'mercado_pago';

    public function label(): string
    {
        return match ($this) {
            self::MercadoPago => 'Mercado Pago',
        };
    }
}
