<?php

namespace App\Http\Controllers;

use App\Service\ZipCodeService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ZipCodeController extends Controller
{
    public function __construct(
        protected ZipCodeService $zipCodeService,
    ) {
    }

    /**
     * Address for a CEP. 404 = CEP does not exist; 503 = provider unavailable — in both
     * cases the form keeps working and the address is typed manually.
     */
    public function show(string $zipCode): JsonResponse
    {
        if (!preg_match('/^\d{5}-?\d{3}$/', $zipCode)) {
            return response()->json(['message' => 'CEP inválido. Informe 8 dígitos.'], 422);
        }

        try {
            $address = $this->zipCodeService->lookup($zipCode);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.',
            ], 503);
        }

        return $address
            ? response()->json($address)
            : response()->json(['message' => 'CEP não encontrado. Preencha o endereço manualmente.'], 404);
    }
}
