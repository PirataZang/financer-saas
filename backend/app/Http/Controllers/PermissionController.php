<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Service\PermissionService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService,
    ) {
    }

    /**
     * Every permission grouped by module, for the user permissions tab.
     */
    #[Permission('users.permissions')]
    public function index(): JsonResponse
    {
        return response()->json($this->permissionService->catalog());
    }
}
