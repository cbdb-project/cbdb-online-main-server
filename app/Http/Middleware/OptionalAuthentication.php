<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

class OptionalAuthentication {
    /**
     * Handle an incoming request.
     *
     * 嘗試認證用戶，但不要求必須登錄。
     * 支持 Session Cookie（Web 前端）和 Bearer Token（外部應用）兩種方式。
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response {
        // 檢查是否為 stateful 請求（來自前端 SPA 的 Session 認證）
        if (EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            // 嘗試使用 web guard 認證（Session Cookie）
            if (Auth::guard('web')->check()) {
                Auth::shouldUse('web');
            }
        } else {
            // 嘗試使用 Sanctum 認證（Bearer Token）
            // 如果有 token，嘗試認證；沒有或失敗也不報錯
            try {
                if ($request->bearerToken()) {
                    Auth::shouldUse('sanctum');
                    $request->user();
                }
            } catch (\Exception $e) {
                // 認證失敗不報錯，繼續作為 guest 處理
            }
        }

        return $next($request);
    }
}
