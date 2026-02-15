<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpAbility {
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null): Response {
        if (!config('mcp.cbdb.require_token_abilities', true)) {
            return $next($request);
        }

        $ability = $ability ?: (string) config('mcp.cbdb.required_ability', 'mcp:read');

        $token = $request->user()?->currentAccessToken();
        if (!$token || !$token->can($ability)) {
            abort(403, "Missing required token ability: {$ability}");
        }

        return $next($request);
    }
}
