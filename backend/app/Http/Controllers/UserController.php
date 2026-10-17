<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Service\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * E-mail is the login, so it is unique across all companies. The message doesn't say
     * "already taken": it must not reveal that the address exists in another company.
     */
    private const EMAIL_MESSAGES = ['email.unique' => 'Não foi possível usar este e-mail. Informe outro.'];

    public function __construct(
        protected UserService $userService,
    ) {
    }

    /**
     * Display a listing of users.
     */
    #[Permission('users.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->only(['search']);
        $perPage = (int) $request->input('per_page', 15);

        $users = $this->userService->list($filters, $perPage);

        return response()->json($users);
    }

    /**
     * Display the specified user.
     */
    #[Permission('users.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->userService->index($id));
    }

    /**
     * Store a newly created user.
     */
    #[Permission('users.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'active' => 'sometimes|boolean',
        ], self::EMAIL_MESSAGES);

        $user = $this->userService->create($validated);

        return response()->json($user, 201);
    }

    /**
     * Update the specified user.
     */
    #[Permission('users.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:8',
            'active' => 'sometimes|boolean',
        ], self::EMAIL_MESSAGES);

        $user = $this->userService->update($id, $validated);

        return response()->json($user);
    }

    /**
     * Remove the specified user.
     */
    #[Permission('users.delete')]
    public function delete(Request $request, int $id): JsonResponse
    {
        $this->userService->delete($id);

        return response()->json(['message' => 'Usuário excluído com sucesso.']);
    }

    /**
     * Replace the user's permissions with the given keys.
     */
    #[Permission('users.permissions')]
    public function permissions(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'string|distinct|exists:permissions,key',
        ]);

        $keys = $this->userService->syncPermissions($request->user(), $id, $validated['permissions']);

        return response()->json(['permissions' => $keys]);
    }
}
