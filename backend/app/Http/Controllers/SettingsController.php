<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\TransactionStatus;
use App\Service\BotActivationService;
use App\Service\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configurações (tabs). Company tabs act on the current company and each needs its own permission;
 * Preferências is the user's own and needs none.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settingsService,
        protected BotActivationService $activationService,
    ) {
    }

    #[Permission('settings.company')]
    public function company(): JsonResponse
    {
        return response()->json($this->settingsService->company());
    }

    #[Permission('settings.company')]
    public function updateCompany(Request $request): JsonResponse
    {
        $validated = $request->validate($this->settingsService->companyRules());

        return response()->json($this->settingsService->updateCompany($validated));
    }

    #[Permission('settings.appearance')]
    public function updateAppearance(Request $request): JsonResponse
    {
        $validated = $request->validate($this->settingsService->sectionRules('appearance'));

        return response()->json($this->settingsService->updateSection('appearance', $validated));
    }

    /** Current values + the status options (the tab may be open without finance.transaction.view). */
    #[Permission('settings.finance')]
    public function finance(): JsonResponse
    {
        return response()->json([
            'values' => SettingsService::for($this->settingsService->company())['finance'],
            'statuses' => TransactionStatus::options(),
        ]);
    }

    #[Permission('settings.finance')]
    public function updateFinance(Request $request): JsonResponse
    {
        $validated = $request->validate($this->settingsService->sectionRules('finance'));

        return response()->json($this->settingsService->updateSection('finance', $validated));
    }

    #[Permission('settings.telegram')]
    public function telegram(): JsonResponse
    {
        return response()->json($this->activationService->overview($this->settingsService->company()));
    }

    #[Permission('settings.telegram')]
    public function regenerateTelegramCode(): JsonResponse
    {
        $company = $this->settingsService->company();
        $this->activationService->regenerateCode($company);

        return response()->json($this->activationService->overview($company));
    }

    #[Permission('settings.telegram')]
    public function revokeTelegramCode(): JsonResponse
    {
        $company = $this->settingsService->company();
        $this->activationService->revokeCode($company);

        return response()->json($this->activationService->overview($company));
    }

    #[Permission('settings.telegram')]
    public function updateTelegramAccount(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['revoked' => 'required|boolean']);

        return response()->json($this->activationService->setRevoked($id, $validated['revoked']));
    }

    #[Permission('settings.telegram')]
    public function removeTelegramAccount(int $id): JsonResponse
    {
        $this->activationService->remove($id);

        return response()->json(['message' => 'Vínculo removido.']);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate($this->settingsService->preferenceRules());

        return response()->json($this->settingsService->updatePreferences($request->user(), $validated));
    }
}
