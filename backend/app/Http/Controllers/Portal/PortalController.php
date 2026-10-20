<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PortalMiddleware;
use App\Models\Customer;
use App\Service\PortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal do cliente. brand/code/login are public (company by portal slug, throttled); the rest
 * runs behind PortalMiddleware and only reads the logged customer's own data.
 */
class PortalController extends Controller
{
    public function __construct(
        protected PortalService $portalService,
    ) {
    }

    public function brand(string $slug): JsonResponse
    {
        return response()->json($this->portalService->brand($this->portalService->company($slug)));
    }

    public function sendCode(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|string|email|max:255']);
        $sentTo = $this->portalService->sendCode($this->portalService->company($slug), $validated['email']);

        return response()->json(['message' => "Enviamos um código para {$sentTo}.", 'sent_to' => $sentTo]);
    }

    public function login(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'code' => 'required|string|max:12',
        ]);

        return response()->json($this->portalService->login($this->portalService->company($slug), $validated['email'], $validated['code']));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->portalService->me($this->customer($request)));
    }

    public function projects(Request $request): JsonResponse
    {
        return response()->json($this->portalService->projects($this->customer($request)));
    }

    public function project(Request $request, int $id): JsonResponse
    {
        return response()->json($this->portalService->project($this->customer($request), $id));
    }

    public function announcements(Request $request): JsonResponse
    {
        return response()->json($this->portalService->announcements($this->customer($request)));
    }

    public function bills(Request $request): JsonResponse
    {
        return response()->json($this->portalService->bills($this->customer($request)));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->portalService->logout($this->customer($request));

        return response()->json(['message' => 'Até logo!']);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get(PortalMiddleware::CUSTOMER);
    }
}
