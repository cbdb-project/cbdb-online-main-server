<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $available = config('app.available_locales', ['zh-TW', 'en']);

        $locale = $this->resolveLocale($request, $available);

        App::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request, array $available): string
    {
        // 1. Session 優先
        $fromSession = $request->session()->get('locale');
        if ($fromSession && in_array($fromSession, $available, true)) {
            return $fromSession;
        }

        // 2. Cookie 次之
        $fromCookie = $request->cookie('locale');
        if ($fromCookie && in_array($fromCookie, $available, true)) {
            return $fromCookie;
        }

        // 3. Accept-Language header。
        // Symfony getPreferredLanguage() 在無匹配時回傳 $available[0]（非 null），
        // 且其內部會將 zh-CN/zh-Hans 的 zh prefix 匹配至 zh-TW，符合設計。
        return $request->getPreferredLanguage($available) ?? $available[0];
    }
}
