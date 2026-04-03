<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! in_array($user->rol, $roles, true)) {
            return response()->json([
                'message' => 'No tiene permisos para realizar esta acción.',
                'code' => 'permission_denied',
            ], 403);
        }

        return $next($request);
    }
}
