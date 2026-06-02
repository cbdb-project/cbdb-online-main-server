<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware {
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response {
        $available = config('app.available_locales', ['zh-TW', 'en']);

        $locale = $this->resolveLocale($request, $available);

        App::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request, array $available): string {
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
        // Symfony 內部將 locale 正規化為底線形式（如 zh_TW），但 Laravel 翻譯目錄
        // 使用連字號（zh-TW）。此處將結果對映回 $available 中的原始鍵，確保
        // App::setLocale() 能正確載入翻譯檔。
        $fromHeader = $request->getPreferredLanguage($available);
        if ($fromHeader !== null) {
            $normalized = str_replace('_', '-', $fromHeader);
            foreach ($available as $locale) {
                if (str_replace('_', '-', $locale) === $normalized) {
                    return $locale;
                }
            }
        }

        return $available[0];
    }
}
