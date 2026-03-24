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
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                ] : null,
            ],
        ]);
    }
}
