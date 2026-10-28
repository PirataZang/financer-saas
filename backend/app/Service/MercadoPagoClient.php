<?php

namespace App\Service;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only door to the Mercado Pago API. The integration is monitoring: no payment, refund, boleto,
 * transfer or anything else that moves the account's money. Enforced here, not by convention: GET
 * only, and only to the paths in READ_PATHS. The Access Token a company pastes can do more than
 * read, so this class is the guarantee that it never does. The one POST is asking for the "Liberações"
 * report (a CSV of the account's own movements); it creates a file, it doesn't touch money.
 */
class MercadoPagoClient
{
    private const API = 'https://api.mercadopago.com';

    /** Every endpoint the integration may read. A new read = a new entry here, never a write. */
    private const READ_PATHS = ['/users/me', '/v1/payments/search', '/v1/account/release_report/list'];

    private const REPORT_PATH = '/v1/account/release_report';

    /**
     * @throws RuntimeException unreachable, unauthorized (revoked grant) or unexpected answer
     */
    public function get(string $accessToken, string $path, array $query = []): array
    {
        if (!in_array($path, self::READ_PATHS, true)) {
            throw new RuntimeException("Mercado Pago: {$path} não é uma leitura permitida.");
        }

        $response = $this->send(fn (PendingRequest $http) => $http->withToken($accessToken)->get(self::API.$path, $query), $path);

        return is_array($response->json()) ? $response->json() : $this->bad($path, $response);
    }

    /** Asks for the "Liberações" report of the period (both ends ISO 8601, UTC). It shows up in reportFiles() when ready. */
    public function requestReport(string $accessToken, string $from, string $to): void
    {
        $this->send(fn (PendingRequest $http) => $http->withToken($accessToken)->asJson()->post(self::API.self::REPORT_PATH, ['begin_date' => $from, 'end_date' => $to]), self::REPORT_PATH);
    }

    /** CSV of a report listed by /v1/account/release_report/list. */
    public function reportFile(string $accessToken, string $fileName): string
    {
        if (!preg_match('/^[\w.\-]+\.csv$/', $fileName)) {
            throw new RuntimeException('Mercado Pago: nome de relatório inválido.');
        }
        $path = self::REPORT_PATH.'/'.$fileName;

        return $this->send(fn (PendingRequest $http) => $http->withToken($accessToken)->get(self::API.$path), $path)->body();
    }

    private function send(callable $request, string $path): Response
    {
        try {
            /** @var Response $response */
            $response = $request(Http::acceptJson()
                ->timeout(config('services.mercadopago.timeout'))
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false));
        } catch (ConnectionException $e) {
            Log::warning('mercadopago.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new RuntimeException('Mercado Pago indisponível no momento. Tente novamente em instantes.', previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('O Mercado Pago recusou o acesso. Conecte a conta de novo.', 401);
        }

        return $response->successful() ? $response : $this->bad($path, $response);
    }

    private function bad(string $path, Response $response): never
    {
        // Body may echo request data: log status and the provider's error code only.
        Log::warning('mercadopago.bad_response', ['path' => $path, 'status' => $response->status(), 'error' => $response->json('error')]);
        throw new RuntimeException('Resposta inesperada do Mercado Pago.');
    }
}
