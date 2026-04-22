<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminApiKey
{
    /**
     * Valida que el request venga del admin-api central.
     * Compara header X-Admin-Api-Key contra config('services.admin_api.api_key').
     */
    public function handle(Request $request, Closure $next)
    {
        $received = $request->header('X-Admin-Api-Key');
        $expected = config('services.admin_api.api_key');

        if (empty($expected) || empty($received) || !hash_equals((string) $expected, (string) $received)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
