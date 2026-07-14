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
 * config 驅動的 code 表「刪除」handler（先支援 TEXT_CODES），供回滾/清理錯誤新增。
 * 定義見 config/code_table_writes.php。按單主鍵刪除，走既有授權 + operations + AuditLog（before-image）。
 */
class CodeTableDeleteHandler extends AbstractMutationHandler {
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

        $id = $targetPk[$keyColumn] ?? null;
        if ($id === null || $id === '') {
            return $this->errorResponse('缺少主鍵 ' . $keyColumn, 422, ['target.pk.' . $keyColumn => ['required']]);
        }
        $id = (int) $id;

        $original = DB::table($table)->where($keyColumn, $id)->first();
        if (!$original) {
            return $this->errorResponse($table . ' 記錄不存在', 404);
        }
        $originalArray = $this->auditLogService->normalizeRow($original);

        $operation = null;
        DB::transaction(function () use (&$operation, $table, $keyColumn, $id, $originalArray, $personId) {
            DB::table($table)->where($keyColumn, $id)->delete();

            $pk = [$keyColumn => $id];
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

        return response()->json([
            'ok' => true,
            'resource' => $def['resource'],
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => [$keyColumn => $id],
                'status' => 'deleted',
                'operation_id' => $operation?->id,
            ],
        ]);
    }
}
