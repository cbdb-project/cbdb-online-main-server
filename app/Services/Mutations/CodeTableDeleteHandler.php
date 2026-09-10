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
 * 注意：handle() 開頭目前無條件回 403（碼表刪除全面停用，理由見該處註解），
 * 底下的刪除實作是為了恢復時可用而維持著、目前不可達。
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
        // 安全：停用碼表刪除。碼表多被人物資料以 ON DELETE CASCADE 外鍵引用，刪一列可能
        // 連帶刪除數萬筆人物列（朝代、干支等高扇出碼尤甚）且無法乾淨復原；現行刪除無引用
        // 護欄。在補上「有引用則拒刪」前，前後端一律封堵（與前端 RISKY_DELETE_DISABLED、
        // CodesController::performDestroy 同步）。移除本護欄前務必先加引用護欄。
        return $this->errorResponse('代碼表刪除已停用（防止級聯刪除人物資料）', 403, ['resource' => [$resource]]);

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

        $pk = [];
        $missingKeys = [];
        foreach ($keyColumns as $col) {
            $value = $targetPk[$col] ?? null;
            if ($value === null || $value === '') {
                $missingKeys[] = $col;

                continue;
            }
            $pk[$col] = (int) $value;
        }
        if (!empty($missingKeys)) {
            $errors = [];
            foreach ($missingKeys as $col) {
                $errors['target.pk.' . $col] = ['required'];
            }

            return $this->errorResponse('缺少主鍵 ' . implode('、', $missingKeys), 422, $errors);
        }

        $original = $this->whereByPk(DB::table($table), $pk)->first();
        if (!$original) {
            return $this->errorResponse($table . ' 記錄不存在', 404);
        }
        $originalArray = $this->auditLogService->normalizeRow($original);

        $operation = null;
        DB::transaction(function () use (&$operation, $table, $pk, $originalArray, $personId) {
            $this->whereByPk(DB::table($table), $pk)->delete();

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

        // 注意：本方法開頭已無條件回 403（碼表刪除停用中），以下都是不可達的既有碼。
        // 若日後恢復刪除，char_variant_map 刪列後**必須**呼叫
        // $this->resetVariantMapCacheIfNeeded($table)（trait 已掛好），
        // 否則被刪掉的對照在該 process 的剩餘生命週期內還會繼續替換。
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
            ],
        ]);
    }
}
