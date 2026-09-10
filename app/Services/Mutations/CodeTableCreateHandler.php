<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\AuditLogService;
use App\Support\CodeTableFieldValidator;
use App\Support\CompositePrimaryKey;
use App\Support\PinyinUmlaut;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * config 驅動的 code／查找表「新增」handler。定義見 config/code_table_writes.php。
 *
 * 與 person-subresource create 的差異：無 c_personid，且單一數值主鍵的表支援
 * 「服務端自動分配 id」（auto_assign_id：未給主鍵時取 max(key)+1）。複合主鍵的表
 * （如 ADDR_BELONGS_DATA）則要求呼叫端把全部主鍵欄給齊。走既有授權
 * （direct → canWriteDirectly）+ operations + AuditLog，回傳 operation_id 可回滾；
 * token 可用；batch_mutate 逐筆復用本 handler。
 *
 * person_id 對本表無意義：呼叫端仍須帶（controller 要求），本 handler 僅將其原樣記入 operations.c_personid。
 */
class CodeTableCreateHandler extends AbstractMutationHandler {
    use \App\Services\Mutations\Concerns\AppliesVariantReplacement;
    use \App\Services\Mutations\Concerns\GuardsCharVariantMapWrites;
    use \App\Services\Mutations\Concerns\HandlesCodeTableWrites;
    protected array $definitions;
    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(OperationRepository $operationRepository, AuditLogService $auditLogService) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
        $this->definitions = config('code_table_writes.tables', []);
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'create'
            && $mode === 'direct'
            && $this->findDefinition($resource) !== null;
    }

    protected function findDefinition(string $resource): ?array {
        foreach ($this->definitions as $def) {
            if (in_array($resource, $def['aliases'] ?? [], true)) {
                return $def;
            }
        }

        return null;
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $def = $this->findDefinition($resource);
        if ($def === null) {
            return $this->errorResponse('目前尚未支援此 code 表', 501, ['resource' => [$resource]]);
        }

        $table = $def['table'];
        $keyColumns = $def['key_columns'];
        $allowed = $def['allowed_fields'];
        $autoAssign = !empty($def['auto_assign_id']);

        // 防呆：key_columns 必須與 CompositePrimaryKey::SCHEMAS 登錄的主鍵完全一致（順序＋欄名）。
        // 少登一欄就會讓「重複主鍵」檢查只比對部分鍵，把合法的新列誤判成 409；
        // 多登／錯登則讓 resource_id 與 audit_log.row_pk 指向一個不存在的鍵。
        $schemaKeys = CompositePrimaryKey::getSchema($table);
        if ($schemaKeys === null || $keyColumns !== $schemaKeys) {
            return $this->errorResponse($table . ' 主鍵宣告與登錄不一致（設定錯誤）', 500, ['pk' => ['schema_mismatch']]);
        }

        // auto_assign_id 只在單一主鍵欄時有語義（複合主鍵沒有「下一個 id」）。
        if ($autoAssign && count($keyColumns) !== 1) {
            return $this->errorResponse($table . ' 複合主鍵不支援自動分配主鍵（設定錯誤）', 500, ['pk' => ['auto_assign_unsupported']]);
        }

        // 白名單校驗（可含主鍵欄）
        $disallowed = array_diff(array_keys($changes), array_merge($allowed, $keyColumns));
        if (!empty($disallowed)) {
            return $this->errorResponse('包含不允許的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowed)],
            ]);
        }

        // 決定主鍵：顯式（target.pk 或 changes 帶主鍵欄）優先；單鍵表未給時可自動分配。
        $explicitPk = [];
        $missingKeys = [];
        $badKeys = [];
        foreach ($keyColumns as $col) {
            $value = $targetPk[$col] ?? ($changes[$col] ?? null);
            if ($value === null || $value === '') {
                $missingKeys[] = $col;

                continue;
            }
            // 本檔只登錄數值主鍵的表（見 config 的登錄前提），非數值一律是呼叫端錯誤。
            // 不擋的話 `(int) 'abc'` 會靜默變成 0——而 0 在 CBDB 往往是合法值
            // （c_firstyear = 0 就是），於是憑空生出一列鍵值錯誤、事後看不出異常的記錄。
            // 順帶擋掉超出 PHP int 精度的字串：`(int) '99999999999999999999'` 會**飽和**成
            // PHP_INT_MAX 而不是報錯，於是值域檢查會看到一個「剛好在範圍內」的數字。
            if (!is_int($value) && !(is_string($value)
                && preg_match('/\A-?\d+\z/', $value) === 1
                && (string) (int) preg_replace('/\A(-?)0+(?=\d)/', '$1', $value) === preg_replace('/\A(-?)0+(?=\d)/', '$1', $value))) {
                $badKeys[] = $col;

                continue;
            }
            $explicitPk[$col] = (int) $value;
        }

        if (!empty($badKeys)) {
            $errors = [];
            foreach ($badKeys as $col) {
                $errors['target.pk.' . $col] = ['numeric'];
            }

            return $this->errorResponse('主鍵必須為整數：' . implode('、', $badKeys), 422, $errors);
        }

        // 主鍵也要驗值域。主鍵在白名單化時就被抽出去了，不會經過
        // CodeTableFieldValidator，所以這裡要自己驗——漏掉的話
        // `ADDR_BELONGS_DATA` 的 c_firstyear（smallint）收到 40000 會被非 strict 的
        // MariaDB 靜默截斷成 32767，於是這一列被建在一個**呼叫端沒有指定的主鍵**上：
        // 回應說建好了 40000，實際存的是 32767，之後照回應的鍵去改／刪都會 404。
        $ranges = $this->integerRanges($table);
        $outOfRangeKeys = [];
        foreach ($explicitPk as $col => $value) {
            $range = $ranges[strtolower($col)] ?? null;
            if ($range !== null && ($value < $range[0] || $value > $range[1])) {
                $outOfRangeKeys[$col] = $range;
            }
        }

        if (!empty($outOfRangeKeys)) {
            $errors = [];
            foreach ($outOfRangeKeys as $col => $range) {
                $errors['target.pk.' . $col] = ['out_of_range:' . $range[0] . '..' . $range[1]];
            }

            return $this->errorResponse('主鍵超出欄位允許範圍：' . implode('、', array_keys($outOfRangeKeys)), 422, $errors);
        }

        if (!empty($missingKeys)) {
            // 單鍵＋auto_assign：允許整個主鍵缺席，服務端分配。
            if (!($autoAssign && count($missingKeys) === count($keyColumns))) {
                $errors = [];
                foreach ($missingKeys as $col) {
                    $errors['target.pk.' . $col] = ['required'];
                }

                return $this->errorResponse('缺少主鍵 ' . implode('、', $missingKeys), 422, $errors);
            }
            $explicitPk = [];
        }

        // 顯式主鍵：先擋重複（TOCTOU 由交易內唯一鍵兜底）。
        if (!empty($explicitPk) && $this->whereByPk(DB::table($table), $explicitPk)->exists()) {
            return $this->errorResponse('目標主鍵已存在', 409, ['target.pk' => ['conflict']]);
        }

        $row = array_intersect_key($changes, array_flip($allowed));

        // 型別正規化 + 校驗：與 update 端共用同一份判定（CodeTableFieldValidator）。
        // 未登記型別的欄位一律要求 string|null 且 ≤ 255；不擋的話型別不合會變成資料庫層 500。
        // 第三個參數（文字欄接受 JSON 數字）**只有 create 端開啟**——這條路徑在加上校驗
        // 之前完全沒有型別檢查，突然改判 422 會打斷既有的外部 token 客戶端；update 端的
        // 嚴格要求則是刻意驗過的契約，不為了對稱而拆。理由詳見 normalize() 的類註。
        $row = CodeTableFieldValidator::normalize($row, $def, true);
        $validationErrors = CodeTableFieldValidator::validate($row, $def + ['integer_ranges' => $this->integerRanges($table)]);
        if (!empty($validationErrors)) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        // §D-6 保存止血：Tier 1 拼音欄的 v→ü 靜默歸一化。**create 端原本沒有這一步**，
        // 於是同一個 `lv` 走 /api/v2/create 存 `lv`、走 /api/v2/mutate 或 /codes 存 `lü`
        // ——同一欄兩種寫法，而 §D-6 的承諾是「保存時一律歸一」。Tier 定義只有一份
        // （config/code_table_mutations.php），這裡按表名去查，不在本檔複製一份 tier 清單。
        // 順序比照 update 端：歸一化在落地替換之前。
        $row = PinyinUmlaut::normalizeFields($row, $this->tier1FieldsFor($table));

        // 異體字落地替換（型別驅動）。掛在白名單化之後、落庫（與 ToolsRepository::timestamp()
        // 蓋稽核欄）之前；稽核欄本來就在排除清單裡。char_variant_map 自身在 EXCLUDED_TABLES
        // （替換等於自我吞噬），所以對照表的維護不會被自己改寫。
        //
        // 這一步也修掉 G4 的不一致：TEXT_CODES.c_title_chn 走 Codes UI／書名批次匯入會被歸一，
        // 走 token API 卻不會——同一個輸入落庫兩種字形。
        // 本表全部主鍵都是數值欄（見 config 的登錄前提），替換不可能動到主鍵，
        // 所以「替換必須早於 PK 計算」在這條路徑上自然成立。
        // 第二個參數**必須顯式傳**：本類別沒有 tableName()，省略會 fallback 到不存在的
        // 方法而在 runtime 炸掉（不是靜態錯誤）。
        $this->resetVariantReplaced();
        $row = $this->applyVariantReplacement($row, $table);

        $operationId = (string) Str::ulid();
        $operation = null;
        $insertedArray = [];
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        // char_variant_map：落庫前驗結構（單一 codepoint、不成環），否則成環的對照會讓
        // dropCycleEdges() 靜默丟掉整組邊、該組字的替換在全站停止（見 trait 註解）。
        if (($guardError = $this->guardCharVariantMapWrite($table, $row)) !== null) {
            return $guardError;
        }

        try {
            DB::transaction(function () use (&$operation, &$insertedArray, $table, $keyColumns, $explicitPk, $autoAssign, $row, $personId, $operationId, $comment) {
                if (!empty($explicitPk)) {
                    $pk = $explicitPk;
                } else {
                    $keyColumn = $keyColumns[0];
                    $pk = [$keyColumn => max(0, (int) DB::table($table)->max($keyColumn)) + 1];
                }

                $rowData = array_merge($row, $pk);
                $rowData = $this->stampAuditColumns($table, $rowData);

                DB::table($table)->insert($rowData);

                $inserted = $this->whereByPk(DB::table($table), $pk)->first();
                $insertedArray = $this->auditLogService->normalizeRow($inserted);

                $resourceData = array_merge($insertedArray, ['__operation_id' => $operationId]);
                if ($comment !== '') {
                    $resourceData['__note'] = $comment;
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_CREATE,
                    $table,
                    CompositePrimaryKey::buildStoredResourceId($pk),
                    $resourceData,
                    []
                );

                $this->auditLogService->write(
                    $table,
                    'INSERT',
                    $pk,
                    null,
                    $insertedArray,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // 這幾個回應都在落地替換之後，所以**必須**帶 notices（AGENTS §1.3：成功、409、
            // 422 都要掛）——被擋下來時使用者更需要知道自己輸入的字被正規化了，否則交易
            // 回滾後他重送的內容會與他以為送出的不同。
            //
            // 判定順序不可調換：SQLite 的 UNIQUE／FOREIGN KEY／NOT NULL 共用 errno 19，
            // trait 內以訊息互斥（見 HandlesCodeTableWrites）。
            //
            // 外鍵指向不存在的列（如 ADDR_BELONGS_DATA 的上級地名尚未建立）是呼叫端的
            // 輸入問題，要回 422 而不是把資料庫例外冒成 500。
            if ($this->isForeignKeyViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('關聯的記錄不存在（外鍵約束）', 422, ['changes' => ['foreign_key_violation']]));
            }
            // NOT NULL／CHECK：理論上已由 not_null_fields 在 422 擋下，這裡是登記漏了時的兜底。
            if ($this->isNotNullOrCheckViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('欄位不可為空', 422, ['changes' => ['not_null_violation']]));
            }
            // 值轉換／超出範圍（strict sql_mode 才會拋）：同樣是輸入問題，兜底轉 422。
            if ($this->isValueRangeOrConversionViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('欄位值超出允許範圍或型別不符', 422, ['changes' => ['invalid_value']]));
            }
            // 顯式撞號 TOCTOU、或 auto-assign 並發搶到同 id → 唯一鍵衝突轉 409（呼叫端可重試）。
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->withVariantNotices($this->errorResponse('目標主鍵已存在（並發衝突，請重試）', 409, ['target.pk' => ['conflict']]));
            }

            throw $e;
        }

        $this->resetVariantMapCacheIfNeeded($table);

        $resultPk = [];
        foreach ($keyColumns as $col) {
            $resultPk[$col] = (int) ($insertedArray[$col] ?? 0);
        }

        return $this->withVariantNotices(response()->json([
            'ok' => true,
            'resource' => $def['resource'],
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => $resultPk,
                'status' => 'created',
                'operation_id' => $operation?->id,
                'row' => $insertedArray,
            ],
        ]));
    }

    /**
     * 該表的 §D-6 Tier 1 拼音欄（純拼音、無西文，保存時靜默 v→ü）。
     *
     * Tier 的定義只存在於 `config/code_table_mutations.php`（update 端的 config）；
     * 這裡按**表名**去查而不是在 code_table_writes 複製一份，否則兩份 tier 清單一定會漂移
     * ——而漂移的症狀是「同一個值在新增與修改後落庫成不同字形」，最難查的那一類。
     * 沒登錄在那份 config 的表（純新增表）回空陣列＝不做歸一。
     *
     * @return array<int,string>
     */
    protected function tier1FieldsFor(string $table): array {
        foreach ((array) config('code_table_mutations.tables', []) as $definition) {
            if (($definition['table'] ?? null) === $table) {
                return (array) ($definition['tier1_fields'] ?? []);
            }
        }

        return [];
    }

    /**
     * 蓋建檔稽核欄（AGENTS.md §1.2，經 AuditActor）。
     *
     * 為什麼要先看 schema：ToolsRepository::timestamp() 無條件塞 c_created_by／c_created_date，
     * 而 CBDB 的表不是每張都有這組欄位。缺欄的表會直接以「Unknown column」失敗——
     * 這正是 ADDR_CODES 在補上 2026_09_10 那支 migration 之前必然新增失敗的原因。
     * 這裡按實際欄位過濾，讓 config 登錄新表時不必先確認稽核欄是否齊全。
     *
     * @param array<string,mixed> $rowData
     * @return array<string,mixed>
     */
    protected function stampAuditColumns(string $table, array $rowData): array {
        $stamped = app(ToolsRepository::class)->timestamp($rowData, true);

        $columns = $this->columnListing($table);
        // 查不到欄位（連線／權限異常）時**不剔除**：寧可讓插入以「Unknown column」失敗，
        // 也不要靜默插進一列沒有署名與時間的資料（那正是 §1.2 要防的反面）。
        if ($columns === []) {
            return $stamped;
        }

        foreach (['c_created_by', 'c_created_date'] as $column) {
            if (!in_array(strtolower($column), $columns, true)) {
                unset($stamped[$column]);
            }
        }

        return $stamped;
    }
}
