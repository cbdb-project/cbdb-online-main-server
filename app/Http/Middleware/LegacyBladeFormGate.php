<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Legacy Blade 表單下架閘門：把 migration flag 語義做實。
 *
 * flag=new（React 版已上線）時：
 *   - GET（index／create／edit 表單頁）→ 302 導向 /app 對應頁（比照 /query-playground 硬導向先例）；
 *   - 寫入（POST／PUT／PATCH／DELETE）→ 410 Gone。legacy 提案入口（proposalStore）沒有欄位
 *     白名單、會把稽核欄等任意表單欄原樣存進提案 payload（2026-08-05 別名提案核准炸 422 的根因），
 *     堵住寫入端即封死髒資料源頭。
 * flag=old 時原樣放行——保留 AGENTS.md 承諾的「翻回 old 即完整回退、不需改碼」。
 *
 * 掛法（routes/web.php）：legacy.form:{section}，section ∈ 子資源段名（altnames…）｜person｜proposal。
 */
class LegacyBladeFormGate {
    /** 子資源段名 → migration flag key 與 /app 人物頁 tab key。 */
    private const SUBRESOURCES = [
        'altnames' => ['flag' => 'basicinformation.altname', 'tab' => 'alt_names'],
        'addresses' => ['flag' => 'basicinformation.addresses', 'tab' => 'addresses'],
        'texts' => ['flag' => 'basicinformation.texts', 'tab' => 'texts'],
        'sources' => ['flag' => 'basicinformation.sources', 'tab' => 'sources'],
        'offices' => ['flag' => 'basicinformation.offices', 'tab' => 'postings'],
        'assoc' => ['flag' => 'basicinformation.assoc', 'tab' => 'associations'],
        'kinship' => ['flag' => 'basicinformation.kinship', 'tab' => 'kinship'],
        'events' => ['flag' => 'basicinformation.events', 'tab' => 'events'],
        'entries' => ['flag' => 'basicinformation.entries', 'tab' => 'entries'],
        'statuses' => ['flag' => 'basicinformation.statuses', 'tab' => 'statuses'],
        'possession' => ['flag' => 'basicinformation.possession', 'tab' => 'possessions'],
        'socialinst' => ['flag' => 'basicinformation.socialinst', 'tab' => 'social_institutions'],
    ];

    private const GONE_MESSAGE = '舊版表單端點已停用，請改用 /app 介面或 /api/v2/mutate。';

    public function handle(Request $request, Closure $next, string $section) {
        if ($section === 'proposal') {
            return $this->handleProposal($request, $next);
        }
        if ($section === 'person') {
            return $this->handlePerson($request, $next);
        }

        return $this->handleSubresource($request, $next, $section);
    }

    /** POST basicinformation/{personid}/{resource}(/{id})/proposal：對應資源 flag=new 時一律 410。 */
    private function handleProposal(Request $request, Closure $next) {
        $resource = (string) $request->route('resource');
        $flag = $resource === 'biogmain'
            ? 'basicinformation.editor'
            : (self::SUBRESOURCES[$resource]['flag'] ?? null);

        // 未知資源交由控制器 404，不在此擋。
        if ($flag !== null && migration_flag_is_new($flag)) {
            abort(410, self::GONE_MESSAGE);
        }

        return $next($request);
    }

    /** 人物層級（Route::resource basicinformation）：index/show/editor 各依其 flag。destroy 不在表單範圍、放行。 */
    private function handlePerson(Request $request, Closure $next) {
        $routeName = (string) $request->route()->getName();
        $action = substr($routeName, strrpos($routeName, '.') + 1);

        $flag = match ($action) {
            'index' => 'basicinformation.index',
            'show' => 'basicinformation.show',
            'create', 'store', 'edit', 'update' => 'basicinformation.editor',
            default => null, // destroy 等：放行
        };
        if ($flag === null || !migration_flag_is_new($flag)) {
            return $next($request);
        }

        if (!$request->isMethod('GET')) {
            abort(410, self::GONE_MESSAGE);
        }

        $personId = $request->route('basicinformation');

        return match ($action) {
            'index' => $this->redirectWithQuery($request, route('app.basicinformation.index', [], false)),
            'create' => redirect(route('app.basicinformation.create', [], false)),
            'show' => redirect(route('app.basicinformation.show', ['id' => $personId], false)),
            default => redirect(route('app.basicinformation.edit', ['id' => $personId], false)), // edit
        };
    }

    /** 子資源（Route::resource basicinformation.{seg} 與 edit/update/destroy.query 三路由）。 */
    private function handleSubresource(Request $request, Closure $next, string $section) {
        $config = self::SUBRESOURCES[$section] ?? null;
        if ($config === null || !migration_flag_is_new($config['flag'])) {
            return $next($request);
        }

        if (!$request->isMethod('GET')) {
            abort(410, self::GONE_MESSAGE);
        }

        $personId = $request->route('basicinformation') ?? $request->route('id');
        $routeName = (string) $request->route()->getName();

        // create／edit.query → 對應 React 編輯器（edit.query 的 PK 查詢參數原樣轉發，直接進編輯模式）；
        // index／path-param 版 edit（複合鍵編碼格式，無法可靠解析）→ /app 人物頁對應分頁。
        if (str_ends_with($routeName, '.create') || str_ends_with($routeName, '.edit.query')) {
            return $this->redirectWithQuery(
                $request,
                route("app.basicinformation.{$section}.editv2", ['id' => $personId], false)
            );
        }

        return redirect(route('app.basicinformation.show', ['id' => $personId, 'tab' => $config['tab']], false));
    }

    /** 302 至目標並保留原查詢字串（edit.query 的 PK 參數、index 的搜尋參數）。 */
    private function redirectWithQuery(Request $request, string $target) {
        $queryString = $request->getQueryString();
        if ($queryString !== null && $queryString !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . $queryString;
        }

        return redirect($target);
    }
}
