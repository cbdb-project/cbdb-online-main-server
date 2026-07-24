<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OperationsProposalController extends Controller {
    protected $operationRepository;
    protected $nameSearchIndexService;
    protected $biogMainRepository;
    protected array $tableColumnCache = [];

    /**
     * 表名到模型類的映射
     * 用於將審批應用到資料表時使用 Eloquent 模型，以觸發觀察者
     *
     * @var array
     */
    protected $tableModelMap = [
        'BIOG_MAIN' => \App\Models\BiogMain::class,
        // 未來可以添加更多表的映射
        // 注意：ALTNAME_DATA 使用復合主鍵，不使用 Eloquent，改為手動調用索引服務
    ];

    /**
     * 核准套用時的「不可清空」欄位守衛（per-table）：payload 將該欄寫成空、而資料列當下有值時，
     * 擋下核准。兜住「提交端驗證修復前的存量 pending 提案」與 legacy 提交路徑（所有提案核准必經此處）。
     * 以「當下 DB 現值」而非提案存檔時的 original 比對：提案送出後若他人已補值，清空它的舊提案照樣被擋。
     */
    protected const NO_CLEAR_COLUMNS_ON_APPLY = [
        'BIOG_MAIN' => [
            'c_mingzi_chn' => '名（中）',
            'c_mingzi' => '拼音名',
        ],
    ];

    /**
     * 段一：以 v2 mutation handler 重放核准的人物子資源提案（表名 → API resource）。
     *
     * 這些表的提案原本落到通用行覆寫（applyCreate/Update/DeleteProposal），繞過聚合的
     * 派生／護欄／audit／索引同步——「核准」退化成盲寫一行、與「直接編輯」行為分歧。
     * 改為重建 {resource, 'direct', operation, personId, targetPk, changes} 重放 direct handler，
     * 使核准與直接編輯逐位一致（見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §4.5）。
     *
     * 不含：委派檔（KIN_DATA／ASSOC_DATA／POSTED_TO_OFFICE_DATA／POSSESSION_DATA／EVENTS_DATA）
     * 已走 legacy repository 委派、行為已對齊；code 表提案走 CodesController 自己的核准路徑、不經此。
     * auth 無礙：canReviewProposals() 與 canWriteDirectly() 為同一謂詞，能到核准端點者必過 authorizeDirect()。
     */
    protected const HANDLER_ROUTED_RESOURCES = [
        'ALTNAME_DATA' => 'altnames',
        'BIOG_ADDR_DATA' => 'addresses',
        'ENTRY_DATA' => 'entries',
        'STATUS_DATA' => 'statuses',
        'BIOG_TEXT_DATA' => 'texts',
        'BIOG_SOURCE_DATA' => 'sources',
        'BIOG_INST_DATA' => 'social_institutions',
    ];

    public function __construct(
        OperationRepository $operationRepository,
        NameSearchIndexService $nameSearchIndexService,
        BiogMainRepository $biogMainRepository
    ) {
        $this->operationRepository = $operationRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
        $this->biogMainRepository = $biogMainRepository;
    }

    public function approve(Request $request, Operation $operation) {
        $this->ensureCanReview($operation);

        $payload = $this->decodeResourceData($operation);
        $original = $this->decodeResourceOriginal($operation);
        $table = $operation->resource;
        $keyColumns = $payload['__key_columns'] ?? [];
        $opType = (int) $operation->op_type;

        if (empty($keyColumns)) {
            flash('審核失敗：提案缺少主鍵資訊。', 'error');

            return redirect()->back();
        }

        $data = $this->normalizeRowForTable($table, $this->sanitizePayload($payload, $table));
        $original = $this->normalizeRowForTable($table, $original);
        $auxiliaryPayload = $this->extractAuxiliaryPayload($payload, $table);
        $comment = trim((string) $request->input('review_comment', ''));

        try {
            DB::transaction(function () use ($opType, $table, $data, $keyColumns, $original, $operation, $comment, $auxiliaryPayload) {
                [$appliedRow, $usedDirectWorkflow] = $this->applyProposal(
                    $operation,
                    $table,
                    $data,
                    $keyColumns,
                    $original,
                    $auxiliaryPayload
                );

                if (!$usedDirectWorkflow) {
                    $finalOperation = $this->logFinalOperation($operation, $appliedRow, $original, $opType);
                    $this->writeAuditLogForApproval($operation, $appliedRow, $original, $opType);

                    // 社會關係／親屬刪除核准：在 final delete operation 建立後同步刪除反向鏡像列，
                    // 使鏡像 audit 掛同一 operation id（與 direct delete 一致，避免單向孤兒且審計鏈完整）。
                    if ($opType === Operation::TYPE_PROPOSAL_DELETE && $table === 'ASSOC_DATA') {
                        app(\App\Repositories\BiogMainRepository::class)->syncAssocMirrorOnDelete($appliedRow, $finalOperation);
                    }
                    if ($opType === Operation::TYPE_PROPOSAL_DELETE && $table === 'KIN_DATA') {
                        // 核准為非互動路徑，沿用「刪除全部對應反向列」語義（$force=true）：取得 #81 §6 廣集孤兒修正，
                        // 不在此拋多筆確認閘（是否於核准路徑加偵測閘由 #82 統一評估）。
                        app(\App\Repositories\BiogMainRepository::class)->syncKinMirrorOnDelete($appliedRow, $finalOperation, null, true);
                    }
                }
                $this->updateProposalStatus(
                    $operation,
                    'approved',
                    $comment,
                    $opType === Operation::TYPE_PROPOSAL_CREATE ? $appliedRow : null,
                    $keyColumns,
                    $opType === Operation::TYPE_PROPOSAL_CREATE
                );
            });
        } catch (ValidationException $e) {
            $messages = $e->validator->errors()->all();
            $detail = implode('；', $messages);
            Log::warning('提案核准失敗（驗證錯誤）', [
                'operation_id' => $operation->id,
                'table' => $table,
                'errors' => $messages,
            ]);
            flash('審核失敗：'.$detail, 'error');

            return redirect()->back();
        } catch (\App\Services\Mutations\MirrorConflictException|\App\Services\Mutations\MirrorSuspectedException $e) {
            // #77：核准社會關係／親屬更新提案時，偵測到對面互逆鏡像列已被獨立改動（內容分歧／關係碼漂移）或資料完整性問題。
            // 整筆交易已回滾、提案未核准——避免靜默覆寫對方資料。回友善中文提示（不外洩底層 SQL），引導審核者先至對面確認。
            Log::warning('提案核准中止：對面鏡像分歧/疑似', [
                'operation_id' => $operation->id,
                'table' => $table,
                'exception' => get_class($e),
            ]);
            flash('審核未通過：偵測到對應的反向關係列已被獨立修改（內容或關係碼不一致）。為避免覆寫對方資料，已中止此次核准——請先至對應人物頁確認/修正反向關係後再核准。', 'error');

            return redirect()->back();
        } catch (\App\Services\Mutations\MirrorIntegrityException $e) {
            // fail-closed：鏡像同步所需的配對碼/權威反向碼缺失，若繼續核准會造成單邊刪除或假成功。
            Log::warning('提案核准中止：鏡像資料完整性 fail-closed', [
                'operation_id' => $operation->id,
                'table' => $table,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            flash('審核未通過：對應的反向關係資料完整性異常，為避免產生單邊刪除或不一致鏡像，已中止此次核准。請先檢查關係碼與對應人物資料後再重試。', 'error');

            return redirect()->back();
        } catch (\Illuminate\Database\QueryException $e) {
            // #77：DB 層錯誤（如核准 create 提案時對面已存在等價鏡像導致主鍵衝突）→ 整筆已回滾。
            // 回友善中文提示，**不外洩原始 SQL／錯誤字串**給審核者（完整訊息只進 log）。
            Log::error('提案核准失敗（資料庫錯誤）', [
                'operation_id' => $operation->id,
                'table' => $table,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            flash('審核失敗：資料庫操作發生衝突或錯誤（可能對應記錄已存在或已被變更），本次未核准。請重新整理後確認資料狀態，或聯絡管理員。', 'error');

            return redirect()->back();
        } catch (\Throwable $e) {
            Log::error('提案核准失敗', [
                'operation_id' => $operation->id,
                'table' => $table,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            flash('審核失敗：'.$e->getMessage(), 'error');

            return redirect()->back();
        }

        flash('提案已核准並套用至資料表 @ '.Carbon::now(), 'success');

        return redirect()->back();
    }

    public function reject(Request $request, Operation $operation) {
        $this->ensureCanReview($operation);

        $comment = trim((string) $request->input('review_comment', ''));
        $this->updateProposalStatus($operation, 'rejected', $comment);

        flash('提案已退回 @ '.Carbon::now(), 'info');

        return redirect()->back();
    }

    protected function ensureCanReview(Operation $operation): void {
        if (!Auth::check() || !Auth::user()->canReviewProposals()) {
            abort(403, '無權審核提案。');
        }

        $opType = (int) $operation->op_type;
        if (!in_array($opType, [Operation::TYPE_PROPOSAL_CREATE, Operation::TYPE_PROPOSAL_UPDATE, Operation::TYPE_PROPOSAL_DELETE], true)) {
            abort(404);
        }
    }

    protected function decodeResourceData(Operation $operation): array {
        $payload = json_decode($operation->resource_data, true);

        return is_array($payload) ? $payload : [];
    }

    protected function decodeResourceOriginal(Operation $operation): array {
        $original = json_decode($operation->resource_original, true);

        return is_array($original) ? $original : [];
    }

    protected function sanitizePayload(array $payload, ?string $table = null): array {
        $sanitized = [];
        $columns = $this->getTableColumnMap($table);

        foreach ($payload as $key => $value) {
            if (is_string($key) && strpos($key, '__') === 0) {
                continue;
            }
            if ($columns !== null && is_string($key) && !isset($columns[$key])) {
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    protected function extractAuxiliaryPayload(array $payload, string $table): array {
        $auxiliary = [];
        $storedAuxiliary = $payload['__proposal_aux'] ?? null;
        if (is_array($storedAuxiliary)) {
            $auxiliary = $storedAuxiliary;
        }

        $columns = $this->getTableColumnMap($table);
        if ($columns === null) {
            return $auxiliary;
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || strpos($key, '__') === 0) {
                continue;
            }
            if (!isset($columns[$key])) {
                $auxiliary[$key] = $value;
            }
        }

        return $auxiliary;
    }

    protected function getTableColumnMap(?string $table): ?array {
        if ($table === null || $table === '' || !Schema::hasTable($table)) {
            return null;
        }

        if (!array_key_exists($table, $this->tableColumnCache)) {
            $this->tableColumnCache[$table] = array_flip(Schema::getColumnListing($table));
        }

        return $this->tableColumnCache[$table];
    }

    protected function applyProposal(
        Operation $operation,
        string $table,
        array $data,
        array $keyColumns,
        array $original,
        array $auxiliaryPayload
    ): array {
        // 段一：已遷移的人物子資源 UPDATE／DELETE 改由 v2 direct handler 重放，使核准與直接編輯逐位一致
        // （派生／護欄／audit／索引同步）。usedDirectWorkflow=true —— handler 自寫 operation + audit，approve() 不再補記。
        //
        // CREATE 刻意排除、續走通用 applyCreateProposal：direct-create handler 帶「同主鍵已有待審核提案則拒」
        // 去重護欄（AbstractPersonSubresourceCreateHandler／SourceMutationHandler），核准時那筆 pending 提案
        // 正是自己、resource_id 相符會被自擋。要放行需把「被核准的 operation id」作為 excludeId 一路傳進各
        // create handler 的護欄——跨切面較大，留待後續（段二）。
        if (isset(self::HANDLER_ROUTED_RESOURCES[$table])
            && (int) $operation->op_type !== Operation::TYPE_PROPOSAL_CREATE) {
            return [$this->applyViaMutationHandler($operation, $table, $data, $keyColumns, $original), true];
        }

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_DELETE) {
            return [$this->applyDeleteProposal($table, $keyColumns, $original), false];
        }

        if ($table === 'KIN_DATA') {
            return [$this->applyKinshipProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'ASSOC_DATA') {
            return [$this->applyAssocProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'POSTED_TO_OFFICE_DATA') {
            return [$this->applyOfficeProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'POSSESSION_DATA' && (int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            return [$this->applyPossessionCreateProposal($operation, $data, $auxiliaryPayload), true];
        }

        if ($table === 'POSSESSION_DATA' && (int) $operation->op_type === Operation::TYPE_PROPOSAL_UPDATE) {
            return [$this->applyPossessionUpdateProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'EVENTS_DATA') {
            return [$this->applyEventProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            return [$this->applyCreateProposal($table, $data, $keyColumns), false];
        }

        return [$this->applyUpdateProposal($table, $data, $keyColumns, $original), false];
    }

    /**
     * 段一核准重放：把 UPDATE／DELETE 提案還原成一次 direct mutation，交回同一個 v2 handler 落庫。
     * （CREATE 不經此——見 applyProposal 的排除說明。）
     *
     * 存的 resource_data 是「合併後的行快照」（data = original ∪ updateData），據此還原意圖：
     *  - update：targetPk = original 的鍵欄；changes = data 相對 original 有差異的欄位（含被改動的鍵欄，
     *            handler 內以 buildNewPk 處理改鍵）。因 data＝original∪updateData，差集恰為使用者變更。
     *  - delete：targetPk = original 的鍵欄；changes = []。
     */
    protected function applyViaMutationHandler(
        Operation $operation,
        string $table,
        array $data,
        array $keyColumns,
        array $original
    ): array {
        $resource = self::HANDLER_ROUTED_RESOURCES[$table];
        $opType = (int) $operation->op_type;
        // 這些子資源以 row 內 c_personid 為權威人物（handler 會校驗 target.pk.c_personid 與 person_id 一致）；
        // operation->c_personid 對舊/測試資料可能為 0，故不可優先。用 null 合併避免 `0 ?? x` 的陷阱。
        $personId = (int) ($data['c_personid'] ?? $original['c_personid'] ?? ($operation->c_personid ?: 0));

        // update／delete 皆以 original 定位目標列；缺 original 無從定位——沿用通用路徑的清晰契約
        // （比 handler 內「主鍵格式不正確」更有指向性）。
        if ($original === []) {
            throw new \RuntimeException(
                $opType === Operation::TYPE_PROPOSAL_DELETE ? '缺少原始資料，無法刪除。' : '缺少原始資料，無法更新。'
            );
        }

        if ($opType === Operation::TYPE_PROPOSAL_DELETE) {
            $handlerOperation = 'delete';
            $targetPk = $this->pickColumns($original, $keyColumns);
            $changes = [];
        } else {
            $handlerOperation = 'update';
            $targetPk = $this->pickColumns($original, $keyColumns);
            $changes = $this->diffChangedColumns($original, $data);
        }

        /** @var \App\Services\Mutations\MutationHandlerRegistry $registry */
        $registry = app(\App\Services\Mutations\MutationHandlerRegistry::class);
        $handler = $registry->resolve($resource, 'direct', $handlerOperation);
        if ($handler === null) {
            throw new \RuntimeException("找不到 {$resource}/{$handlerOperation} 的 mutation handler，無法套用提案。");
        }

        $response = $handler->handle($resource, 'direct', $handlerOperation, $personId, $targetPk, $changes, []);
        $status = $response->getStatusCode();
        $body = json_decode($response->getContent(), true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($body) ? (string) ($body['message'] ?? '提案套用失敗') : '提案套用失敗';

            // 交回外層交易回滾；訊息不外洩底層細節（approve() 已有 ValidationException/QueryException 友善提示）。
            throw new \RuntimeException("提案套用失敗（{$resource}/{$handlerOperation}）：{$message}");
        }

        if ($opType === Operation::TYPE_PROPOSAL_DELETE) {
            // appliedRow 於刪除後僅供日誌，回傳刪除前的原始列即可（下游 updateProposalStatus 只在 create 用到）。
            return $original;
        }

        // 讀回套用後的資料列：以 handler 回報的新主鍵定位（改鍵時 result.pk 為新鍵）。
        $newPk = is_array($body) && isset($body['result']['pk']) && is_array($body['result']['pk'])
            ? $this->pickColumns($body['result']['pk'], $keyColumns)
            : $targetPk;

        return $this->fetchAppliedRow($table, $newPk) ?? array_merge($original, $data);
    }

    /** 從 $row 取出 $columns 指定的欄位（缺欄跳過）。 */
    protected function pickColumns(array $row, array $columns): array {
        $out = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $out[$column] = $row[$column];
            }
        }

        return $out;
    }

    /** $data 相對 $original 有差異（stringwise）的欄位；作為 direct update 的 changes。 */
    protected function diffChangedColumns(array $original, array $data): array {
        $changes = [];
        foreach ($data as $column => $value) {
            if (!array_key_exists($column, $original) || (string) $original[$column] !== (string) $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    protected function applyKinshipProposal(
        Operation $operation,
        array $data,
        array $original,
        array $auxiliaryPayload
    ): array {
        $personId = (int) ($operation->c_personid ?? $data['c_personid'] ?? $original['c_personid'] ?? 0);
        $requestPayload = array_merge($data, $auxiliaryPayload);
        $request = Request::create('/', 'POST', $requestPayload);

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            // #82：核准 CREATE 啟用鏡像衝突/疑似偵測（對齊 v2 direct create）——對面分歧/碼漂移則拋例外中止核准，不盲插衝突鏡像。
            return $this->biogMainRepository->kinshipStoreById($request, $personId, true);
        }

        if (empty($original)) {
            throw new \RuntimeException('缺少原始資料，無法更新。');
        }

        $result = $this->biogMainRepository->kinshipUpdateById(
            $request,
            $personId,
            $this->buildLegacyKinshipId($original),
            true // #77：核准時啟用鏡像衝突/疑似偵測——對面鏡像已分歧/碼漂移則拋例外中止核准（不靜默覆寫）
        );

        $mirrorStatus = (int) ($result['err'] ?? 1);
        unset($result['err']);

        if ($mirrorStatus === 0) {
            throw new \RuntimeException('對應的親屬資料更新失敗，請從對應的親屬人物修改。');
        }

        if ($mirrorStatus > 1) {
            throw new \RuntimeException('對應的親屬資料有多筆重複，請從對應的親屬人物修改。');
        }

        return $result;
    }

    protected function applyAssocProposal(
        Operation $operation,
        array $data,
        array $original,
        array $auxiliaryPayload
    ): array {
        $personId = (int) ($operation->c_personid ?? $data['c_personid'] ?? $original['c_personid'] ?? 0);
        $request = Request::create('/', 'POST', array_merge($data, $auxiliaryPayload));

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            // #82：核准 CREATE 啟用鏡像衝突/疑似偵測（對齊 v2 direct create）。
            $result = $this->biogMainRepository->assocStoreById($request, $personId, true);

            return $this->fetchAppliedRow('ASSOC_DATA', [
                'c_personid' => $result['c_personid'] ?? $personId,
                'c_assoc_code' => $result['c_assoc_code'] ?? null,
                'c_assoc_id' => $result['c_assoc_id'] ?? null,
                'c_kin_code' => $result['c_kin_code'] ?? null,
                'c_kin_id' => $result['c_kin_id'] ?? null,
                'c_assoc_kin_code' => $result['c_assoc_kin_code'] ?? null,
                'c_assoc_kin_id' => $result['c_assoc_kin_id'] ?? null,
                'c_text_title' => $result['c_text_title'] ?? '',
                'c_assoc_first_year' => $result['c_assoc_first_year'] ?? '-9999',
            ]) ?? $result;
        }

        if (empty($original)) {
            throw new \RuntimeException('缺少原始資料，無法更新。');
        }

        $result = $this->biogMainRepository->assocUpdateById(
            $request,
            $this->buildLegacyAssocId($original),
            $personId,
            true // #77：核准時啟用鏡像衝突/疑似偵測——對面鏡像已分歧/碼漂移則拋例外中止核准（不靜默覆寫）
        );

        if ($result === []) {
            throw new \RuntimeException('資料不存在或已被刪除，無法更新。');
        }

        return $this->fetchAppliedRow('ASSOC_DATA', [
            'c_personid' => $personId,
            'c_assoc_code' => $result['c_assoc_code'] ?? $original['c_assoc_code'] ?? null,
            'c_assoc_id' => $result['c_assoc_id'] ?? $original['c_assoc_id'] ?? null,
            'c_kin_code' => $result['c_kin_code'] ?? $original['c_kin_code'] ?? null,
            'c_kin_id' => $result['c_kin_id'] ?? $original['c_kin_id'] ?? null,
            'c_assoc_kin_code' => $result['c_assoc_kin_code'] ?? $original['c_assoc_kin_code'] ?? null,
            'c_assoc_kin_id' => $result['c_assoc_kin_id'] ?? $original['c_assoc_kin_id'] ?? null,
            'c_text_title' => $result['c_text_title'] ?? $original['c_text_title'] ?? '',
            'c_assoc_first_year' => $result['c_assoc_first_year'] ?? $original['c_assoc_first_year'] ?? '-9999',
        ]) ?? array_merge($original, $result);
    }

    protected function applyOfficeProposal(
        Operation $operation,
        array $data,
        array $original,
        array $auxiliaryPayload
    ): array {
        $personId = (int) ($operation->c_personid ?? $data['c_personid'] ?? $original['c_personid'] ?? 0);

        // 更新提案：officeUpdateById() 需要表單隱藏欄位 _id, _postingid, _officeid 來定位原始記錄。
        // 早期提案可能未將這些欄位存入 __proposal_aux，需從 $original 補齊。
        if ((int) $operation->op_type !== Operation::TYPE_PROPOSAL_CREATE) {
            if (!isset($auxiliaryPayload['_id'])) {
                $auxiliaryPayload['_id'] = $personId;
            }
            if (!isset($auxiliaryPayload['_postingid'])) {
                $auxiliaryPayload['_postingid'] = $original['c_posting_id'] ?? $data['c_posting_id'] ?? null;
            }
            if (!isset($auxiliaryPayload['_officeid'])) {
                $auxiliaryPayload['_officeid'] = $original['c_office_id'] ?? null;
            }
        }

        $request = Request::create('/', 'POST', array_merge($data, $auxiliaryPayload));

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            $resourceId = $this->biogMainRepository->officeStoreById($request, $personId);
        } else {
            if (empty($original)) {
                throw new \RuntimeException('缺少原始資料，無法更新。');
            }

            $result = $this->biogMainRepository->officeUpdateById(
                $request,
                $this->buildLegacyOfficeId($original),
                $personId
            );
            $resourceId = is_array($result) ? ($result['id'] ?? null) : $result;
        }

        if (!is_string($resourceId) || $resourceId === '') {
            throw new \RuntimeException('官名提案套用後無法取得主鍵。');
        }

        $pk = CompositePrimaryKey::parseStoredResourceId($resourceId, 'POSTED_TO_OFFICE_DATA');
        if ($pk === null) {
            throw new \RuntimeException('官名提案套用後無法解析主鍵。');
        }

        $row = $this->fetchAppliedRow('POSTED_TO_OFFICE_DATA', $pk);
        if ($row === null) {
            throw new \RuntimeException('官名提案套用後讀取資料失敗。');
        }

        return $row;
    }

    /**
     * 財產新增提案核准：委派 possessionStoreById 配發 c_possession_record_id（交易內 max+1），
     * 連帶寫主表 POSSESSION_DATA + 副表 POSSESSION_ADDR + operation + audit_log。
     * 地址陣列存於 __proposal_aux['c_addr_id']；usedDirectWorkflow=true 由委派端自行寫 operation/audit，
     * approve() 不再重複寫，避免 operation/audit 重複（比照 applyOfficeProposal）。
     */
    protected function applyPossessionCreateProposal(
        Operation $operation,
        array $data,
        array $auxiliaryPayload
    ): array {
        $personId = (int) ($operation->c_personid ?? $data['c_personid'] ?? 0);

        $addr = $auxiliaryPayload['c_addr_id'] ?? [];
        if (!is_array($addr)) {
            $addr = [$addr];
        }

        $request = Request::create('/', 'POST', array_merge($data, ['c_addr_id' => array_values($addr)]));

        $newId = $this->biogMainRepository->possessionStoreById($request, $personId);

        $row = $this->fetchAppliedRow('POSSESSION_DATA', ['c_possession_record_id' => $newId]);
        if ($row === null) {
            throw new \RuntimeException('財產提案套用後讀取資料失敗。');
        }

        return $row;
    }

    /**
     * 套用財產更新提案：連帶同步 POSSESSION_ADDR（修補先前 update 提案走泛型單表套用、漏掉地址副表的缺口）。
     * aux 帶 c_addr_id（陣列）時 possessionUpdateById 同步副表；未帶則保留既有地址（is_array 守衛）。
     */
    protected function applyPossessionUpdateProposal(
        Operation $operation,
        array $data,
        array $original,
        array $auxiliaryPayload
    ): array {
        $personId = (int) ($operation->c_personid ?? $data['c_personid'] ?? $original['c_personid'] ?? 0);
        $recordId = (int) ($data['c_possession_record_id'] ?? $original['c_possession_record_id'] ?? 0);

        $request = Request::create('/', 'POST', array_merge($data, $auxiliaryPayload));
        $this->biogMainRepository->possessionUpdateById($request, $personId, $recordId);

        $row = $this->fetchAppliedRow('POSSESSION_DATA', ['c_possession_record_id' => $recordId]);
        if ($row === null) {
            throw new \RuntimeException('財產提案套用後讀取資料失敗。');
        }

        return $row;
    }

    protected function applyEventProposal(
        Operation $operation,
        array $data,
        array $original,
        array $auxiliaryPayload
    ): array {
        $personId = (int) ($operation->c_personid ?? $data['c_personid'] ?? $original['c_personid'] ?? 0);
        $request = Request::create('/', 'POST', array_merge($data, $auxiliaryPayload));

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            $result = $this->biogMainRepository->eventStoreById($request, $personId);
        } else {
            if (empty($original)) {
                throw new \RuntimeException('缺少原始資料，無法更新。');
            }

            $result = $this->biogMainRepository->eventUpdateById(
                $request,
                $personId,
                $this->buildLegacyEventId($original)
            );
        }

        return $this->fetchAppliedRow('EVENTS_DATA', [
            'c_personid' => $personId,
            'c_sequence' => $result['c_sequence'] ?? $original['c_sequence'] ?? null,
            'c_event_code' => $result['c_event_code'] ?? $original['c_event_code'] ?? null,
        ]) ?? array_merge($original, $result);
    }

    protected function buildLegacyKinshipId(array $original): string {
        foreach (['c_personid', 'c_kin_id', 'c_kin_code'] as $column) {
            if (!array_key_exists($column, $original)) {
                throw new \RuntimeException("缺少 {$column}，無法更新親屬提案。");
            }
        }

        return implode('-', [
            $original['c_personid'],
            $original['c_kin_id'],
            $original['c_kin_code'],
        ]);
    }

    protected function buildLegacyAssocId(array $original): string {
        $required = ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id'];
        foreach ($required as $column) {
            if (!array_key_exists($column, $original)) {
                throw new \RuntimeException("缺少 {$column}，無法更新社會關係提案。");
            }
        }

        $assocFirstYear = (string) ($original['c_assoc_first_year'] ?? '-9999');

        return implode('-', [
            $original['c_personid'],
            $original['c_assoc_code'],
            $original['c_assoc_id'],
            $original['c_kin_code'],
            $original['c_kin_id'],
            $original['c_assoc_kin_code'],
            $original['c_assoc_kin_id'],
            $this->biogMainRepository->unionPKDef($original['c_text_title'] ?? ''),
            str_replace('-', '(minus)', $assocFirstYear),
        ]);
    }

    protected function buildLegacyOfficeId(array $original): string {
        foreach (['c_office_id', 'c_posting_id'] as $column) {
            if (!array_key_exists($column, $original)) {
                throw new \RuntimeException("缺少 {$column}，無法更新官名提案。");
            }
        }

        return $original['c_office_id'].'-'.$original['c_posting_id'];
    }

    protected function buildLegacyEventId(array $original): string {
        foreach (['c_sequence', 'c_event_code'] as $column) {
            if (!array_key_exists($column, $original)) {
                throw new \RuntimeException("缺少 {$column}，無法更新事件提案。");
            }
        }

        return $original['c_sequence'].'-'.$original['c_event_code'];
    }

    protected function fetchAppliedRow(string $table, array $conditions): ?array {
        $conditions = array_filter($conditions, static fn ($value) => $value !== null);
        if ($conditions === []) {
            return null;
        }

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        $row = $query->first();

        return $row ? $this->convertRowToArray($row) : null;
    }

    protected function applyCreateProposal(string $table, array $data, array $keyColumns): array {
        $data = $this->assignAutoKeyIfNeeded($table, $keyColumns, $data);
        $data = $this->enforceAuditFieldsForCreate($table, $data);

        if (!$this->hasKeyValues($keyColumns, $data, $this->optionalKeyColumnsForTable($table))) {
            throw new \RuntimeException('缺少主鍵欄位，無法新增資料。');
        }

        $existing = DB::table($table)->where($this->buildKeyConditions($keyColumns, $data))->first();
        if ($existing) {
            throw new \RuntimeException('資料已存在，無法再次新增。');
        }

        // 檢查是否有對應的模型類，如果有則使用 Eloquent 模型以觸發觀察者
        if (isset($this->tableModelMap[$table])) {
            $modelClass = $this->tableModelMap[$table];
            $model = $modelClass::create($data);

            return $this->convertRowToArray($model);
        }

        // 如果沒有對應的模型，則使用原有的 DB::table() 方法
        DB::table($table)->insert($data);

        $row = DB::table($table)->where($this->buildKeyConditions($keyColumns, $data))->first();
        if (!$row) {
            throw new \RuntimeException('新增後讀取資料失敗。');
        }

        // 特殊處理：ALTNAME_DATA 需要手動調用索引服務
        if ($table === 'ALTNAME_DATA') {
            $this->indexAltnameAfterCreate($data);
        }

        return $this->convertRowToArray($row);
    }

    /**
     * 「不可清空」守衛：提案 payload 把 NO_CLEAR_COLUMNS_ON_APPLY 所列欄位寫成空白、
     * 而該列當下（$currentRow）有值時，拒絕核准。原本即為空的欄位不受影響（可維持空）。
     */
    protected function assertNoClearColumns(string $table, array $data, array $currentRow): void {
        foreach (self::NO_CLEAR_COLUMNS_ON_APPLY[$table] ?? [] as $column => $label) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $proposed = trim((string) ($data[$column] ?? ''));
            $current = trim((string) ($currentRow[$column] ?? ''));
            if ($proposed === '' && $current !== '') {
                throw new \RuntimeException("此提案會清空既有的「{$label}」，無法核准。");
            }
        }
    }

    protected function applyUpdateProposal(string $table, array $data, array $keyColumns, array $original): array {
        if (empty($original)) {
            throw new \RuntimeException('缺少原始資料，無法更新。');
        }

        $data = $this->enforceAuditFieldsForUpdate($table, $data, $original);
        $conditions = $this->buildKeyConditions($keyColumns, $original);

        // 檢查是否有對應的模型類，如果有則使用 Eloquent 模型以觸發觀察者
        if (isset($this->tableModelMap[$table])) {
            $modelClass = $this->tableModelMap[$table];
            $model = $modelClass::where($conditions)->first();
            if (!$model) {
                throw new \RuntimeException('資料不存在或已被刪除，無法更新。');
            }

            $this->assertNoClearColumns($table, $data, $model->toArray());

            foreach ($keyColumns as $column) {
                if (!array_key_exists($column, $original)) {
                    continue;
                }
                if (array_key_exists($column, $data) && !$this->keyValuesMatch($data[$column], $original[$column])) {
                    throw new \RuntimeException('提案不可修改主鍵欄位。');
                }
            }

            $updatePayload = array_diff_key($data, array_flip($keyColumns));
            if (!empty($updatePayload)) {
                // 使用 update() 方法，這會觸發 Observer 並強制更新
                $model->update($updatePayload);
                // 重新讀取以確保獲取最新數據
                $model->refresh();
            }

            return $this->convertRowToArray($model);
        }

        // 如果沒有對應的模型，則使用原有的 DB::table() 方法
        $current = DB::table($table)->where($conditions)->first();
        if (!$current) {
            throw new \RuntimeException('資料不存在或已被刪除，無法更新。');
        }

        $this->assertNoClearColumns($table, $data, (array) $current);

        $updatePayload = $this->buildUpdatePayload($data, $keyColumns, $original);

        // 改鍵碰撞偵測（#117，對齊 direct 路徑）：updatePayload 含任一主鍵欄即為改鍵；若變更後的新主鍵
        // 已被另一列佔用，擋下並回明確錯誤，避免 UPDATE 撞 DB 複合主鍵約束冒成未處理的 500。
        $reKeyedColumns = array_intersect($keyColumns, array_keys($updatePayload));
        if (!empty($reKeyedColumns)) {
            $newKeyRow = $this->resolveReadbackKeyRow($keyColumns, $original, $updatePayload);
            if (DB::table($table)->where($this->buildKeyConditions($keyColumns, $newKeyRow))->exists()) {
                throw new \RuntimeException('變更後的主鍵與現有記錄重複，無法核准此改鍵提案。');
            }
        }

        if (!empty($updatePayload)) {
            DB::table($table)->where($conditions)->update($updatePayload);
        }

        $readKeyRow = $this->resolveReadbackKeyRow($keyColumns, $original, $updatePayload);
        $readConditions = $this->buildKeyConditions($keyColumns, $readKeyRow);
        $row = DB::table($table)->where($readConditions)->first();
        if (!$row) {
            throw new \RuntimeException('更新後讀取資料失敗。');
        }

        // 特殊處理：ALTNAME_DATA 需要手動調用索引服務
        if ($table === 'ALTNAME_DATA') {
            $this->indexAltnameAfterUpdate($original, $data);
        }

        return $this->convertRowToArray($row);
    }

    /**
     * 套用刪除提案：以 __key_columns + original 的 PK 值定位目標列並刪除。
     * offices(POSTED_TO_OFFICE_DATA)/possession(POSSESSION_DATA) 連帶刪除副表。
     * 回傳被刪除前的原始列（供 logFinalOperation/audit 使用）；目標列不存在則回傳空陣列。
     */
    protected function applyDeleteProposal(string $table, array $keyColumns, array $original): array {
        if (empty($original)) {
            throw new \RuntimeException('缺少原始資料，無法刪除。');
        }
        if (empty($keyColumns)) {
            throw new \RuntimeException('提案缺少主鍵資訊，無法刪除。');
        }

        $conditions = $this->buildKeyConditions($keyColumns, $original);

        $row = DB::table($table)->where($conditions)->first();
        if (!$row) {
            // 目標列已不存在：視為冪等成功，回傳原始資料以利稽核紀錄
            return $original;
        }

        $deletedRow = $this->convertRowToArray($row);

        // 連帶刪除副表
        if ($table === 'POSTED_TO_OFFICE_DATA') {
            $this->deleteOfficeAuxiliaryTables($deletedRow);
        } elseif ($table === 'POSSESSION_DATA') {
            $this->deletePossessionAuxiliaryTables($deletedRow);
        }

        DB::table($table)->where($conditions)->delete();

        // 注意：社會關係反向鏡像刪除移至 approve() 於 logFinalOperation 之後執行，
        // 以便鏡像 audit 掛 final delete operation id（見 approve()）。

        if ($table === 'ALTNAME_DATA') {
            $this->indexAltnameAfterDelete($deletedRow);
        }

        return $deletedRow;
    }

    /**
     * POSTED_TO_OFFICE_DATA 核准刪除時連帶刪除 POSTED_TO_ADDR_DATA 與 POSTING_DATA。
     */
    protected function deleteOfficeAuxiliaryTables(array $row): void {
        $officeId = $row['c_office_id'] ?? null;
        $postingId = $row['c_posting_id'] ?? null;
        $personId = $row['c_personid'] ?? null;

        if ($officeId === null || $postingId === null) {
            return;
        }

        if (Schema::hasTable('POSTED_TO_ADDR_DATA')) {
            $addrQuery = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_office_id', $officeId)
                ->where('c_posting_id', $postingId);
            if ($personId !== null) {
                $addrQuery->where('c_personid', $personId);
            }
            $addrQuery->delete();
        }

        if (Schema::hasTable('POSTING_DATA')) {
            DB::table('POSTING_DATA')->where('c_posting_id', $postingId)->delete();
        }
    }

    /**
     * POSSESSION_DATA 核准刪除時連帶刪除 POSSESSION_ADDR。
     */
    protected function deletePossessionAuxiliaryTables(array $row): void {
        $recordId = $row['c_possession_record_id'] ?? null;
        if ($recordId === null) {
            return;
        }

        if (Schema::hasTable('POSSESSION_ADDR')) {
            DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $recordId)->delete();
        }
    }

    /**
     * ALTNAME_DATA 核准刪除後移除全文索引。
     */
    protected function indexAltnameAfterDelete(array $row): void {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $name = $row['c_alt_name_chn'] ?? null;
        $personId = $row['c_personid'] ?? null;
        if (empty($name) || $personId === null) {
            return;
        }

        $this->nameSearchIndexService->removeAltname(
            $personId,
            $row['c_alt_name_type_code'] ?? null,
            $name
        );
    }

    protected function buildUpdatePayload(array $data, array $keyColumns, array $original): array {
        $updatePayload = array_diff_key($data, array_flip($keyColumns));

        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $original) || !array_key_exists($column, $data)) {
                continue;
            }

            if (!$this->keyValuesMatch($data[$column], $original[$column])) {
                $updatePayload[$column] = $data[$column];
            }
        }

        return $updatePayload;
    }

    protected function resolveReadbackKeyRow(array $keyColumns, array $original, array $updatePayload): array {
        $row = $original;

        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $updatePayload)) {
                $row[$column] = $updatePayload[$column];
            }
        }

        return $row;
    }

    protected function keyValuesMatch($left, $right): bool {
        if ($left === $right) {
            return true;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (string) $left == (string) $right;
        }

        return trim((string) $left) === trim((string) $right);
    }

    protected function hasKeyValues(array $keyColumns, array $row, array $optionalColumns = []): bool {
        foreach ($keyColumns as $column) {
            if (in_array($column, $optionalColumns, true)) {
                continue;
            }

            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                return false;
            }
        }

        return true;
    }

    protected function optionalKeyColumnsForTable(string $table): array {
        return $table === 'BIOG_SOURCE_DATA' ? ['c_pages'] : [];
    }

    protected function normalizeRowForTable(string $table, array $row): array {
        if ($table !== 'BIOG_SOURCE_DATA') {
            return $row;
        }

        $row['c_pages'] = (string) ($row['c_pages'] ?? '');

        if (array_key_exists('c_textid', $row) && $row['c_textid'] !== null && $row['c_textid'] !== '') {
            $row['c_textid'] = (int) $row['c_textid'] === -999 ? 0 : (int) $row['c_textid'];
        }

        return $row;
    }

    protected function enforceAuditFieldsForCreate(string $table, array $data): array {
        $columns = $this->getTableColumnMap($table);
        if ($columns === null) {
            return $data;
        }

        $now = Carbon::now();

        if (isset($columns['c_created_by']) && (!array_key_exists('c_created_by', $data) || trim((string) ($data['c_created_by'] ?? '')) === '') && Auth::check()) {
            $data['c_created_by'] = Auth::user()->name ?? Auth::id();
        }

        if (isset($columns['c_created_date']) && (!array_key_exists('c_created_date', $data) || $data['c_created_date'] === null || trim((string) $data['c_created_date']) === '')) {
            $data['c_created_date'] = $now;
        }

        return $data;
    }

    protected function enforceAuditFieldsForUpdate(string $table, array $data, array $original): array {
        $columns = $this->getTableColumnMap($table);
        if ($columns === null) {
            return $data;
        }

        $now = Carbon::now();

        if (isset($columns['c_modified_by']) && (!array_key_exists('c_modified_by', $data) || trim((string) ($data['c_modified_by'] ?? '')) === '') && Auth::check()) {
            $data['c_modified_by'] = Auth::user()->name ?? Auth::id();
        }

        if (isset($columns['c_modified_date']) && (!array_key_exists('c_modified_date', $data) || $data['c_modified_date'] === null || trim((string) $data['c_modified_date']) === '')) {
            $data['c_modified_date'] = $now;
        }

        foreach (['c_created_by', 'c_created_date'] as $field) {
            if (isset($columns[$field]) && array_key_exists($field, $original) && !array_key_exists($field, $data)) {
                $data[$field] = $original[$field];
            }
        }

        return $data;
    }

    protected function buildKeyConditions(array $keyColumns, array $row): array {
        $conditions = [];
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new \RuntimeException("缺少主鍵欄位 {$column}");
            }
            $conditions[$column] = $row[$column];
        }

        return $conditions;
    }

    protected function convertRowToArray($row): array {
        if (is_array($row)) {
            return $row;
        }
        if ($row instanceof Model) {
            return $row->toArray();
        }
        if ($row instanceof \ArrayAccess) {
            return (array) $row;
        }

        return json_decode(json_encode($row), true) ?: [];
    }

    protected function writeAuditLogForApproval(Operation $proposal, array $appliedRow, array $original, int $proposalType): void {
        if (!DB::getSchemaBuilder()->hasTable('audit_log')) {
            return;
        }

        $payload = json_decode($proposal->resource_data, true) ?: [];
        $keyColumns = $payload['__key_columns'] ?? [];
        if (empty($keyColumns)) {
            return;
        }

        $rowPk = [];
        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $appliedRow)) {
                $rowPk[$column] = $appliedRow[$column];
            }
        }

        if (empty($rowPk)) {
            return;
        }

        if ($proposalType === Operation::TYPE_PROPOSAL_DELETE) {
            $operation = 'DELETE';
            $oldData = $original ?: $appliedRow;
            $newData = null;
        } elseif ($proposalType === Operation::TYPE_PROPOSAL_CREATE) {
            $operation = 'INSERT';
            $oldData = null;
            $newData = $appliedRow;
        } else {
            $operation = 'UPDATE';
            $oldData = $original;
            $newData = $appliedRow;
        }

        (new AuditLogService())->write(
            $proposal->resource,
            $operation,
            $rowPk,
            $oldData,
            $newData,
            'user',
            (string) Auth::id(),
            (string) $proposal->id
        );
    }

    protected function logFinalOperation(Operation $proposal, array $appliedRow, array $original, int $proposalType): ?Operation {
        $proposalData = json_decode($proposal->resource_data, true) ?? [];
        $keyColumns = $proposalData['__key_columns'] ?? [];

        if ($proposalType === Operation::TYPE_PROPOSAL_DELETE) {
            $type = Operation::TYPE_DELETE;
        } elseif ($proposalType === Operation::TYPE_PROPOSAL_CREATE) {
            $type = Operation::TYPE_CREATE;
        } else {
            $type = Operation::TYPE_UPDATE;
        }

        // delete 後 appliedRow 為被刪列（= original），以原始 PK 建立 resource_id
        $resourceIdRow = $type === Operation::TYPE_DELETE ? $original : $appliedRow;
        $resourceId = $this->buildCompositeId($keyColumns, $resourceIdRow);

        // 對於 BiogMain 相關提案，使用實際的 c_personid；對於 Codes 提案使用 0
        $personId = $proposal->c_personid ?? 0;

        if ($type === Operation::TYPE_DELETE) {
            return $this->operationRepository->store(
                Auth::id(),
                $personId,
                Operation::TYPE_DELETE,
                $proposal->resource,
                $resourceId,
                $original,
                $original
            );
        }

        return $this->operationRepository->store(
            Auth::id(),
            $personId,
            $type,
            $proposal->resource,
            $resourceId,
            $appliedRow,
            $type === Operation::TYPE_UPDATE ? $original : []
        );
    }

    protected function updateProposalStatus(
        Operation $proposal,
        string $status,
        string $comment = null,
        ?array $appliedRow = null,
        array $keyColumns = [],
        bool $updateResourceId = false
    ): void {
        $payload = json_decode($proposal->resource_data, true) ?: [];

        $payload['__review_status'] = $status;
        $payload['__reviewed_by'] = Auth::user()->name ?? Auth::id();
        $payload['__reviewed_by_id'] = Auth::id();
        $payload['__reviewed_at'] = Carbon::now()->format('Y-m-d H:i:s');
        if ($comment !== null && $comment !== '') {
            $payload['__review_comment'] = $comment;
        }

        if ($status === 'approved' && $appliedRow !== null && count($keyColumns) === 1) {
            $keyColumn = $keyColumns[0];
            if (array_key_exists($keyColumn, $appliedRow)) {
                $payload[$keyColumn] = $appliedRow[$keyColumn];
                $payload['__proposal_meta'] = is_array($payload['__proposal_meta'] ?? null)
                    ? $payload['__proposal_meta']
                    : [];
                $payload['__proposal_meta']['approved_resource_id'] = (string) $appliedRow[$keyColumn];
            }
        }

        $proposal->resource_data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($status === 'approved' && $appliedRow !== null && $updateResourceId && !empty($keyColumns)) {
            $proposal->resource_id = $this->buildCompositeId($keyColumns, $appliedRow);
        }
        $proposal->save();
    }

    protected function buildCompositeId(array $keyColumns, array $row): string {
        if (empty($keyColumns)) {
            return '';
        }

        $parts = [];
        foreach ($keyColumns as $column) {
            $parts[] = isset($row[$column]) ? (string) $row[$column] : '';
        }

        return implode('_._', $parts);
    }

    protected function assignAutoKeyIfNeeded(string $table, array $keyColumns, array $data): array {
        if (count($keyColumns) !== 1) {
            return $data;
        }

        $keyColumn = $keyColumns[0];
        if (!array_key_exists($keyColumn, $data)) {
            return $data;
        }

        $currentValue = $data[$keyColumn];
        if ($currentValue === null || $currentValue === '') {
            return $data;
        }

        if (!is_numeric($currentValue)) {
            return $data;
        }

        $existing = DB::table($table)->where($keyColumn, $currentValue)->first();
        if (!$existing) {
            return $data;
        }

        $nextValue = $this->guessNextNumericKeyValue($table, $keyColumn);
        if ($nextValue === null) {
            return $data;
        }

        $data[$keyColumn] = $nextValue;

        return $data;
    }

    protected function guessNextNumericKeyValue(string $table, string $column): ?string {
        try {
            $max = DB::table($table)->max($column);
        } catch (\Throwable $e) {
            return null;
        }

        if ($max === null) {
            return '1';
        }

        if (is_numeric($max)) {
            return (string) ((int) $max + 1);
        }

        return null;
    }

    /**
     * ALTNAME_DATA 新增後手動調用索引服務
     *
     * @param array $data
     * @return void
     */
    protected function indexAltnameAfterCreate(array $data): void {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        if (empty($data['c_alt_name_chn']) || !isset($data['c_personid'])) {
            return;
        }

        $this->nameSearchIndexService->indexAltname(
            $data['c_personid'],
            $data['c_alt_name_type_code'],
            $data['c_alt_name_chn']
        );
    }

    /**
     * ALTNAME_DATA 更新後手動調用索引服務
     *
     * @param array $original
     * @param array $updated
     * @return void
     */
    protected function indexAltnameAfterUpdate(array $original, array $updated): void {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $nameChanged = ($original['c_alt_name_chn'] ?? '') !== ($updated['c_alt_name_chn'] ?? '');
        $typeChanged = ($original['c_alt_name_type_code'] ?? null) !== ($updated['c_alt_name_type_code'] ?? null);

        if ($nameChanged || $typeChanged) {
            // 刪除舊索引
            if (!empty($original['c_alt_name_chn'])) {
                $this->nameSearchIndexService->removeAltname(
                    $original['c_personid'],
                    $original['c_alt_name_type_code'],
                    $original['c_alt_name_chn']
                );
            }

            // 創建新索引
            if (!empty($updated['c_alt_name_chn'])) {
                $this->nameSearchIndexService->indexAltname(
                    $updated['c_personid'] ?? $original['c_personid'],
                    $updated['c_alt_name_type_code'],
                    $updated['c_alt_name_chn']
                );
            }
        }
    }
}
