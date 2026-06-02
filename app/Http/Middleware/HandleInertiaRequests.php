<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware {
    /**
     * Inertia 使用的根模板
     */
    protected $rootView = 'inertia';

    /**
     * 每個 Inertia 回應共用的 props
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array {
        return array_merge(parent::share($request), [
            'app' => [
                'version' => get_app_version(),
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                ] : null,
            ],
            'locale'      => app()->getLocale(),
            'locale_url'  => route('locale.switch', [], false),
            // ⚠️ 頁面特定翻譯群組（views、codes、operations、admin）
            //   請由控制器以 'page_translations' key 傳入，不可複用此 'translations' key，
            //   否則 inertia-laravel 的淺合併會覆蓋此處的 shared 翻譯。
            'translations' => [
                'common' => is_array($t = trans('common')) ? $t : [],
                'nav'    => is_array($t = trans('nav'))    ? $t : [],
                'person' => is_array($t = trans('person')) ? $t : [],
                'query'  => is_array($t = trans('query'))  ? $t : [],
            ],
        ]);
    }
}
