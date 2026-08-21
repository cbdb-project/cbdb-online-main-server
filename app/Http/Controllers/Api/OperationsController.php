<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiogMain;
use App\Models\OfficeCode;
use App\Models\OfficeCodeTypeRel;
use App\Models\OfficeTypeTree;
use App\Models\Operation;
use App\Models\User;
use App\Repositories\BiogMainRepository;
use App\Services\CharVariantMapService;
use App\Services\SecurityAuditLogger;
use App\Support\VariantReplaceScope;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OperationsController extends Controller {
    /**
     * 依 confirmation_token 解析呼叫者，並要求帳號為啟用狀態（is_active=1）。
     * 找不到對應使用者、或帳號未啟用（含被停用者）一律回 null，寫入端據此拒絕，
     * 避免未啟用帳號僅憑外洩／自查的 token 就能經此通道寫入 operations。
     */
    private function resolveActiveUserByToken($token): ?User {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $user = User::where('confirmation_token', $token)->first();
        if (!$user || !$user->isActive()) {
            return null;
        }

        return $user;
    }

    public function add(Request $request) {
        //用來將json存入operations
        $x = $this->add_operations($request);

        return $x;
    }

    public function update(Request $request) {
        //要製作update_operations
        $x = $this->update_operations($request);

        return $x;
    }

    public function del(Request $request) {
        //要製作destroy_operations
        $x = $this->destroy_operations($request);

        return $x;
    }

    /**
     * 對客戶端送來的 JSON payload 套異體字落地替換（v1 token API 仍在服役）。
     *
     * 幾個刻意的選擇：
     * - **先過 fail-closed**：`$resource` 由客戶端任意給、無白名單，未知表一律原樣存入
     *   （`VariantReplaceScope::modeFor()` 內部也會 fail-closed，這裡先擋是為了連 decode 都省）。
     * - **decode 失敗就原樣存入**：維持現況語義（壞 JSON 原樣存、到 `CrowdsourcingController::confirm()`
     *   才爆），不可變成字串 `"null"`，也不可在此當場拋錯而改變既有 API 行為。
     * - re-encode 用 `JSON_UNESCAPED_UNICODE`：中文以原字元存，比較與人工檢視都直覺；
     *   代價是既存位元內容（鍵序／escaping）可能與客戶端原字串不同，這是可接受的。
     *
     * @param mixed $json 客戶端送來的原始 JSON 字串
     */
    private function replaceVariantsInJsonPayload($json, ?string $resource): string {
        $raw = (string) $json;

        // **只有與註冊表拼法完全相同時才替換**，刻意與下游的分派保持對稱：
        // `CrowdsourcingController::confirm()` 的 switch 與 `update_operations()` 的
        // `$y == "BIOG_MAIN"` 都是精確比對，所以 `" biog_main "` 這種鬆散寫法根本不會被寫入
        // 任何表。若這裡放行它去替換，就會出現「payload 被改寫、卻永遠不會落庫」的不對稱，
        // 而且等於讓未驗證的外部字串進到 schema 查詢（那條路徑的空結果會被快取住，
        // 讓該表在這個 process 之後都不替換——S1 註解警告過的靜默失效）。
        $canonical = $resource === null || $resource === ''
            ? null
            : VariantReplaceScope::canonicalTableName($resource);
        if ($canonical === null || $canonical !== $resource) {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }

        $replaced = CharVariantMapService::replaceRow($decoded, $canonical)['data'];
        $encoded = json_encode($replaced, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $raw : $encoded;
    }

    public function add_operations($keyword) {
        $user = $this->resolveActiveUserByToken($keyword['token'] ?? null);
        if (!$user) {
            return response('403', 403);
        }
        $token = $user->id;
        $x = $keyword['json'];
        if (empty($x)) {
            return '500';
        }
        $y = $keyword['resource'];
        if (empty($y)) {
            return '500';
        }

        $operation = new Operation();
        $operation->resource = $y;
        $operation->resource_data = $this->replaceVariantsInJsonPayload($x, $y);
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        $operation->op_type = 1;
        //crowdsourcing_status欄位值說明
        //0.專業用戶修改紀錄
        //1.crowdsourcing記錄並已插入數據庫
        //2.crowdsourcing記錄還沒有被處理
        //3.crowdsourcing記錄reject
        $message = $operation->save();
        $msgArray['status_code'] = 200;
        $msgArray['message'] = "if you submitted a new person/address/office... id, it might be changed by auto increment primary key mechanism, when your data will be approved. Please contact CBDB project manager, if you want to customize the id. 您提交的數據中若包含新人名/地名/官名等 id，這些 id 可能在這條記錄被確認匯入系統之後發生改變。因此，如果您希望將這些 id 設定為固定值，請聯絡 CBDB 項目經理。";
        $message ? $message = $msgArray : $message = '500';

        return $message;
    }

    public function update_operations($keyword) {
        $user = $this->resolveActiveUserByToken($keyword['token'] ?? null);
        if (!$user) {
            return response('403', 403);
        }
        $token = $user->id;
        $x = $keyword['json'];
        if (empty($x)) {
            return '500';
        }
        $y = $keyword['resource'];
        if (empty($y)) {
            return '500';
        }
        //20191224增加判斷式，開放其他API進入。
        if ($y == "BIOG_MAIN") {
            $c_personid = $keyword['c_personid'];
            $resource_id = $c_personid;
            if (empty($c_personid)) {
                return '500';
            }
            $BiogMainRepository = new BiogMainRepository();
            $ori = $BiogMainRepository->byPersonId($c_personid);
        } else {
            $pId = $keyword['pId'];
            $c_personid = "";
            $resource_id = $pId;
            switch ($y) {
                case "OFFICE_CODES":
                    $ori = OfficeCode::find($pId);

                    break;
                case "OFFICE_CODE_TYPE_REL":
                    $temp_l = explode("-", $pId);
                    $ori = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first();

                    break;
                case "OFFICE_TYPE_TREE":
                    $ori = OfficeTypeTree::find($pId);

                    break;
                default:
                    $ori = null;

                    break;
            }
        }
        $operation = new Operation();
        $operation->resource = $y;
        $operation->c_personid = $c_personid;
        $operation->resource_id = $resource_id;
        $operation->resource_data = $this->replaceVariantsInJsonPayload($x, $y);
        // resource_original 是**歷史快照**（更新前的既有列），刻意不替換——那是「當時實際
        // 長什麼樣」的事實，改寫它等於偽造紀錄（與 restore 不做內容替換同一個道理）。
        $operation->resource_original = $ori;
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        $operation->op_type = 3;
        $message = $operation->save();
        $message ? $message = '200' : $message = '500';

        return $message;
    }

    public function destroy_operations($keyword) {
        $user = $this->resolveActiveUserByToken($keyword['token'] ?? null);
        if (!$user) {
            return response('403', 403);
        }
        $token = $user->id;
        $y = $keyword['resource'];
        if (empty($y)) {
            return '500';
        }
        //20191224增加判斷式，開放其他API進入。
        if ($y == "BIOG_MAIN") {
            $c_personid = $keyword['c_personid'];
            $resource_id = $c_personid;
            if (empty($c_personid)) {
                return '500';
            }
            $BiogMainRepository = new BiogMainRepository();
            $ori = $BiogMainRepository->byPersonId($c_personid);
            $biog = BiogMain::find($c_personid);
            $biog->c_name_chn = '<待删除>';
        } else {
            $pId = $keyword['pId'];
            $c_personid = "";
            $resource_id = $pId;
            $ori = null;
            switch ($y) {
                case "OFFICE_CODES":
                    $biog = OfficeCode::find($pId);

                    break;
                case "OFFICE_CODE_TYPE_REL":
                    $temp_l = explode("-", $pId);
                    $biog = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first();

                    break;
                case "OFFICE_TYPE_TREE":
                    $biog = OfficeTypeTree::find($pId);

                    break;
                default:
                    $biog = null;

                    break;
            }
        }
        $operation = new Operation();
        $operation->resource = $y;
        $operation->c_personid = $c_personid;
        $operation->resource_id = $resource_id;
        $operation->resource_data = $biog;
        $operation->resource_original = $ori;
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        $operation->op_type = 4;
        $message = $operation->save();
        $message ? $message = '200' : $message = '500';

        return $message;
    }

    public function storeProcess(Request $request) {
        //20190531這邊要取得table名稱, 規劃建置switch case來處理各種儲存
        $id = $request['id'];
        DB::table('operations')->where('id', $id)->update(['crowdsourcing_status' => 1]);
        $operationRow = DB::table('operations')->where('id', $id)->first();
        $resourceData = $operationRow ? $operationRow->resource_data : null;
        $data = $resourceData ? json_decode($resourceData, true) : [];
        $new_id = BiogMain::max('c_personid') + 1;
        $data['c_personid'] = $new_id;
        $actorId = $operationRow ? (string) $operationRow->user_id : '';
        $message = null;
        DB::transaction(function () use (&$message, $data, $actorId) {
            $message = BiogMain::create($data);

            (new \App\Services\AuditLogService())->write(
                'BIOG_MAIN',
                'INSERT',
                ['c_personid' => $data['c_personid']],
                null,
                $message->toArray(),
                'api',
                $actorId
            );
        });
        $message = $message ? '200' : '500';

        return $message;
    }

    public function token(Request $request) {
        $user_id = $request->q;
        $user_password = $request->p;

        // 刻意用 Hash::check 而不是 Auth::attempt：這條路由不掛 auth，但 api middleware group
        // 的 EnsureFrontendRequestsAreStateful 在請求來自前端網域時會補上 StartSession，
        // 此時 Auth::attempt 會順手建立一個 web session——一個「發放 token 的端點」不該有
        // 登入副作用，停用帳號那條分支更不該留下已認證的 session 等帳號重新啟用後復活。
        $user = is_string($user_id) && $user_id !== ''
            ? User::where('email', $user_id)->first()
            : null;

        // 這是唯一剩下的長期憑證簽發路徑（confirmation_token），所以成功與被拒都要留紀錄
        // ——否則連暴力破解都看不見。一律不記 token 值。
        // 限流見 routes/api.php 的 throttle:crowdsourcing-token（#1264 之前這裡完全沒有節流）。
        $audit = app(SecurityAuditLogger::class);

        if (!$user || !is_string($user_password) || !Hash::check($user_password, $user->password)) {
            // 查不到帳號時 rowPk 留空，不要記成 id=0：那會憑空生出一列「受影響的 users.id=0」，
            // 反覆用不存在的 email 嘗試時，調查者會誤以為真有這個使用者。
            // 也不回記使用者送來的原始 email——那是未驗證、無長度限制的輸入，
            // 而這個端點沒有 throttle，原樣入庫等於讓人隨意撐大 audit_log。
            $audit->record(
                table: 'users',
                operation: 'UPDATE',
                rowPk: $user ? ['id' => (int) $user->id] : [],
                event: 'crowdsourcing_token_denied',
                after: [
                    'reason' => 'bad_credentials',
                    'matched_user' => (bool) $user,
                    // 只有查到帳號時才記 email，且用 DB 裡的值（有界）而非請求輸入。
                    'email' => $user?->email,
                ]
            );

            // #1264：改用 401。原本三條失敗路徑都回 200，於是「把 200 的 body 當 token 用」的
            // 客戶端會拿著「您的帳號與密碼輸入錯誤」這串字去當憑證，而監控也看不出這裡有沒有
            // 被暴力破解。body 文字刻意不變（既有客戶端可能在比對字串），只補上狀態碼。
            return response("您的帳號與密碼輸入錯誤", 401);
        }

        // 停用（含從未啟用）帳號不得換到 token：resolveActiveUserByToken() 已擋住用它寫入，
        // 但 confirmation_token 本身就是一個長期有效的憑證，不該外流。
        if (!$user->isActive()) {
            $audit->record(
                table: 'users',
                operation: 'UPDATE',
                rowPk: ['id' => (int) $user->id],
                event: 'crowdsourcing_token_denied',
                after: ['reason' => 'account_inactive']
            );

            // 憑證正確但帳號狀態不允許 → 403（與站內 Authenticate middleware 的語義一致）。
            return response(__('auth.account_inactive'), 403);
        }

        if (!$user->isCrowdsourcingUser()) {
            $audit->record(
                table: 'users',
                operation: 'UPDATE',
                rowPk: ['id' => (int) $user->id],
                event: 'crowdsourcing_token_denied',
                after: ['reason' => 'not_crowdsourcing_role']
            );

            return response("帳號須為眾包身分，才可以取得token。", 403);
        }

        $audit->record(
            table: 'users',
            operation: 'UPDATE',
            rowPk: ['id' => (int) $user->id],
            event: 'crowdsourcing_token_issued',
            after: ['email' => $user->email]
        );

        // 原樣回傳，不替空值補錯誤字串：既有眾包客戶端把 200 的 body 直接當 token 用，
        // 換成人話錯誤反而會讓它拿著一個看起來合法的字串繼續打。
        // （users.confirmation_token 是 NOT NULL，實務上不會是空值。）
        return $user->confirmation_token;
    }
}
