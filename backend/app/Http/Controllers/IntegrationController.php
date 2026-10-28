<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Service\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Configurações › Integrações: one card per provider (IntegrationProvider). Each company connects
 * its own account by sending that account's Access Token (stored encrypted); after that the card toggles, edits settings, syncs
 * on demand and removes everything the integration brought. All read-only towards the provider.
 */
class IntegrationController extends Controller
{
    public function __construct(protected MercadoPagoService $mercadoPago)
    {
    }

    #[Permission('settings.integrations')]
    public function list(): JsonResponse
    {
        return response()->json(array_map(fn (IntegrationProvider $provider) => $this->service($provider)->card(), IntegrationProvider::cases()));
    }

    #[Permission('settings.integrations')]
    public function connect(Request $request, IntegrationProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string', 'max:500'],
        ]);
        $this->service($provider)->connect($validated['access_token']);

        return response()->json($this->service($provider)->card());
    }

    #[Permission('settings.integrations')]
    public function update(Request $request, IntegrationProvider $provider): JsonResponse
    {
        $service = $this->service($provider);
        $integration = $this->integration($provider);
        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array:'.implode(',', array_keys($service->settingsRules()))],
            ...collect($service->settingsRules())->mapWithKeys(fn ($rules, $key) => ["settings.{$key}" => $rules])->all(),
        ]);

        $integration->update([
            'active' => $validated['active'] ?? $integration->active,
            'settings' => [...$service->settings($integration), ...($validated['settings'] ?? [])],
        ]);

        return response()->json($service->card());
    }

    #[Permission('settings.integrations')]
    public function sync(IntegrationProvider $provider): JsonResponse
    {
        $integration = $this->integration($provider);
        if (!$integration->active) {
            throw ValidationException::withMessages(['integration' => ['Ative a integração para sincronizar.']]);
        }

        try {
            $result = $this->service($provider)->sync($integration);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['integration' => [$e->getMessage()]]);
        }

        return response()->json([...$this->service($provider)->card(), 'synced' => $result['payments']]);
    }

    #[Permission('settings.integrations')]
    public function delete(IntegrationProvider $provider): JsonResponse
    {
        $this->service($provider)->purge($this->integration($provider));

        return response()->json(['message' => "Integração com o {$provider->label()} removida."]);
    }

    private function service(IntegrationProvider $provider): MercadoPagoService
    {
        return match ($provider) {
            IntegrationProvider::MercadoPago => $this->mercadoPago,
        };
    }

    private function integration(IntegrationProvider $provider): Integration
    {
        return Integration::for($provider) ?? abort(404, "A integração com o {$provider->label()} não está conectada.");
    }
}
