<?php

namespace App\Service;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Address lookup by Brazilian zip code (CEP). The only place that knows the provider
 * (ViaCEP today): swapping it means changing this class, not the screens or the API.
 */
class ZipCodeService
{
    private const CACHE_TTL_DAYS = 30;

    /**
     * @return array{zip_code: string, street: string, neighborhood: string, city: string, state: string, country: string}|null
     *         null when the CEP does not exist
     *
     * @throws RuntimeException when the provider is unavailable, times out or answers garbage
     */
    public function lookup(string $zipCode): ?array
    {
        $zipCode = preg_replace('/\D/', '', $zipCode);

        if ($cached = Cache::get($this->cacheKey($zipCode))) {
            return $cached;
        }

        try {
            $response = Http::timeout(config('services.viacep.timeout'))
                ->acceptJson()
                ->get(config('services.viacep.url')."/{$zipCode}/json/");
        } catch (ConnectionException $e) {
            Log::warning('ZipCodeService: provider unreachable', ['zip_code' => $zipCode, 'error' => $e->getMessage()]);
            throw new RuntimeException('Serviço de CEP indisponível.', previous: $e);
        }

        $data = $response->json();

        if ($response->successful() && is_array($data) && ($data['erro'] ?? false)) {
            return null;
        }

        if (!$response->successful() || !is_array($data) || !isset($data['cep'], $data['localidade'], $data['uf'])) {
            Log::warning('ZipCodeService: unexpected provider response', ['zip_code' => $zipCode, 'status' => $response->status()]);
            throw new RuntimeException('Serviço de CEP indisponível.');
        }

        $address = [
            'zip_code' => $zipCode,
            'street' => (string) ($data['logradouro'] ?? ''),
            'neighborhood' => (string) ($data['bairro'] ?? ''),
            'city' => (string) $data['localidade'],
            'state' => (string) $data['uf'],
            'country' => 'Brasil',
        ];

        Cache::put($this->cacheKey($zipCode), $address, now()->addDays(self::CACHE_TTL_DAYS));

        return $address;
    }

    private function cacheKey(string $zipCode): string
    {
        return "zip_code:{$zipCode}";
    }
}
