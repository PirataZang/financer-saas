<?php

namespace App\Http\Middleware;

use App\Attributes\Permission;
use Closure;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the #[Permission('module.action')] attribute declared on the controller
 * method (or class). Runs after AuthMiddleware; actions without the attribute only
 * require authentication.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = self::requiredPermission($request->route()?->getActionName());

        if ($key && !$request->user()?->hasPermission($key)) {
            return response()->json([
                'code' => 'NO_PERMISSION',
                'permission' => $key,
                'message' => 'Você não possui permissão para realizar esta ação.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Permission key declared for a "Controller@method" (or invokable controller) action, or null.
     */
    public static function requiredPermission(?string $action): ?string
    {
        if (!$action || $action === 'Closure') {
            return null;
        }

        [$controller, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];

        if (!method_exists($controller, $method)) {
            return null;
        }

        $attributes = (new ReflectionMethod($controller, $method))->getAttributes(Permission::class)
            ?: (new ReflectionClass($controller))->getAttributes(Permission::class);

        return $attributes ? $attributes[0]->newInstance()->key : null;
    }
}
