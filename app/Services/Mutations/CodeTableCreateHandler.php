<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * config 驅動的 code 表「新增」handler（先支援 TEXT_CODES）。定義見 config/code_table_writes.php。
 *
 * 與 person-subresource create 的差異：單一主鍵、無 c_personid，且支援「服務端自動分配 id」
 * （auto_assign_id：未給主鍵時取 max(key)+1）。走既有授權（direct → canWriteDirectly）
 * + operations + AuditLog，回傳 operation_id 可回滾；token 可用；batch_mutate 逐筆復用本 handler。
 *
 * person_id 對本表無意義：呼叫端仍須帶（controller 要求），本 handler 僅將其原樣記入 operations.c_personid。
 */
class CodeTableCreateHandler extends AbstractMutationHandler {
    use \App\Services\Mutations\Concerns\GuardsCharVariantMapWrites;
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
        $keyColumn = $def['key_column'];
        $allowed = $def['allowed_fields'];

        // 白名單校驗（可含主鍵欄）
        $disallowed = array_diff(array_keys($changes), array_merge($allowed, [$keyColumn]));
        if (!empty($disallowed)) {
            return $this->errorResponse('包含不允許的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowed)],
            ]);
        }

        // 決定主鍵：顯式（target.pk 或 changes 帶 keyColumn）優先；否則自動分配。
        $explicitId = $targetPk[$keyColumn] ?? ($changes[$keyColumn] ?? null);
        $explicit = $explicitId !== null && $explicitId !== '';
        if (!$explicit && empty($def['auto_assign_id'])) {
            return $this->errorResponse('缺少主鍵 ' . $keyColumn, 422, ['target.pk.' . $keyColumn => ['required']]);
        }

        // 顯式主鍵：先擋重複（TOCTOU 由交易內唯一鍵兜底）。
        if ($explicit && DB::table($table)->where($keyColumn, (int) $explicitId)->exists()) {
            return $this->errorResponse('目標主鍵已存在', 409, ['target.pk' => ['conflict']]);
        }

        $row = array_intersect_key($changes, array_flip($allowed));
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
            DB::transaction(function () use (&$operation, &$insertedArray, $table, $keyColumn, $explicit, $explicitId, $def, $row, $personId, $operationId, $comment) {
                $id = $explicit
                    ? (int) $explicitId
                    : (max(0, (int) DB::table($table)->max($keyColumn)) + 1);

                $rowData = array_merge($row, [$keyColumn => $id]);
                $rowData = app(ToolsRepository::class)->timestamp($rowData, true);

                DB::table($table)->insert($rowData);

                $inserted = DB::table($table)->where($keyColumn, $id)->first();
                $insertedArray = $this->auditLogService->normalizeRow($inserted);

                $pk = [$keyColumn => $id];
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
            // 顯式撞號 TOCTOU、或 auto-assign 並發搶到同 id → 唯一鍵衝突轉 409（呼叫端可重試）。
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->errorResponse('目標主鍵已存在（並發衝突，請重試）', 409, ['target.pk' => ['conflict']]);
            }

            throw $e;
        }

        $this->resetVariantMapCacheIfNeeded($table);

        return response()->json([
            'ok' => true,
            'resource' => $def['resource'],
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => [$keyColumn => (int) ($insertedArray[$keyColumn] ?? 0)],
                'status' => 'created',
                'operation_id' => $operation?->id,
                'row' => $insertedArray,
            ],
        ]);
    }

    private function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, [1062, 19], true)) {
            return true;
        }
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate entry');
    }
}
