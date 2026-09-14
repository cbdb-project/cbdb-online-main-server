<?php

namespace App\Http\Controllers;

use App\Services\ViewTableService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ViewTableController extends Controller {
    public function __construct(
        private readonly ViewTableService $viewTableService
    ) {
    }

    // index() 是 legacy Blade 版，已隨 Blade 下架環節 4a-3 連同視圖一併刪除。
    // 共用的取資料 helper 全部保留給 appIndex() 使用。

    // show() 是 legacy Blade 版，已隨 Blade 下架環節 4a-3 連同視圖一併刪除。
    // 共用的取資料 helper 全部保留給 appShow() 使用。

    public function appIndex(): InertiaResponse {
        $views = $this->viewTableService->listDefinitions()
            ->map(function ($view) {
                return [
                    ...$view,
                    'app_url' => route('app.view.show', ['key' => $view['key']], false),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('ViewTables/List', [
            'views' => $views,
            'listUrl' => route('app.view.index', [], false),
            'page_translations' => [
                'views' => is_array($t = trans('views')) ? $t : [],
            ],
        ]);
    }

    public function appShow(Request $request, string $key): InertiaResponse {
        $data = $this->viewTableService->buildViewData($key, $request);

        if ($data === null) {
            abort(404);
        }

        $data['pageUrl'] = route('app.view.show', ['key' => $data['key']], false);
        $data['listUrl'] = route('app.view.index', [], false);
        $data['availableViews'] = $this->viewTableService->listDefinitions()
            ->map(function ($view) {
                return [
                    'key' => $view['key'],
                    'title' => $view['title'],
                    'primary_alias' => $view['primary_alias'],
                    'app_url' => route('app.view.show', ['key' => $view['key']], false),
                ];
            })
            ->values()
            ->all();

        $data['page_translations'] = [
            'views' => is_array($t = trans('views')) ? $t : [],
        ];

        return Inertia::render('ViewTables/Show', $data);
    }
}
