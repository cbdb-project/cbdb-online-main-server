<?php

namespace App\Http\Controllers;

use App\Services\PersonBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PersonBrowserController extends Controller {
    private PersonBrowserService $service;

    public function __construct(PersonBrowserService $service) {
        $this->middleware('auth');
        $this->service = $service;
    }

    /**
     * 人物瀏覽工作台主頁面（Inertia）。
     */
    public function index(Request $request): InertiaResponse {
        return Inertia::render('PersonBrowser/Index', [
            'tabKeys' => PersonBrowserService::validTabKeys(),
            'searchEndpoint' => route('app.person-browser.search', [], false),
            'summaryEndpoint' => route('app.person-browser.summary', ['personId' => '__PERSON_ID__'], false),
            'tabEndpoint' => route('app.person-browser.tab', ['personId' => '__PERSON_ID__', 'tabKey' => '__TAB_KEY__'], false),
            'initialPersonId' => $request->input('person_id') ? (int) $request->input('person_id') : null,
            'initialKeyword' => $request->input('keyword', ''),
            'initialTab' => $request->input('tab', 'basic_info'),
        ]);
    }

    /**
     * 搜尋人物（JSON）。
     */
    public function search(Request $request): JsonResponse {
        $result = $this->service->search($request);

        return response()->json($result);
    }

    /**
     * 取得人物摘要（JSON）。
     */
    public function summary(int $personId): JsonResponse {
        $data = $this->service->summary($personId);

        if ($data === null) {
            return response()->json(['error' => 'Person not found'], 404);
        }

        return response()->json($data);
    }

    /**
     * 取得 tab 資料（JSON）。
     */
    public function tab(Request $request, int $personId, string $tabKey): JsonResponse {
        if (!in_array($tabKey, PersonBrowserService::validTabKeys(), true)) {
            return response()->json(['error' => 'Invalid tab key'], 404);
        }

        $data = $this->service->tabData($personId, $tabKey);

        if ($data === null) {
            return response()->json(['error' => 'Tab data not available'], 404);
        }

        return response()->json($data);
    }
}
