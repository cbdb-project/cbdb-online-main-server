<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Prometheus metrics endpoint
Route::get('metrics', 'MetricsController@index')->name('metrics');

Route::get('/', 'WelcomeController@index');

Auth::routes();

// email/verify/{token} 已於 P0-2 下架（連同 EmailController）。
// 它是一條不掛 auth、直接 Auth::login() 的無密碼登入端點：confirmation_token 永久有效、
// 會經 URL 路徑流入 access log／Referer／瀏覽器歷史，任何知道它的人都能取得該帳號 session；
// 被停用的帳號也能靠它繞過 is_active 複查重拿 session。而它連名義上的「啟用帳號」都沒做
// （$user->is_active = 2 早被註解），啟用信自 2021-08 起停發 → 沒有合法用途。
// 日後若要恢復啟用信，應另建一次性、有期限的 email_verifications 表，不得重用
// confirmation_token，且不得 Auth::login()。
// ── Blade 下架環節 4a：唯讀頁已實體刪除，舊 URI 只留 302 導向 ──────────────
//
// 這些路由原本掛 `legacy.page:app.*`（環節 3 封路、視圖仍在、kill switch 可叫回）。
// 環節 4a 刪掉視圖與對應的 Blade controller 方法之後，**不能再留那個 middleware**：
// 它有兩條 fail-open 路徑（導向目標不存在時放行、kill switch 關閉時放行），
// 視圖不存在會讓那兩條變成 500 而不是「看到舊頁」。所以改成純 redirect closure——
// 沒有可掉下去的 controller。
//
// 🔴 **這批頁面自此沒有任何 kill switch 級回退**：`LEGACY_PAGE_RETIREMENT=false`
// 對它們已無作用，要回到 Blade 只能 git revert 並重新部署。
//
// route name 一律保留：書籤／外部連結繼續可用（302 並保留 query string），
// 且 `route('operations.index')` 這類既有呼叫端不必全部改。

Route::get('operations', function (\Illuminate\Http\Request $request) {
    return redirect()->to('/app/operations'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->name('operations.index');
Route::get('app/operations', ['as' => 'app.operations.index', 'uses' => 'OperationsController@appIndex'])->middleware('inertia');
Route::post('locale', 'LocaleController@switch')->name('locale.switch')->middleware('throttle:20,1');

Route::get('home', 'HomeController@index')->name('home');
Route::get('dashboard', function (\Illuminate\Http\Request $request) {
    return redirect()->to('/app/dashboard'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->name('dashboard');
Route::get('app/dashboard', 'DashboardController@appIndex')
    ->middleware(['auth', 'inertia'])
    ->name('app.dashboard');
Route::get('cbdbapi/person.php', 'CbdbApiController@person')->name('cbdbapi.v1.person');
Route::get('cbdbapi/person', 'CbdbApiController@person');
Route::get('openapi.yaml', function () {
    return response()->file(base_path('docs/openapi/openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('openapi.yaml');
Route::middleware(['auth.optional'])->post('api/v2/mutate', 'Api\\MutationController@store')->name('api.v2.mutate.web');
Route::middleware(['auth.optional'])->post('api/v2/batch_mutate', 'Api\\MutationController@batchStore')->name('api.v2.batch-mutate.web');
Route::middleware(['auth.optional'])->post('api/v2/create', 'Api\\MutationController@create')->name('api.v2.create.web');
Route::middleware(['auth.optional'])->post('api/v2/delete', 'Api\\MutationController@delete')->name('api.v2.delete.web');
// 修改提案＝撤回舊提案＋同一條提交管線重發（見 MutationController::resubmit 註解）
Route::middleware(['auth.optional'])->post('api/v2/proposals/{operation}/resubmit', 'Api\\MutationController@resubmit')->name('api.v2.proposals.resubmit.web');
Route::middleware(['auth.optional'])->match(['get', 'post'], 'api/v2/get', 'Api\\MutationController@get')->name('api.v2.get.web');
// #79：社會關係／親屬「對面互逆鏡像」現況偵測（缺邊/多條），供編輯器行內提示用。
Route::middleware(['auth.optional'])->post('api/v2/relationship/opposite-edges', 'Api\\MutationController@oppositeEdges')->name('api.v2.relationship.opposite-edges.web');
Route::get('view', function (\Illuminate\Http\Request $request) {
    return redirect()->to('/app/view'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->name('view.index');
Route::get('view/{key}', function (\Illuminate\Http\Request $request, string $key) {
    return redirect()->to('/app/view/'.$key.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->name('view.show');

// CHGIS 地圖：底圖圖磚與下載狀態（與地址/官職列表頁同等公開）
Route::get('chgis-map/tiles/{z}/{x}/{y}', 'ChgisMapController@tile')
    ->where(['z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+'])
    ->name('chgis-map.tile');
Route::get('chgis-map/status', 'ChgisMapController@status')->name('chgis-map.status');
Route::get('basicinformation/{id}/map-points', 'ChgisMapController@personPoints')
    ->where('id', '[0-9]+')
    ->name('basicinformation.map-points');

// Legacy 人物頁：Blade 已於下架計畫環節 2 刪除。顯示頁保留路由名與 URI 並 302 導向 /app
// 對應頁（觀察期用 302，不用 301——301 會被瀏覽器／CDN 長期快取，revert 也救不回來）；
// 寫入端（store/update）回 410，語義沿用已移除的 LegacyBladeFormGate。
//
// ⚠️ basicinformation.index 這個**路由名與 URI 都不可移除**：多處 helper 仍以它為準。
//
// 📌 **刻意的不對稱：人物層給 302，12 組子資源舊 URI 給 404。**
// 子資源的 legacy URI 是「一個人物 × 一種資源 × 複合主鍵 query」的組合
// （如 /basicinformation/{id}/altnames/edit?c_personid=..&c_alt_name_chn=..），要導向 React
// edit-v2 就得逐段解析並轉換複合主鍵編碼——而那正是 legacy 那套自訂編碼最容易出錯的地方
// （minus/slash/NULL 哨兵各有轉義規則）。導錯會把使用者送到**別人的**記錄，比 404 危險。
// 人物層只有 {id} 一個整數參數，沒有這個風險，所以值得給 302。
// 若日後確認有實際的書籤損失，再補一條 catch-all 導向（見計畫環節 7）。
//
// ⚠️ 由 web middleware group 的順序決定：VerifyCsrfToken 跑在這些路由閉包**之前**，
// 所以真實世界未帶 token 的 legacy POST/PUT 會先拿到 419 而不是 410（測試環境 CSRF 跳過
// 才看得到 410）。這與原 LegacyBladeFormGate（同樣是 route middleware）行為一致，非退化。
Route::get('basicinformation', function (\Illuminate\Http\Request $request) {
    return redirect()->to('/app/basicinformation'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->name('basicinformation.index');
Route::get('basicinformation/create', fn () => redirect('/app/basicinformation/create', 302))
    ->name('basicinformation.create');
Route::get('basicinformation/{basicinformation}/edit', fn ($id) => redirect('/app/basicinformation/'.$id.'/edit', 302))
    ->where('basicinformation', '[0-9]+')->name('basicinformation.edit');
Route::get('basicinformation/{basicinformation}', fn ($id) => redirect('/app/basicinformation/'.$id, 302))
    ->where('basicinformation', '[0-9]+')->name('basicinformation.show');
Route::post('basicinformation', fn () => abort(410, '舊版表單端點已停用，請改用 /app 介面或 /api/v2/mutate。'))
    ->name('basicinformation.store');
Route::match(['put', 'patch'], 'basicinformation/{basicinformation}', fn () => abort(410, '舊版表單端點已停用，請改用 /app 介面或 /api/v2/mutate。'))
    ->where('basicinformation', '[0-9]+')->name('basicinformation.update');
// destroy 未被 LegacyBladeFormGate 擋過、也無 React 對應，維持原樣（見計畫 D-5b／環節 7）。
Route::delete('basicinformation/{basicinformation}', 'BasicInformationController@destroy')
    ->where('basicinformation', '[0-9]+')->name('basicinformation.destroy');
// Inertia + React 版（人物列表，public，與舊 index 同）
Route::get('app/basicinformation', 'BasicInformationController@appIndex')
    ->middleware('inertia')
    ->name('app.basicinformation.index');
// Inertia + React 版（人物主檔 create/edit/show）。
// create 須排在 {id}/edit、{id} 之前，避免被泛用路由攔截。
Route::get('app/basicinformation/create', 'BasicInformationController@appCreate')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.create');
Route::get('app/basicinformation/{id}/edit', 'BasicInformationController@appEdit')
    ->where('id', '[0-9]+')
    ->middleware('inertia')
    ->name('app.basicinformation.edit');
// Task 27 重做：對齊 legacy /edit 的 React 基本資料編輯器（BasicInfoEditor）。
// 這些 edit-v2 路由即為現行上線的 React 編輯器：legacy 表單被 LegacyBladeFormGate 攔截後 302 導向對應 *.editv2。
Route::get('app/basicinformation/{id}/edit-v2', 'BasicInformationController@appEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.editv2');
// 地址編輯器 V2（對齊 legacy addresses/_form）。
Route::get('app/basicinformation/{id}/addresses/edit-v2', 'BasicInformationController@appAddressEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.addresses.editv2');
// 著述編輯器 V2（對齊 legacy texts/_form）。
Route::get('app/basicinformation/{id}/texts/edit-v2', 'BasicInformationController@appTextEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.texts.editv2');
Route::get('app/basicinformation/{id}/altnames/edit-v2', 'BasicInformationController@appAltnameEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.altnames.editv2');
// 社會機構編輯器 V2（對齊 legacy socialinst/_form）。
Route::get('app/basicinformation/{id}/socialinst/edit-v2', 'BasicInformationController@appSocialinstEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.socialinst.editv2');
// 占有／財產編輯器 V2（對齊 legacy possession/_form）。
Route::get('app/basicinformation/{id}/possession/edit-v2', 'BasicInformationController@appPossessionEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.possession.editv2');
// 事件編輯器 V2（對齊 legacy events/_form）。
Route::get('app/basicinformation/{id}/events/edit-v2', 'BasicInformationController@appEventEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.events.editv2');
Route::get('app/basicinformation/{id}/entries/edit-v2', 'BasicInformationController@appEntriesEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.entries.editv2');
// 社會區分編輯器 V2（對齊 legacy statuses/_form，含 AI 智能識別社會區分類別代碼）。
Route::get('app/basicinformation/{id}/statuses/edit-v2', 'BasicInformationController@appStatusEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.statuses.editv2');
// 著述出處編輯器 V2（對齊 legacy sources/_form）。
Route::get('app/basicinformation/{id}/sources/edit-v2', 'BasicInformationController@appSourceEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.sources.editv2');
// 官名／任官編輯器 V2（對齊 legacy offices/_form，含多地址＋雙 era＋社會機構）。
Route::get('app/basicinformation/{id}/offices/edit-v2', 'BasicInformationController@appOfficeEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.offices.editv2');
// 社會關係編輯器 V2（對齊 legacy assoc/_form，9 段 PK＋雙 era＋多人物搜尋＋AI 代碼識別；pair 後端權威補齊）。
Route::get('app/basicinformation/{id}/assoc/edit-v2', 'BasicInformationController@appAssocEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.assoc.editv2');
// 親屬關係編輯器 V2（對齊 legacy kinship/_form，3 段 PK＋親屬碼/人物/出處搜尋；互逆配對碼後端權威補齊＋雙向 mirror）。
Route::get('app/basicinformation/{id}/kinship/edit-v2', 'BasicInformationController@appKinshipEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.kinship.editv2');
// PersonEditor 資料端點（JSON，編輯者/訪客可用，非 superadmin-only）。額外路徑段，
// 不會被下方 {id} 泛用路由攔截；不掛 inertia（純 JSON）。
Route::get('app/basicinformation/{id}/summary', 'BasicInformationController@summary')
    ->where('id', '[0-9]+')
    ->name('app.basicinformation.summary');
Route::get('app/basicinformation/{id}/tabs/{tabKey}', 'BasicInformationController@tab')
    ->where('id', '[0-9]+')
    ->name('app.basicinformation.tab');
Route::get('app/basicinformation/{id}', 'BasicInformationController@appShow')
    ->where('id', '[0-9]+')
    ->middleware('inertia')
    ->name('app.basicinformation.show');
Route::get('basicinformation/{id}/saveas', 'BasicInformationController@saveas');
Route::get('basicinformation/{id}/Duplicate_Collateral_Info', 'BasicInformationController@Duplicate_Collateral_Info');

// ── Blade 下架環節 4b-4a：codes 全套 Blade 已實體刪除，舊 URI 只留 302／410 ──────
//
// 與環節 4a 同一個處置與同一個理由（見本檔上方那段）：`legacy.page` 有兩條 fail-open
// 路徑，視圖不存在會讓它們變成 500 而不是「看到舊頁」，所以改成純 closure。
//
// 🔴 **codes 自此沒有任何 kill switch 級回退**：`LEGACY_PAGE_RETIREMENT=false` 對這批
// 已無作用，要回到 Blade 只能 git revert 並重新部署。
//
// route name 一律保留：書籤／外部連結繼續可用，且 `code_table_edit_url()`／
// `Navigation::codeItem()`／`CodesController::codesIdTemplate()` 那幾個
// **flag-aware 的字串回退**（flag=old 時回傳 `/codes/...`）不會變成死連結——
// 它們產出的 URL 仍會 302 到 React 版。那些回退本身的收斂屬環節 4d。
// ── Blade 下架環節 4b-4a：codes 顯示頁的 302 導向 ────────────────────────
//
// 🔴 **一律走 `route($target, $request->route()->parameters(), false)`，不要手拼字串**
// （第一版手拼，被 review 抓出兩個 bug）：
//  1. **編碼**：手拼會讓 `Location` 吐出裸 UTF-8／裸空白，而代碼表**已支援文本主鍵**
//     （`ADDR_CODES` 之外還有 `ALTNAME_DATA.c_alt_name_chn` 這類），所以 `$id = '慎'`
//     不是假想。更糟的是 `a%2Fb` 會被解成真的路徑分隔。`route()` 會做 rawurlencode
//     並只放行 `/ ? & # %`——那正是舊 `RetireLegacyBladePage` 的行為。
//  2. **query string**：五條裡有一條漏拼（`proposals/{operation}/edit`），而舊 middleware
//     對所有導向型一律保留 QS。書籤／從 operations 頁帶參數過來都會受影響。
//
// 這個小工具把兩件事收在一起，順帶消掉硬編碼的 `/app/codes` 字面值。
//
// ⚠️ **不要在交給 route() 之前自己先 rawurlencode**（codex 提過、實測後決定不改）：
// `route()` 只放行 `/ ? & # %`，而 codes 的 `{id}` 是 `where('id', '.*')`，
// `operations.resource_id` 對複合主鍵存的就是 `c_personid=1&c_x=2` 這種**帶 `=` 與 `&`** 的
// 格式。先編碼會把那個形狀改寫成 `%3D`／`%26`——那是單方面改掉一個出現在 operations
// payload 裡的 URL。這裡的目標是與被移除的 middleware **一字不差的 parity**
// （`RetireLegacyBladePage:80-81` 就是這兩行）。取捨與理由釘在
// `LegacyBladePageRetirementTest::codes_redirects_preserve_the_query_string_and_encode_the_id()`。
if (!function_exists('cbdb_legacy_codes_redirect')) {
    function cbdb_legacy_codes_redirect(string $target, \Illuminate\Http\Request $request) {
        $url = route($target, $request->route()->parameters(), false);
        $qs = $request->getQueryString();

        return redirect()->to($url.($qs ? '?'.$qs : ''), 302);
    }
}

Route::get('codes', fn (\Illuminate\Http\Request $request) => cbdb_legacy_codes_redirect('app.codes.index', $request))
    ->name('codes.index');
// Inertia + React 版（代碼表總覽）
Route::get('app/codes', 'CodesController@appIndex')
    ->middleware('inertia')
    ->name('app.codes.index');
// 全量導出：route 泛用，但範圍由 config('codes.export_columns') 白名單收斂（本輪僅 OFFICE_CODES）。
// 直連 live 生產庫，故加 throttle 防爬蟲爆量。設計見 docs/OFFICE_CODES_EXPORT_SYNC.md。
Route::get('codes/{table_name}/export', 'CodesController@export')->name('codes.export')->middleware('throttle:6,1');
Route::get('codes/{table_name}', fn (\Illuminate\Http\Request $request) => cbdb_legacy_codes_redirect('app.codes.show', $request))
    ->name('codes.show');
// TEXT_INSTANCE_DATA 的「Load Data」用：依 c_textid 精確取回書名（JSON，不掛 inertia）。
// 額外路徑段，置於下方 {table_name} 泛用路由之前，避免被攔截。
// 直連 live 生產庫、且與 codes 讀取面一樣無登入門檻，故加 throttle（同 codes.export 的理由）。
Route::get('app/codes/text-title/{textId}', 'CodesController@textTitle')
    ->where('textId', '[0-9]+')
    ->middleware('throttle:60,1')
    ->name('app.codes.text-title');
// Inertia + React 版（單表資料檢視 + 新增流程）
Route::get('app/codes/{table_name}/create', 'CodesController@appCreate')
    ->middleware('inertia')
    ->name('app.codes.create');
Route::post('app/codes/{table_name}/proposal', 'CodesController@appProposeStore')
    ->middleware('inertia')
    ->name('app.codes.propose.store');
Route::post('app/codes/{table_name}', 'CodesController@appStore')
    ->middleware('inertia')
    ->name('app.codes.store');
// 提案調整（須排在 {id} 泛用路由之前，否則 id='.*' 會吞掉 proposals/{operation}）
Route::get('app/codes/{table_name}/proposals/{operation}/edit', 'CodesController@appProposalEdit')
    ->middleware('inertia')->name('app.codes.proposals.edit');
Route::patch('app/codes/{table_name}/proposals/{operation}', 'CodesController@proposalUpdateExisting')
    ->middleware('inertia')->name('app.codes.proposals.update');
Route::delete('app/codes/{table_name}/proposals/{operation}', 'CodesController@proposalCancel')
    ->middleware('inertia')->name('app.codes.proposals.cancel');
Route::get('app/codes/{table_name}/{id}/edit', 'CodesController@appEdit')
    ->middleware('inertia')->name('app.codes.edit')->where('id', '.*');
Route::match(['post', 'patch'], 'app/codes/{table_name}/{id}/proposal', 'CodesController@appProposalUpdate')
    ->middleware('inertia')->name('app.codes.propose.update')->where('id', '.*');
Route::match(['put', 'patch'], 'app/codes/{table_name}/{id}', 'CodesController@appUpdate')
    ->middleware('inertia')->name('app.codes.update')->where('id', '.*');
Route::delete('app/codes/{table_name}/{id}', 'CodesController@appDestroy')
    ->middleware('inertia')->name('app.codes.destroy')->where('id', '.*');
Route::get('app/codes/{table_name}', 'CodesController@appShow')
    ->middleware('inertia')
    ->name('app.codes.show');

// 官職實體聚合 CRUD（/app/office/*）——上層聚合入口，寫入走 mutation API（resource=office）。
// create 須排在 {id}/edit 之前；{id} 限數字避免吞掉 create。
Route::get('app/office', 'OfficeEntityController@appIndex')
    ->middleware('inertia')->name('app.office.index');
Route::get('app/office/create', 'OfficeEntityController@appCreate')
    ->middleware('inertia')->name('app.office.create');
Route::get('app/office/{id}/edit', 'OfficeEntityController@appEdit')
    ->middleware('inertia')->name('app.office.edit')->whereNumber('id');

// 社會機構實體聚合 CRUD（/app/social-institution/*）——上層聚合入口，
// 寫入走 mutation API（resource=social-institution）。同官職：create 先於 {id}/edit。
Route::get('app/social-institution', 'SocialInstitutionEntityController@appIndex')
    ->middleware('inertia')->name('app.social-institution.index');
Route::get('app/social-institution/create', 'SocialInstitutionEntityController@appCreate')
    ->middleware('inertia')->name('app.social-institution.create');
Route::get('app/social-institution/{id}/edit', 'SocialInstitutionEntityController@appEdit')
    ->middleware('inertia')->name('app.social-institution.edit')->whereNumber('id');

// 文獻實體聚合 CRUD（/app/text/*）——上層聚合入口（TEXT_CODES ＋ TEXT_INSTANCE_DATA 版本層級），
// 寫入走 mutation API（resource=text-entity）。同官職：create 先於 {id}/edit。
Route::get('app/text', 'TextEntityController@appIndex')
    ->middleware('inertia')->name('app.text.index');
Route::get('app/text/create', 'TextEntityController@appCreate')
    ->middleware('inertia')->name('app.text.create');
Route::get('app/text/{id}/edit', 'TextEntityController@appEdit')
    ->middleware('inertia')->name('app.text.edit')->whereNumber('id');
Route::get('codes/{table_name}/create', fn (\Illuminate\Http\Request $request) => cbdb_legacy_codes_redirect('app.codes.create', $request))
    ->name('codes.create');
Route::post('codes/{table_name}/proposal', fn () => abort(410, 'Legacy codes proposal endpoint has been removed; use /app/codes/{table_name}/proposal.'))
    ->name('codes.propose.store');
// ✅ **環節 4b-1 已收斂**：這三條原本不掛封路 middleware，因為 React /app/operations 的
// 「修改提案」與「撤回」連結**都**指向它們（`OperationsController::serializeOperationRow()`
// 的 `urls.edit_proposal` 與 `urls.cancel_proposal` 兩個三元式的 else 分支），
// 而那兩行 `route()` 都**沒有 Route::has() 保護**——刪了會讓整頁 500。
// 4b-1 把兩行都改指 `app.codes.proposals.*`（React 版早就存在、授權同等），
// 所以這三條現在封得起來：GET→302、PATCH／DELETE→410。
// 回歸測試：`OperationsIndexLinksTest::test_code_table_proposal_urls_point_at_the_react_endpoints_and_are_reachable()`。
// ⚠️ **`proposalUpdateExisting()` 與 `proposalCancel()` 不是薄殼**：`app.codes.proposals.*`
// 兩條路由指向的是**同一個方法**，所以那兩個 controller 方法**必須留著**，
// 這裡刪的只是 legacy 這兩條路由的接線（見環節 4b-2b 的記錄）。
Route::get('codes/{table_name}/proposals/{operation}/edit', fn (\Illuminate\Http\Request $request) => cbdb_legacy_codes_redirect('app.codes.proposals.edit', $request))
    ->name('codes.proposals.edit');
Route::patch('codes/{table_name}/proposals/{operation}', fn () => abort(410, 'Legacy codes proposal update endpoint has been removed; use /app/codes/{table_name}/proposals/{operation}.'))
    ->name('codes.proposals.update');
Route::delete('codes/{table_name}/proposals/{operation}', fn () => abort(410, 'Legacy codes proposal cancel endpoint has been removed; use /app/codes/{table_name}/proposals/{operation}.'))
    ->name('codes.proposals.cancel');
Route::match(['post', 'patch'], 'codes/{table_name}/{id}/proposal', fn () => abort(410, 'Legacy codes update-proposal endpoint has been removed; use /app/codes/{table_name}/{id}/proposal.'))
    ->name('codes.propose.update')->where('id', '.*');
Route::get('codes/{table_name}/{id}/edit', fn (\Illuminate\Http\Request $request) => cbdb_legacy_codes_redirect('app.codes.edit', $request))
    ->name('codes.edit')->where('id', '.*');
Route::match(['put', 'patch'], 'codes/{table_name}/{id}', fn () => abort(410, 'Legacy codes update endpoint has been removed; use /app/codes/{table_name}/{id}.'))
    ->name('codes.update')->where('id', '.*');
Route::post('codes/{table_name}', fn () => abort(410, 'Legacy codes store endpoint has been removed; use /app/codes/{table_name}.'))
    ->name('codes.store');
Route::delete('codes/{table_name}/{id}', fn () => abort(410, 'Legacy codes destroy endpoint has been removed; use /app/codes/{table_name}/{id}.'))
    ->name('codes.destroy')->where('id', '.*');

Route::post('operations/{operation}/approve', 'OperationsProposalController@approve')->name('operations.proposals.approve');
Route::post('operations/{operation}/reject', 'OperationsProposalController@reject')->name('operations.proposals.reject');
// 提案人撤回（與資源無關）：實體級提案的 resource 是聚合名，codes.proposals.cancel 的表名路徑段對它必 404。
Route::delete('operations/{operation}/cancel', 'OperationsProposalController@cancel')->name('operations.proposals.cancel');

// Legacy 使用者管理：原為 Route::resource，拆成顯式路由才能逐 action 掛不同處置
// （見 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md）——index/edit 導向 React，
// store/update/destroy 是 legacy 寫入端回 410，create/show 的 controller 方法本來就是空的。
// 路由名與 URI 全部原樣保留，Blade 視圖與 controller 也都沒刪（環節 3「先封路、不刪碼」）。
Route::get('manage', 'ManagementController@index')
    ->middleware('legacy.page:app.manage.index')->name('manage.index');
Route::get('manage/create', 'ManagementController@create')
    ->middleware('legacy.page:gone')->name('manage.create');
Route::post('manage', 'ManagementController@store')
    ->middleware('legacy.page:gone')->name('manage.store');
Route::get('manage/{manage}/edit', 'ManagementController@edit')
    ->middleware('legacy.page:app.manage.edit')->name('manage.edit');
Route::get('manage/{manage}', 'ManagementController@show')
    ->middleware('legacy.page:gone')->name('manage.show');
Route::match(['put', 'patch'], 'manage/{manage}', 'ManagementController@update')
    ->middleware('legacy.page:gone')->name('manage.update');
Route::delete('manage/{manage}', 'ManagementController@destroy')
    ->middleware('legacy.page:gone')->name('manage.destroy');
// Inertia + React 版（使用者管理列表 + 編輯）
Route::get('app/manage', 'ManagementController@appIndex')
    ->middleware(['auth', 'inertia'])
    ->name('app.manage.index');
Route::get('app/manage/{manage}/edit', 'ManagementController@appEdit')
    ->middleware(['auth', 'inertia'])
    ->name('app.manage.edit');
Route::match(['put', 'patch'], 'app/manage/{manage}', 'ManagementController@appUpdate')
    ->middleware(['auth', 'inertia'])
    ->name('app.manage.update');

// GET 導向 React、POST（legacy 表單送出）回 410；原本是 match(['get','post']) 同一條。
Route::get('merge-preview', function (\Illuminate\Http\Request $request) {
    return redirect()->to('/app/merge-preview'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->name('merge-preview.index');
// legacy merge-preview 表單的 POST 端點：原本指向 `MergePreviewController@index`
// （Blade 版用 POST 回同一頁顯示結果），環節 3 已封成 410。環節 4a-3 刪掉該方法後
// 必須改成 closure——否則 kill switch 一關就會 500（`RouteActionsExistTest` 抓到的正是這個）。
Route::post('merge-preview', fn () => abort(410, '舊版合併預覽表單已停用，請改用 /app/merge-preview。'))
    ->name('merge-preview.store');
Route::get('app/merge-preview', 'MergePreviewController@appIndex')->name('app.merge-preview.index')->middleware('inertia');

// 原本這裡是 `Route::resource('operations', ...)`，但 OperationsController 只實作 index()
// 與一個空的 store()；resource 因此生出 create／show／edit／update／destroy 五條指向不存在
// 方法的路由，命中即 500（#1250）。index 已在本檔開頭以顯式路由宣告（operations.index），
// 空的 store 沒有任何呼叫端，故整段移除；確認過全庫沒有引用被拿掉的那些路由名稱。
Route::post('operations/{operation}/restore', 'OperationsController@restore')->name('operations.restore');

Route::middleware('auth')->group(function () {
    Route::get('profile', 'UserProfileController@edit')
        ->middleware('legacy.page:app.profile.edit')->name('profile.edit');
    Route::patch('profile', 'UserProfileController@update')
        ->middleware('legacy.page:gone')->name('profile.update');
    // Inertia + React 版
    Route::get('app/profile', 'UserProfileController@appEdit')
        ->middleware('inertia')
        ->name('app.profile.edit');
    Route::patch('app/profile', 'UserProfileController@appUpdate')
        ->middleware('inertia')
        ->name('app.profile.update');

    // API Token 管理
    Route::get('api-tokens', 'ApiTokenController@index')->name('api-tokens.index');
    Route::post('api-tokens', 'ApiTokenController@store')->name('api-tokens.store');
    Route::delete('api-tokens/{tokenId}', 'ApiTokenController@destroy')->name('api-tokens.destroy');
    Route::delete('api-tokens', 'ApiTokenController@destroyAll')->name('api-tokens.destroy-all');

    // 檢視表（Inertia + React）
    Route::get('app/view', 'ViewTableController@appIndex')
        ->middleware('inertia')
        ->name('app.view.index');
    Route::get('app/view/{key}', 'ViewTableController@appShow')
        ->middleware('inertia')
        ->name('app.view.show');

    // ── 暫不公開：僅管理員可訪問 ──────────────────────────────────────
    Route::middleware('superadmin')->group(function () {
        // 最近眾包錄入記錄
        Route::get('crowdsourcing', function (\Illuminate\Http\Request $request) {
            return redirect()->to('/app/crowdsourcing'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
        })->name('crowdsourcing.index');
        Route::get('app/crowdsourcing', ['as' => 'app.crowdsourcing.index', 'uses' => 'CrowdsourcingController@appIndex'])->middleware('inertia');
        // 同 operations（#1250）：CrowdsourcingController 只有 index()／appIndex()／
        // confirm()／reject() 與一個空的 store()，resource 生出的 create／show／edit／
        // update／destroy 全是 500。index 已於上方顯式宣告（同在 superadmin 群組內），
        // 空的 store 無呼叫端，整段移除。
        Route::get('crowdsourcing/{id}/confirm', 'CrowdsourcingController@confirm');
        Route::get('crowdsourcing/{id}/reject', 'CrowdsourcingController@reject');

        // 人物瀏覽工作台（Inertia + React）
        Route::get('app/person-browser', 'PersonBrowserController@index')
            ->middleware('inertia')
            ->name('app.person-browser.index');
        Route::get('app/person-browser/search', 'PersonBrowserController@search')
            ->name('app.person-browser.search');
        Route::get('app/person-browser/people/{personId}/summary', 'PersonBrowserController@summary')
            ->where('personId', '[0-9]+')
            ->name('app.person-browser.summary');
        Route::get('app/person-browser/people/{personId}/tabs/{tabKey}', 'PersonBrowserController@tab')
            ->where('personId', '[0-9]+')
            ->name('app.person-browser.tab');

        // 按入仕查詢（Inertia + React）
        Route::get('app/search-by/entry', 'SearchByEntryController@index')
            ->middleware('inertia')
            ->name('app.search-by.entry.index');
        Route::get('app/search-by/entry/types', 'SearchByEntryController@getEntryTypes')->name('app.search-by.entry.types');
        Route::get('app/search-by/entry/codes', 'SearchByEntryController@getEntryCodes')->name('app.search-by.entry.codes');
        Route::get('app/search-by/entry/places', 'SearchByEntryController@getPlaces')->name('app.search-by.entry.places');
        Route::get('app/search-by/entry/query', 'SearchByEntryController@query')->name('app.search-by.entry.query');

        // 歷史地圖（需登入且為 superadmin）
        Route::get('app/maps', 'HistoricalMapsController@index')
            ->name('app.maps.index');
    });
    // ─────────────────────────────────────────────────────────────────

    // Legacy maps 重定向（需登入，目標 /app/maps 本身已有 superadmin 保護）
    Route::get('maps', 'HistoricalMapsController@legacyRedirect')->name('maps.index');
    Route::get('maps/index.html', 'HistoricalMapsController@legacyRedirect');
    Route::get('maps/tang', 'HistoricalMapsController@legacyRedirect');
    Route::get('maps/tang/{path?}', 'HistoricalMapsController@legacyRedirect')->where('path', '.*');

    Route::get('admin/explainsql', 'AdminExplainSqlController@show')
        ->middleware('legacy.page:app.admin.explainsql')->name('admin.explainsql');
    Route::post('admin/explainsql', 'AdminExplainSqlController@explain')->middleware('legacy.page:gone');
    // Inertia + React 版（表單頁；GET 顯示、POST 跑 EXPLAIN 後重新 render）
    Route::get('app/admin/explainsql', 'AdminExplainSqlController@appShow')
        ->middleware('inertia')
        ->name('app.admin.explainsql');
    Route::post('app/admin/explainsql', 'AdminExplainSqlController@appExplain')
        ->middleware('inertia')
        ->name('app.admin.explainsql.explain');
    Route::get('admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@showForm')
        ->middleware('legacy.page:app.admin.batch-load-book-titles')->name('admin.batch-load-book-titles');
    Route::post('admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@store')
        ->middleware('legacy.page:gone')->name('admin.batch-load-book-titles.store');
    Route::post('admin/batch-load-book-titles/undo', 'AdminBatchLoadBookTitlesController@undo')
        ->middleware('legacy.page:gone')->name('admin.batch-load-book-titles.undo');
    Route::post('admin/batch-load-book-titles/update-pinyin', 'AdminBatchLoadBookTitlesController@updatePinyin')
        ->middleware('legacy.page:gone')->name('admin.batch-load-book-titles.update-pinyin');
    // Inertia + React 版（store/undo 重用既有方法，依請求路徑決定重導）
    Route::get('app/admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@appShowForm')
        ->middleware('inertia')->name('app.admin.batch-load-book-titles');
    Route::post('app/admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@store')
        ->middleware('inertia')->name('app.admin.batch-load-book-titles.store');
    Route::post('app/admin/batch-load-book-titles/undo', 'AdminBatchLoadBookTitlesController@undo')
        ->middleware('inertia')->name('app.admin.batch-load-book-titles.undo');
    // 逐列直接編輯拼音（回傳 JSON，React 以 fetch 呼叫並就地更新該列；重用既有 updatePinyin）
    Route::post('app/admin/batch-load-book-titles/update-pinyin', 'AdminBatchLoadBookTitlesController@updatePinyin')
        ->name('app.admin.batch-load-book-titles.update-pinyin');
    // 罕見字檢測（回傳 JSON）：只查 pinyin 表，列出表未收的漢字與行號，匯入前先行檢查。
    Route::post('app/admin/batch-load-book-titles/check-rare-chars', 'AdminBatchLoadBookTitlesController@checkRareChars')
        ->name('app.admin.batch-load-book-titles.check-rare-chars');
    Route::get('admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@showForm')
        ->middleware('legacy.page:app.admin.batch-load-social-institutes')->name('admin.batch-load-social-institutes');
    Route::post('admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@store')
        ->middleware('legacy.page:gone')->name('admin.batch-load-social-institutes.store');
    // Inertia + React 版（store 重用，依請求路徑重導）
    Route::get('app/admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@appShowForm')
        ->middleware('inertia')->name('app.admin.batch-load-social-institutes');
    Route::post('app/admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@store')
        ->middleware('inertia')->name('app.admin.batch-load-social-institutes.store');
    Route::get('admin/batch-load-offices', 'AdminBatchLoadOfficesController@showForm')
        ->middleware('legacy.page:app.admin.batch-load-offices')->name('admin.batch-load-offices');
    Route::post('admin/batch-load-offices', 'AdminBatchLoadOfficesController@store')
        ->middleware('legacy.page:gone')->name('admin.batch-load-offices.store');
    // Inertia + React 版（store 重用，依請求路徑重導）
    Route::get('app/admin/batch-load-offices', 'AdminBatchLoadOfficesController@appShowForm')
        ->middleware('inertia')->name('app.admin.batch-load-offices');
    Route::post('app/admin/batch-load-offices', 'AdminBatchLoadOfficesController@store')
        ->middleware('inertia')->name('app.admin.batch-load-offices.store');
    // 外部資料庫引用瀏覽器：已開放活躍帳號，路徑不再帶 admin 前綴（controller 沿用 WikiMaintenanceController 名稱）。
    Route::get('external-db-link', 'WikiMaintenanceController@index')->name('external-db-link');
    Route::get('app/external-db-link', 'WikiMaintenanceController@appIndex')->name('app.external-db-link')->middleware('inertia');
    Route::get('admin/cbdb-table-maintenance', 'CbdbTableMaintenanceController@index')
        ->middleware('legacy.page:app.admin.cbdb-table-maintenance')->name('admin.cbdb-table-maintenance');
    Route::get('app/admin/cbdb-table-maintenance', 'CbdbTableMaintenanceController@appIndex')->name('app.admin.cbdb-table-maintenance')->middleware('inertia');
    Route::post('admin/cbdb-table-maintenance/rebuild', 'CbdbTableMaintenanceController@rebuild')->name('admin.cbdb-table-maintenance.rebuild');
    Route::get('admin/cbdb-table-maintenance/progress/{taskId}', 'CbdbTableMaintenanceController@getNameFtsProgress')
        ->where('taskId', '[a-zA-Z0-9_]+')
        ->name('admin.cbdb-table-maintenance.progress');
    Route::get('admin/unidirectional-relationship-repair', 'UnidirectionalRelationshipRepairController@index')
        ->middleware('legacy.page:app.admin.unidirectional-relationship-repair')->name('admin.unidirectional-relationship-repair');
    Route::get('app/admin/unidirectional-relationship-repair', 'UnidirectionalRelationshipRepairController@appIndex')->name('app.admin.unidirectional-relationship-repair')->middleware('inertia');
    Route::post('admin/unidirectional-relationship-repair/kinship', 'UnidirectionalRelationshipRepairController@repairKinship')->name('admin.unidirectional-relationship-repair.kinship');
    // Query Playground
    Route::get('query-playground', 'QueryPlaygroundController@index')->name('query-playground.index');
    Route::post('query-playground/run', 'QueryPlaygroundController@run')->name('query-playground.run');
    Route::post('query-playground/schema', 'QueryPlaygroundController@qbeSchema')->name('query-playground.schema');
    Route::post('query-playground/generate-from-nl', 'QueryPlaygroundController@generateFromNL')->name('query-playground.generate-from-nl');
    Route::post('query-playground/generate-from-nl-stream', 'QueryPlaygroundController@generateFromNLStream')->name('query-playground.generate-from-nl-stream');
    // QA 模式多輪追問每輪都會呼叫一次 LLM API，屬有實際成本的操作；比照 routes/ai.php 既有
    // throttle 慣例，依登入使用者 ID 限流（見 docs/QUERY_PLAYGROUND_QA_MULTITURN_PLAN.md 第 6.4 節）。
    // 用具名 limiter（定義於 RouteServiceProvider::boot()）而非直接把數字內插進字串：
    // 內插字串在路由註冊當下就把上限值定死，之後改 config 不會生效；具名 limiter 的 callback
    // 於每次請求時才讀取 config，才能真正做到「config 驅動」且可在測試中動態調整。
    Route::post('query-playground/answer-from-nl', 'QueryPlaygroundController@answerFromNL')
        ->middleware('throttle:qa-answer')
        ->name('query-playground.answer-from-nl');
    Route::post('query-playground/answer-from-nl-stream', 'QueryPlaygroundController@answerFromNLStream')
        ->middleware('throttle:qa-answer')
        ->name('query-playground.answer-from-nl-stream');
    Route::get('query-playground/nl-query-logs', function (\Illuminate\Http\Request $request) {
        return redirect()->to('/app/query-playground/nl-query-logs'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->name('query-playground.nl-query-logs');
    Route::get('app/query-playground/nl-query-logs', 'QueryPlaygroundController@appNlQueryLogs')
        ->middleware('inertia')
        ->name('app.query-playground.nl-query-logs');

    // Query Playground（Inertia + React）
    Route::get('app/query-playground', 'QueryPlaygroundController@appIndex')
        ->middleware('inertia')
        ->name('app.query-playground.index');

    // AI 智能填充任官信息
    Route::post('api/ai/posting/extract', 'AiPostingAutofillController@extract')->name('ai.posting.extract');

    // AI 智能識別代碼（社會關係 / 社會區分）
    Route::post('api/ai/code-lookup/suggest', 'CodeLookupController@suggest')->name('ai.code-lookup.suggest');

    // AI 填充日誌（管理員工具）
    Route::get('admin/ai-fill-logs', function (\Illuminate\Http\Request $request) {
        return redirect()->to('/app/admin/ai-fill-logs'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->name('admin.ai-fill-logs');
    Route::get('app/admin/ai-fill-logs', 'AiFillLogController@appIndex')
        ->middleware('inertia')
        ->name('app.admin.ai-fill-logs');
    Route::get('admin/audit-logs', function (\Illuminate\Http\Request $request) {
        return redirect()->to('/app/admin/audit-logs'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->name('admin.audit-logs');
    // Inertia + React 版（與舊 Blade 版並存；側邊欄指向由 migration flag 控制）
    Route::get('app/admin/audit-logs', 'AdminAuditLogController@appIndex')
        ->middleware('inertia')
        ->name('app.admin.audit-logs');

    Route::post('admin/unidirectional-relationship-repair/assoc', 'UnidirectionalRelationshipRepairController@repairAssoc')->name('admin.unidirectional-relationship-repair.assoc');
});
