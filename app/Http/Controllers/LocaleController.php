<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller {
    public function switch(Request $request): RedirectResponse {
        $available = config('app.available_locales', ['zh-TW', 'en']);

        $request->validate([
            'locale' => ['required', 'string', Rule::in($available)],
        ]);

        $locale = $request->input('locale');

        $request->session()->put('locale', $locale);

        Cookie::queue('locale', $locale, 525600); // 365 × 24 × 60 分鐘

        return redirect()->back(fallback: url('/'));
    }
}
