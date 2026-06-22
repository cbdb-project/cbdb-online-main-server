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
        $user = $request->user();
        $hasQueryParameters = !empty($request->query());

        return Inertia::render('PersonBrowser/Index', [
            'tabKeys' => PersonBrowserService::validTabKeys(),
            'searchEndpoint' => route('app.person-browser.search', [], false),
            'summaryEndpoint' => route('app.person-browser.summary', ['personId' => '__PERSON_ID__'], false),
            'tabEndpoint' => route('app.person-browser.tab', ['personId' => '__PERSON_ID__', 'tabKey' => '__TAB_KEY__'], false),
            'mutateEndpoint' => route('api.v2.mutate.web', [], false),
            'createEndpoint' => route('api.v2.create.web', [], false),
            'deleteEndpoint' => route('api.v2.delete.web', [], false),
            'pinyinEndpoint' => '/api/select/search/pinyin',
            'canEditBasicInfo' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            // 可提案但不可直接寫入的眾包用戶亦可送提案（與後端 authorizeProposal 一致）。
            'canProposeEdits' => $user ? $user->canPropose() : false,
            // PersonBrowser 別名分頁編輯器遷移開關（預設 old）。
            'altnameEditorIsNew' => migration_flag_is_new('basicinformation.altname'),
            // PersonBrowser 地址分頁編輯器遷移開關（預設 old）。
            'addressesEditorIsNew' => migration_flag_is_new('basicinformation.addresses'),
            // PersonBrowser 著述分頁編輯器遷移開關（預設 old）。
            'textsEditorIsNew' => migration_flag_is_new('basicinformation.texts'),
            // PersonBrowser 出處分頁編輯器遷移開關（預設 old）。
            'sourcesEditorIsNew' => migration_flag_is_new('basicinformation.sources'),
            // PersonBrowser 任官/官名分頁編輯器遷移開關（預設 old）。
            'officesEditorIsNew' => migration_flag_is_new('basicinformation.offices'),
            // PersonBrowser 社會關係分頁編輯器遷移開關（預設 old）。
            'assocEditorIsNew' => migration_flag_is_new('basicinformation.assoc'),
            // PersonBrowser 親屬分頁編輯器遷移開關（預設 old）。
            'kinshipEditorIsNew' => migration_flag_is_new('basicinformation.kinship'),
            // PersonBrowser 事件分頁編輯器遷移開關（預設 old）。
            'eventsEditorIsNew' => migration_flag_is_new('basicinformation.events'),
            // PersonBrowser 入仕分頁編輯器遷移開關（預設 old）。
            'entriesEditorIsNew' => migration_flag_is_new('basicinformation.entries'),
            // PersonBrowser 社會區分分頁編輯器遷移開關（預設 old）。
            'statusesEditorIsNew' => migration_flag_is_new('basicinformation.statuses'),
            // PersonBrowser 財產分頁編輯器遷移開關（預設 old）。
            'possessionEditorIsNew' => migration_flag_is_new('basicinformation.possession'),
            // PersonBrowser 社交機構分頁編輯器遷移開關（預設 old）。
            'socialInstEditorIsNew' => migration_flag_is_new('basicinformation.socialinst'),
            'initialPersonId' => $request->has('person_id')
                ? (int) $request->input('person_id')
                : ($hasQueryParameters ? null : 1),
            'initialKeyword' => $request->input('keyword', ''),
            'initialDynasty' => $request->input('c_dy', ''),
            'initialTab' => $request->input('tab', 'basic_info'),
            'initialPage' => max(1, (int) $request->input('page', 1)),
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
