<?php

namespace App\Services\Mutations;

use App\Models\BiogMain;
use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIOG_MAIN（人物主檔）delete handler（direct 模式）。
 *
 * 對齊 legacy BasicInformationController::destroy() 語意：
 * - 人物「刪除」其實是軟刪除：將 c_name_chn 設為 '<待删除>' 後 save（UPDATE，非真 DELETE，
 *   不觸發 FK 連鎖、原列仍在）。
 * - 寫 operation op_type=4(TYPE_DELETE)。
 * - 寫 audit_log operation='UPDATE'（old=原 row、new=改名後 row）。
 * - 若 CBDB__NAME_FTS 存在，reindexPerson()。
 *
 * proposal 模式：本 handler 回 501。人物層級的提案走 legacy crowdsourcing_status
 * （見 BasicInformationController::destroy() 對眾包用戶的處理），非本次 v2 範圍。
 */
class BiogMainDeleteHandler extends AbstractMutationHandler {
    protected const DELETE_MARKER = '<待删除>';

    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService,
        NameSearchIndexService $nameSearchIndexService
    ) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['basicinformation', 'biogmain', 'biog_main'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'delete';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // proposal 模式：人物層級提案走 legacy crowdsourcing_status，非本次 v2 範圍。
        if ($mode === 'proposal') {
            return $this->errorResponse('人物主檔提案模式尚未於 v2 實作，請改用 legacy 眾包流程', 501, [
                'mode' => ['proposal_not_supported'],
            ]);
        }

        $authorizationError = $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'BIOG_MAIN');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if ((string) ($targetPk['c_personid'] ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與 target.pk.c_personid 不一致', 422, [
                'person_id' => ['mismatch'],
            ]);
        }

        $biog = BiogMain::find($personId);
        if (!$biog) {
            return $this->errorResponse('BIOG_MAIN 記錄不存在', 404);
        }

        $ori = $biog->toArray();

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        /** @var Operation|null $operation */
        $operation = null;

        try {
            DB::transaction(function () use ($biog, $personId, $ori, $comment, &$operation) {
                // 軟刪除：改名後 UPDATE（非真 DELETE，不連鎖）
                $biog->c_name_chn = self::DELETE_MARKER;
                $biog->save();

                $newData = $biog->toArray();

                $resourceData = $newData;
                if ($comment !== '') {
                    $resourceData['__note'] = $comment;
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_DELETE,
                    'BIOG_MAIN',
                    CompositePrimaryKey::buildStoredResourceId(['c_personid' => $personId]),
                    $resourceData,
                    $ori
                );

                $this->auditLogService->write(
                    'BIOG_MAIN',
                    'UPDATE',
                    ['c_personid' => $personId],
                    $ori,
                    $newData,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );

                if (Schema::hasTable('CBDB__NAME_FTS')) {
                    $this->nameSearchIndexService->reindexPerson($biog);
                }
            });
        } catch (\Throwable $e) {
            return $this->errorResponse('刪除失敗：'.$e->getMessage(), 500);
        }

        return response()->json([
            'ok' => true,
            'resource' => 'basicinformation',
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => ['c_personid' => $personId],
                'operation_id' => $operation?->id,
            ],
        ]);
    }
}
