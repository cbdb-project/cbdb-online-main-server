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
            'locale' => app()->getLocale(),
            'translations' => [
                'common' => is_array($t = trans('common')) ? $t : [],
                'nav'    => is_array($t = trans('nav'))    ? $t : [],
                'person' => is_array($t = trans('person')) ? $t : [],
                'query'  => is_array($t = trans('query'))  ? $t : [],
            ],
        ]);
    }
}
