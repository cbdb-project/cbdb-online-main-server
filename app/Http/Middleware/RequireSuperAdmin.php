<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin {
    public function handle(Request $request, Closure $next): Response {
        $user = $request->user();
        abort_unless(
            $user?->isActive() && $user?->isSuperAdmin(),
            403,
            '此頁面僅限管理員存取。'
        );

        return $next($request);
    }
}
