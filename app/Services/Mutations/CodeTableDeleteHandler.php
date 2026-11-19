<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * config 驅動的 code／查找表「刪除」handler，供回滾/清理錯誤新增。
 * 定義見 config/code_table_writes.php。按 key_columns（單鍵或複合鍵）刪除，
 * 走既有授權 + operations + AuditLog（before-image）。
 *
 * 注意：碼表刪除預設停用（handle() 開頭回 403，理由見該處註解）；只有 config 標
 * `allow_delete: true` 的定義（目前僅 ENTRY_CODE_TYPE_REL，見
 * docs/ENTRY_CODE_TYPE_REL_V2_WRITE_PLAN.md D-4）會走到底下的刪除實作。
 */
class CodeTableDeleteHandler extends AbstractMutationHandler {
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
        return $operation === 'delete'
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
        $def = $this->findDefinition($resource);

        // 安全：碼表刪除預設停用。碼表多被人物資料以外鍵引用，刪一列可能牽動數萬筆人物列
        // （朝代、干支等高扇出碼尤甚）且無法乾淨復原。與前端 RISKY_DELETE_DISABLED、
        // CodesController::performDestroy 同步封堵。
        // 唯一的例外是 config 明確標 `allow_delete: true` 的定義——只給「全庫沒有任何外鍵引用
        // 本表」的純關聯表（ENTRY_CODE_TYPE_REL），且要有鎖住那個前提的護欄測試。
        // 這道檢查刻意排在授權之前：停用的表對誰都是 403，與過去的行為一致。
        if ($def === null || empty($def['allow_delete'])) {
            return $this->errorResponse('代碼表刪除已停用（防止級聯刪除人物資料）', 403, ['resource' => [$resource]]);
        }

        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $table = $def['table'];
        $keyColumns = $def['key_columns'];

        // 與 create 端相同的防呆：key_columns 與 CompositePrimaryKey::SCHEMAS 不一致時，
        // 部分鍵的 where 會一次刪掉多列。
        $schemaKeys = CompositePrimaryKey::getSchema($table);
        if ($schemaKeys === null || $keyColumns !== $schemaKeys) {
            return $this->errorResponse($table . ' 主鍵宣告與登錄不一致（設定錯誤）', 500, ['pk' => ['schema_mismatch']]);
        }
        // 型別讀不到時 isTextColumn() 一律回 false，文本主鍵會被 (int) 轉型——寧可 500 也不要刪錯列。
        if ($this->schemaUnavailable($table)) {
            return $this->errorResponse($table . ' 讀不到欄位型別，為避免刪錯列已中止', 500, ['pk' => ['schema_unavailable']]);
        }

        $pk = [];
        $missingKeys = [];
        $badKeys = [];
        foreach ($keyColumns as $col) {
            $value = $targetPk[$col] ?? null;
            if ($value === null || $value === '') {
                $missingKeys[] = $col;

                continue;
            }
            // 文本主鍵不可轉 int（'060102' → 60102 會刪錯列或刪不到）。
            if ($this->isTextColumn($table, $col)) {
                if (!is_string($value) && !is_int($value)) {
                    $badKeys[$col] = 'string';

                    continue;
                }
                $pk[$col] = (string) $value;

                continue;
            }
            // 數值主鍵：非數值一律 422。不擋的話 `(int) 'abc'` 會靜默變成 0，而 0 在 CBDB
            // 往往是合法碼，於是刪到一列呼叫端根本沒指名的記錄。
            if (!is_int($value) && !(is_string($value) && preg_match('/\A-?\d{1,18}\z/', $value) === 1)) {
                $badKeys[$col] = 'numeric';

                continue;
            }
            $pk[$col] = (int) $value;
        }
        if (!empty($badKeys)) {
            $errors = [];
            foreach ($badKeys as $col => $rule) {
                $errors['target.pk.' . $col] = [$rule];
            }

            return $this->errorResponse('主鍵格式不正確：' . implode('、', array_keys($badKeys)), 422, $errors);
        }
        if (!empty($missingKeys)) {
            $errors = [];
            foreach ($missingKeys as $col) {
                $errors['target.pk.' . $col] = ['required'];
            }

            return $this->errorResponse('缺少主鍵 ' . implode('、', $missingKeys), 422, $errors);
        }

        $original = $this->findExactRow($table, $pk);
        if (!$original) {
            return $this->errorResponse($table . ' 記錄不存在', 404);
        }
        $originalArray = $this->auditLogService->normalizeRow($original);

        $operation = null;

        try {
            DB::transaction(function () use (&$operation, $table, $pk, $originalArray, $personId) {
                $deleted = $this->whereByPk(DB::table($table), $pk)->delete();
                // 同一主鍵在 general_ci 下只可能對應一列（唯一鍵也按同一個 collation 判定），
                // 而上面已精確比對過；多於一列代表 key_columns 不是真正的唯一鍵，整筆回滾。
                if ($deleted !== 1) {
                    throw new CodeTableDeleteConflictException($deleted);
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_DELETE,
                    $table,
                    CompositePrimaryKey::buildStoredResourceId($pk),
                    $originalArray,
                    $originalArray
                );

                $this->auditLogService->write(
                    $table,
                    'DELETE',
                    $pk,
                    $originalArray,
                    null,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            });
        } catch (CodeTableDeleteConflictException $e) {
            return $e->deleted === 0
                ? $this->errorResponse($table . ' 記錄不存在（可能已被同時刪除）', 404)
                : $this->errorResponse($table . ' 主鍵條件命中多列，已中止', 409, ['target.pk' => ['ambiguous']]);
        } catch (\Illuminate\Database\QueryException $e) {
            // AGENTS §1.1：刪除撞到外鍵（1451，仍被引用）要轉友好報錯，不可吞掉也不可冒成 500。
            // allow_delete 的前提是「沒有任何外鍵引用本表」，正常走不到；走到代表前提被打破了。
            if ($this->isLockContention($e)) {
                return $this->errorResponse('與其他同時進行的修改發生鎖衝突，請重試', 409, ['target.pk' => ['lock_contention']]);
            }
            if ($this->isForeignKeyViolation($e)) {
                return $this->errorResponse('此記錄仍被其他資料引用，無法刪除', 409, ['target.pk' => ['referenced']]);
            }

            throw $e;
        }

        // char_variant_map 刪列後必須清掉行程內快取，否則被刪掉的對照在該 process 的剩餘
        // 生命週期內還會繼續替換（目前 char_variant_map 未開放刪除，這裡是防未來）。
        $this->resetVariantMapCacheIfNeeded($table);

        return response()->json([
            'ok' => true,
            'resource' => $def['resource'],
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => $pk,
                'status' => 'deleted',
                'operation_id' => $operation?->id,
                'row' => $originalArray,
            ],
        ]);
    }

    /**
     * 以主鍵找出**鍵值精確相等**的那一列。
     *
     * prod 是 utf8mb4_general_ci（不分大小寫、PAD SPACE）：`WHERE c_entry_type = 'XA'` 會命中
     * `'xa'` 那一列。若直接刪，被刪的是呼叫端沒有指名的列，而 operations／audit_log 記下的卻是
     * `'XA'`——稽核鏈指向一個從未存在的鍵。所以文本主鍵取回後再做 PHP 嚴格比較。
     */
    protected function findExactRow(string $table, array $pk): ?object {
        $candidates = $this->whereByPk(DB::table($table), $pk)->get();
        foreach ($candidates as $candidate) {
            $row = (array) $candidate;
            $matches = true;
            foreach ($pk as $col => $value) {
                // 依欄位型別而非值的 PHP 型別判斷：哪天上游少了 (string) 轉型、綁定的是 int，
                // MariaDB 的數值比較會讓 8 命中 '08'，這裡仍要擋得住。
                if ($this->isTextColumn($table, $col) && (string) ($row[$col] ?? '') !== (string) $value) {
                    $matches = false;

                    break;
                }
            }
            if ($matches) {
                return $candidate;
            }
        }

        return null;
    }
}
