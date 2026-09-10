<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\AuditActor;
use App\Support\CodeTableFieldValidator;
use App\Support\CompositePrimaryKey;
use App\Support\SelfReferencingTreeGuard;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Code／lookup 表受審計更新 handler 的共用基底。
 *
 * 整合交易、`audit_log`、`operations`、複合主鍵驗證、欄位白名單、變更偵測與 direct/proposal 兩模式；
 * 具體每表 handler 只需實作 tableName／resourceName／resourceAliases／displayName／keyColumns／allowedFields
 * 少量方法（見 CODE_TABLE_MUTATION_API_PLAN.md §4）。
 *
 * code 表為全域代碼、非人物子資源，故 `operations.c_personid` 一律設為 0（不受呼叫端 person_id 控制；
 * 呼叫端仍須依 MutationController 契約傳 person_id，通常為 0）。
 */
abstract class AbstractCodeTableMutationHandler extends AbstractMutationHandler {
    use \App\Services\Mutations\Concerns\AppliesVariantReplacement;
    use \App\Services\Mutations\Concerns\GuardsCharVariantMapWrites;
    use \App\Services\Mutations\Concerns\HandlesCodeTableWrites;
    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
    }

    /** 實際資料表名（＝ audit_log／operations 的 table 值、CompositePrimaryKey::SCHEMAS 鍵）。 */
    abstract protected function tableName(): string;

    /** 回應與提案 meta 使用的正規 resource 名（須包含於 resourceAliases()）。 */
    abstract protected function resourceName(): string;

    /** supports() 接受的 resource 別名清單。 */
    abstract protected function resourceAliases(): array;

    /** 提案 meta 顯示名（如「年號」）。 */
    abstract protected function displayName(): string;

    /** 主鍵欄位（單鍵或複合鍵，順序需與 CompositePrimaryKey::SCHEMAS 一致）。 */
    abstract protected function keyColumns(): array;

    /** 允許更新的欄位白名單。 */
    abstract protected function allowedFields(): array;

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, $this->resourceAliases(), true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        $table = $this->tableName();

        try {
            CompositePrimaryKey::validateOrFail($targetPk, $table);
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 防呆：keyColumns() 必須與 CompositePrimaryKey::SCHEMAS 登錄的主鍵完全一致（順序＋欄名）。
        // validateOrFail 依 SCHEMAS 驗證「全鍵存在且非 null」；若子類 keyColumns() 為 SCHEMAS 的真子集，
        // 驗證仍會通過、但 whereByPk 只用部分鍵→UPDATE 可能命中多列。此處硬擋子類設定錯誤（500）。
        $schemaKeys = CompositePrimaryKey::getSchema($table);
        if ($schemaKeys === null || $this->keyColumns() !== $schemaKeys) {
            return $this->errorResponse($table . ' 主鍵宣告與登錄不一致（handler 設定錯誤）', 500, ['pk' => ['schema_mismatch']]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        // 型別讀不到就不要寫：isTextColumn() 在讀不到時回 false，於是文本主鍵不會被轉成
        // 字串，MariaDB 會把 varchar 欄轉成數字比較、改到別的列並記下一個沒有任何列擁有
        // 的鍵（見下方註解）。而 columnTypes() 會把「讀不到」快取起來，一次瞬時失敗會污染
        // 整個 request。寧可 500 也不要寫壞一列。
        if ($this->schemaUnavailable($table)) {
            return $this->errorResponse($table . ' 讀不到欄位型別，為避免寫錯主鍵已中止', 500, ['pk' => ['schema_unavailable']]);
        }

        // 依 keyColumns 取出主鍵（已驗證＝SCHEMAS、全鍵存在且非 null）。
        //
        // **文本主鍵必須轉成字串**，而且理由不是潔癖：MariaDB 比較 varchar 與數字時會把
        // 欄位轉成數字，所以 `where('c_office_type_node_id', 601)` 會命中 `'0601'` 那一列
        // （已對真實庫實測）。於是 UPDATE 改到了對的列，但 resource_id、audit_log.row_pk
        // 與回應的 result.pk 全部記成 `601`——一個沒有任何列擁有的鍵：operations 頁解不出
        // 現況、呼叫端照回應的鍵做後續操作一律 404。若哪天出現數值相等的兩個 id
        // （`'0601'` 與 `'601'`），同一個條件還會一次更新兩列而只記錄其中一個鍵。
        // SQLite 不做這種轉型，所以測試環境永遠看不到——只能靠這裡擋。
        $pk = [];
        foreach ($this->keyColumns() as $col) {
            $value = $targetPk[$col];
            if ($this->isTextColumn($table, $col)) {
                if (!is_string($value) && !is_int($value)) {
                    return $this->errorResponse('主鍵格式不正確', 422, ['target.pk.' . $col => ['string']]);
                }
                $value = (string) $value;
                // 長度也要驗，與 create 端同一條規則。少了這條，超長的鍵會一路走到
                // findByPk 然後回 404「記錄不存在」——技術上沒錯，但把「你送的鍵不合法」
                // 講成「這筆資料不存在」會讓呼叫端去找不存在的資料問題。
                $maxLength = $this->columnTypes($table)[strtolower($col)]['max_length'] ?? null;
                if ($maxLength !== null && mb_strlen($value) > $maxLength) {
                    return $this->errorResponse('主鍵格式不正確', 422, ['target.pk.' . $col => ['max:' . $maxLength]]);
                }
            }
            $pk[$col] = $value;
        }

        $original = $this->findByPk($pk);
        if (!$original) {
            return $this->errorResponse($table . ' 記錄不存在', 404);
        }

        $allowed = $this->allowedFields();

        // 拒絕白名單外的欄位
        $disallowedFields = array_diff(array_keys($changes), $allowed);
        if (!empty($disallowedFields)) {
            return $this->errorResponse('包含不允許更新的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowedFields)],
            ]);
        }

        $updateData = array_intersect_key($changes, array_flip($allowed));
        if (empty($updateData)) {
            return $this->errorResponse('changes 至少需包含一個可更新欄位', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        // 型別正規化（與 create 端同一套；只在單邊做會製造「新增進得去、改回同一個值
        // 卻 422」的不對稱）。必須在校驗與變更偵測之前：正規化後才是真正要落庫的值。
        $updateData = CodeTableFieldValidator::normalize($updateData, $this->fieldTypeSpec());

        // 驗證欄位值
        $validationErrors = $this->validateFields($updateData);
        if (!empty($validationErrors)) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        // 自參照樹：改上層節點可能成環（A→B、B→A 各自都滿足外鍵，資料庫擋不住），
        // 而成環的樹沒有任何消費者能安全走訪。必須在落庫前擋（判定與其餘五條寫入路徑
        // 共用 SelfReferencingTreeGuard）。
        $treeParentColumn = $this->treeParentColumn();
        if ($treeParentColumn !== null && array_key_exists($treeParentColumn, $updateData) && count($this->keyColumns()) === 1) {
            $keyColumn = $this->keyColumns()[0];
            $currentParent = $original->{$treeParentColumn} ?? null;
            // 上層沒有真的改變時不驗：根節點的 c_parent_id 本來就等於自己（那是「根」的
            // 表示法），把整列原樣送回來存檔不該被守衛擋下。
            if ((string) $updateData[$treeParentColumn] !== (string) $currentParent) {
                $cycleError = $this->findTreeCycle($table, $keyColumn, $treeParentColumn, $pk[$keyColumn], $updateData[$treeParentColumn]);
                if ($cycleError !== null) {
                    return $this->errorResponse($cycleError, 422, ['changes' => ['tree_cycle']]);
                }
            }
        }

        // 保存前處理（如 §D-6 Tier 1 拼音 v→ü 歸一化）；於變更偵測前，確保冪等（已是 ü→不觸發更新）。
        $updateData = $this->preprocessUpdateData($updateData);

        // 異體字落地替換（型別驅動）。同樣必須在**變更偵測之前**：使用者把變體形改成
        // 參考形時，比較的雙方要是替換後的值，否則「送變體形、現值已是參考形」會被判成
        // 有變更而寫一次無意義的 UPDATE，反之也可能漏掉真正的變更。
        //
        // 這條掛鉤**今天就是活的**：`config/code_table_mutations.php` 多數表只開放拼音／
        // 拉丁欄（對它們替換是恆等映射，因為對照表的鍵都是漢字），但
        // `TEXT_INSTANCE_DATA.c_publisher` 是不帶 `_chn` 後綴的中文欄（見 plan D3 列的
        // 8 個同類欄），送「淸華書局」會落庫「清華書局」。別因為「看起來全是拼音欄」
        // 就把這裡當成 no-op 而移除。
        // char_variant_map 自身在 EXCLUDED_TABLES（替換等於自我吞噬），不受影響。
        $this->resetVariantReplaced();
        $updateData = $this->applyVariantReplacement($updateData);

        // 檢查是否有實際變更
        $originalArray = $this->auditLogService->normalizeRow($original);
        $hasEffectiveChange = false;
        foreach ($updateData as $field => $value) {
            if (($originalArray[$field] ?? null) !== $value) {
                $hasEffectiveChange = true;

                break;
            }
        }
        if (!$hasEffectiveChange) {
            // 使用者送的字被歸一成與現值相同時，422 也要帶 notices，
            // 否則「未偵測到任何修改內容」看起來毫無道理（對齊人物子資源 handler）。
            return $this->withVariantNotices($this->errorResponse('未偵測到任何修改內容', 422, [
                'changes' => ['no_effective_changes'],
            ]));
        }

        $resourceId = CompositePrimaryKey::buildStoredResourceId($pk);
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        // code 表為全域代碼，c_personid 一律設為 0，不受呼叫端控制
        $operationPersonId = 0;

        if ($mode === 'proposal') {
            return $this->handleProposal($operationPersonId, $pk, $resourceId, $updateData, $originalArray, $comment);
        }

        return $this->handleDirect($operationPersonId, $pk, $resourceId, $updateData, $originalArray, $comment);
    }

    protected function handleDirect(int $personId, array $pk, string $resourceId, array $updateData, array $originalArray, string $comment): JsonResponse {
        $table = $this->tableName();
        $operationId = (string) Str::ulid();

        /** @var \App\Models\Operation|null $operation */
        $operation = null;
        $newArray = [];

        // char_variant_map：落庫前驗結構，並排除自己那一列（單邊改欄時要能 merge 舊值）。
        if (($guardError = $this->guardCharVariantMapWrite($table, $updateData, isset($pk['id']) ? (int) $pk['id'] : null)) !== null) {
            return $guardError;
        }

        try {
            DB::transaction(function () use ($table, $pk, $resourceId, $updateData, $originalArray, $comment, $operationId, $personId, &$operation, &$newArray) {
                // 自參照樹：**交易內再驗一次，這次鎖住走訪路徑**。handle() 裡那次是為了
                // 給呼叫端漂亮的 422，但它是「檢查後才寫」——兩個同時進行的
                // `A.parent=B` 與 `B.parent=A` 各自都會看到對方還掛在根上而通過，
                // 然後兩邊都提交、環成立。鎖住路徑上的列才真的收斂掉這個 race。
                $treeParentColumn = $this->treeParentColumn();
                if ($treeParentColumn !== null && array_key_exists($treeParentColumn, $updateData) && count($this->keyColumns()) === 1) {
                    $keyColumn = $this->keyColumns()[0];
                    $lockedCycleError = SelfReferencingTreeGuard::findCycle(
                        $table,
                        $keyColumn,
                        $treeParentColumn,
                        $pk[$keyColumn],
                        $updateData[$treeParentColumn],
                        true,
                    );
                    if ($lockedCycleError !== null) {
                        throw new TreeCycleException($lockedCycleError);
                    }
                }

                $this->whereByPk(DB::table($table), $pk)->update($this->stampModifiedColumns($table, $updateData));

                $updatedRow = $this->findByPk($pk);
                $newArray = $this->auditLogService->normalizeRow($updatedRow);

                $resourceData = array_merge($newArray, ['__operation_id' => $operationId]);
                if ($comment !== '') {
                    $resourceData['__note'] = $comment;
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_UPDATE,
                    $table,
                    $resourceId,
                    $resourceData,
                    $originalArray
                );

                $this->auditLogService->write(
                    $table,
                    'UPDATE',
                    $pk,
                    $originalArray,
                    $newArray,
                    'user',
                    (string) Auth::id(),
                    $operationId
                );
            });
        } catch (TreeCycleException $e) {
            // 交易內的鎖定複查擋下的競態；與 handle() 裡那次同樣回 422 tree_cycle。
            return $this->withVariantNotices($this->errorResponse($e->getMessage(), 422, ['changes' => ['tree_cycle']]));
        } catch (\Illuminate\Database\QueryException $e) {
            // 這三個回應都在落地替換之後，所以都要帶 notices（AGENTS §1.3：成功、409、422
            // 都要掛）——被擋下來時使用者更需要知道自己輸入的字已被正規化。
            //
            // 判定順序不可調換：SQLite 的 UNIQUE／FOREIGN KEY／NOT NULL 共用 errno 19，
            // trait 內以訊息互斥（見 HandlesCodeTableWrites）。
            // 鎖競爭（deadlock／lock wait timeout）：交易已回滾、沒有寫出環，
            // 是可直接重試的暫時性衝突，回 409 而不是 500。自參照樹的鎖定走訪會讓
            // 兩個方向相反的重掛以不同順序取鎖，資料庫因此可能挑一方回滾。
            if ($this->isLockContention($e)) {
                return $this->withVariantNotices($this->errorResponse('與其他同時進行的修改發生鎖衝突，請重試', 409, ['changes' => ['lock_contention']]));
            }
            if ($this->isForeignKeyViolation($e)) {
                // 白名單開放的欄位裡有外鍵欄（ADDR_CODES.c_admin_cat_code → ADMIN_CAT_CODES、
                // ADDR_BELONGS_DATA.c_source → TEXT_CODES）。指向不存在的代碼是呼叫端的輸入
                // 問題，要回 422；不擋的話 MariaDB 1452 會一路冒到 controller 變成 500，
                // SQLite 則會被誤判成 409「請重試」而誘導無效的重試迴圈。
                return $this->withVariantNotices($this->errorResponse('關聯的記錄不存在（外鍵約束）', 422, ['changes' => ['foreign_key_violation']]));
            }
            // NOT NULL／CHECK：理論上已由 not_null_fields 在 422 擋下，這裡是登記漏了時的
            // 兜底——同樣是呼叫端輸入問題，不該以 500 或「請重試」的 409 呈現。
            if ($this->isNotNullOrCheckViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('欄位不可為空', 422, ['changes' => ['not_null_violation']]));
            }
            // 值轉換／超出範圍（strict sql_mode 才會拋）：同樣是輸入問題，兜底轉 422。
            if ($this->isValueRangeOrConversionViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('欄位值超出允許範圍或型別不符', 422, ['changes' => ['invalid_value']]));
            }
            // 部分表（如 char_variant_map 的 c_variant_char）在非主鍵欄位上另有唯一鍵；
            // 更新撞到該唯一鍵須回 409（可重試/可提示），不能讓資料庫層例外冒出成 500。
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('修改後的值與其他記錄的唯一鍵衝突', 409, ['changes' => ['conflict']]));
            }

            throw $e;
        }

        $this->resetVariantMapCacheIfNeeded($table);

        return $this->withVariantNotices(response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => $pk,
                'updated_fields' => array_keys($updateData),
                'operation_id' => $operation?->id,
                'row' => $newArray,
            ],
        ]));
    }

    protected function handleProposal(int $personId, array $pk, string $resourceId, array $updateData, array $originalArray, string $comment): JsonResponse {
        // char_variant_map：提案階段就擋。核准走的是通用 applyUpdateProposal()（本表不在
        // HANDLER_ROUTED_RESOURCES），那條路徑不驗結構——讓成環的對照混進待審提案，
        // 核准時就會直接落庫，之後 dropCycleEdges() 把整組邊丟掉、替換全站靜默停止。
        if (($guardError = $this->guardCharVariantMapWrite($this->tableName(), $updateData, isset($pk['id']) ? (int) $pk['id'] : null)) !== null) {
            return $guardError;
        }

        $proposalData = array_merge($originalArray, $updateData, [
            '__proposal_meta' => [
                'action' => 'update',
                'resource_type' => $this->resourceName(),
                'table' => $this->tableName(),
                'display_name' => $this->displayName(),
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => $this->keyColumns(),
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_UPDATE,
            $this->tableName(),
            $resourceId,
            $proposalData,
            $originalArray
        );

        // 提案 payload 已是替換後的值（掛鉤在 mode 分派之前），所以提案回應也要帶 notices
        // ——否則提案人不知道自己送的字形在審核畫面上已被改過。
        return $this->withVariantNotices(response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'proposal',
            'operation' => 'update',
            'result' => [
                'pk' => $pk,
                'updated_fields' => array_keys($updateData),
                'status' => 'proposal_updated',
                'operation_id' => $operation?->id,
            ],
        ]));
    }

    /**
     * 允許以整數值更新的欄位（預設無）。這組欄位存在的原因：本基底原本假設所有已登錄表的
     * allowed_fields 都是拼音／文字欄，一律要求 string|null；char_variant_map 的
     * c_strict_excluded 是第一個整數旗標欄，JSON 呼叫端會送整數（非字串）。刻意不對「所有」
     * 欄位一律放寬接受 int，避免其他表原本合法的「送整數應被拒絕」行為被意外放寬
     * （例如某拼音欄位不該接受數字型別）。需要整數欄位的子類／設定應覆寫或提供此清單。
     *
     * @return array<int,string>
     */
    protected function integerFields(): array {
        return [];
    }

    /**
     * 允許以浮點數更新的欄位（預設無）。理由同 integerFields()，只是值域是 double
     * （目前用於 ADDR_CODES 的 x_coord／y_coord 經緯度）。整數同樣被接受——JSON 的
     * `120` 與 `120.0` 是同一個座標，客戶端沒有辦法強制序列化成後者。
     *
     * @return array<int,string>
     */
    protected function floatFields(): array {
        return [];
    }

    /**
     * 不套 255 長度上限的欄位（預設無）：實際型別為 text／longtext 的欄。
     * 不登記的話 longtext 欄會被基底的 255 檢查誤擋（ADDR_CODES.c_notes 即是）。
     *
     * @return array<int,string>
     */
    protected function longTextFields(): array {
        return [];
    }

    /**
     * 資料庫 NOT NULL、不接受以 null 清空的欄位（預設無）。
     * 不登記的話送 null 會直接變成資料庫層的 1048 例外（500）而不是 422
     * （ADDR_CODES.c_admin_cat_code 即是：NOT NULL DEFAULT 0 且帶 FK）。
     *
     * @return array<int,string>
     */
    protected function notNullFields(): array {
        return [];
    }

    /**
     * 欄位值校驗：預設對每個白名單欄做「字串或 null、長度 ≤ 255」檢查；
     * integerFields()／floatFields()／longTextFields()／notNullFields() 逐欄放寬或收緊。
     * 判定本體與 create 端共用（{@see \App\Support\CodeTableFieldValidator}），
     * 確保同一張表在 create 與 update 得到一致的錯誤語義。需要更嚴格規則的表可覆寫。
     */
    protected function validateFields(array $data): array {
        return CodeTableFieldValidator::validate($data, $this->fieldTypeSpec());
    }

    /**
     * 自參照樹的「上層」欄位名（預設無）。設了之後 update 會擋掉成環的修改。
     * 目前只有 OFFICE_TYPE_TREE.c_parent_id。
     */
    protected function treeParentColumn(): ?string {
        return null;
    }

    /**
     * 本表的型別登記，整理成 {@see CodeTableFieldValidator} 的 spec 形狀。
     *
     * @return array<string,array<int,string>>
     */
    protected function fieldTypeSpec(): array {
        return [
            'integer_fields' => $this->integerFields(),
            'float_fields' => $this->floatFields(),
            'long_text_fields' => $this->longTextFields(),
            'not_null_fields' => $this->notNullFields(),
            // 由實際 schema 推導，不是手抄的 config（見 trait 註解）。
            'integer_ranges' => $this->integerRanges($this->tableName()),
        ];
    }

    /**
     * 寫入前對 updateData 的最後加工（預設為 no-op）。
     * 子類可覆寫以套用 §D-6 保存時拼音 v→ü 歸一化等。於變更偵測前呼叫。
     *
     * @param array<string,mixed> $updateData
     * @return array<string,mixed>
     */
    protected function preprocessUpdateData(array $updateData): array {
        return $updateData;
    }

    /**
     * 蓋「最後一次實際寫入」稽核欄（AGENTS.md §1.2），經 AuditActor 署名。
     *
     * 為什麼要在這裡補：本基底原本直接 `->update($updateData)`，從不蓋 c_modified_*。
     * 同一列若改走 /codes 表單就會被 CodesController 蓋章，於是同一張表的
     * c_modified_by 會依「最後是哪個介面改的」而時對時錯——比全部留空更難查。
     * 表沒有這組欄位（多數純代碼表）時原樣返回，不會製造 Unknown column。
     *
     * @param array<string,mixed> $updateData
     * @return array<string,mixed>
     */
    protected function stampModifiedColumns(string $table, array $updateData): array {
        $columns = $this->columnListing($table);
        if ($columns === []) {
            return $updateData;
        }

        if (in_array('c_modified_by', $columns, true)) {
            $updateData['c_modified_by'] = AuditActor::currentName();
        }
        if (in_array('c_modified_date', $columns, true)) {
            $updateData['c_modified_date'] = Carbon::now();
        }

        return $updateData;
    }

    /** 以主鍵定位單列。 */
    protected function findByPk(array $pk): ?object {
        return $this->whereByPk(DB::table($this->tableName()), $pk)->first();
    }
}
