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

        // 3. Configured app default locale (deterministic; must come before Accept-Language
        //    so that test requests and API calls without explicit session/cookie always
        //    resolve to the configured default rather than the environment's header).
        $configured = config('app.locale', $available[0]);
        if (in_array($configured, $available, true)) {
            return $configured;
        }

        // 4. Accept-Language header (only reached when config locale is not in $available).
        // Symfony normalizes locale to underscore form (e.g. zh_TW); map back to the
        // original key so App::setLocale() loads the correct translation files.
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
