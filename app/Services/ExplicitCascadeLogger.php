<?php

namespace App\Services;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use Illuminate\Support\Facades\Auth;

/**
 * 「應用層顯式級聯刪除」的紀錄器：把連帶刪除的每一列都留下痕跡。
 *
 * 去級聯（docs/ON_DELETE_CASCADE_RISK.md §4.4）要求連帶刪除改由應用層自己做，並且
 * **不得有任何一列在 operations／audit_log 之外悄悄消失**——DB 級聯之所以危險，正是
 * 因為被它刪掉的列對應用層完全不可見（§3.3）。搬到應用層後若只記父列、不記子列，
 * 等於把同一個盲區原封不動搬過來。
 *
 * 紀錄形式沿用專案既有慣例（AGENTS.md 高風險區備忘）：同一組子列合寫**一筆**
 * operations（`resource_data['rows']` 放全部被刪列、resource_id 沿用父資源格式），
 * 並**逐列**寫 audit_log（before-image）。整組共用同一個 operation_id，可整組回退。
 */
class ExplicitCascadeLogger {
    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(?OperationRepository $operationRepository = null, ?AuditLogService $auditLogService = null) {
        $this->operationRepository = $operationRepository ?? new OperationRepository();
        $this->auditLogService = $auditLogService ?? new AuditLogService();
    }

    /**
     * 記錄一組被連帶刪除的列。
     *
     * @param string $table 被刪表名
     * @param string $resourceId operations.resource_id（沿用父資源格式，見類別註解）
     * @param iterable<int,object|array> $rows 被刪除的列（**必須在 DELETE 之前取得**）
     * @param int $personId 歸屬人物
     * @param string|null $groupOperationId 已存在的群組 operation id；null 則以本次新建的為準
     * @return string|null 本組使用的 operation id
     */
    public function logDeletedRows(string $table, string $resourceId, iterable $rows, int $personId, ?string $groupOperationId = null): ?string {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->auditLogService->normalizeRow($row);
        }

        if (empty($normalized)) {
            return $groupOperationId;
        }

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_DELETE,
            $table,
            $resourceId,
            ['rows' => $normalized],
            ['rows' => $normalized]
        );

        $operationId = $groupOperationId ?? ($operation ? (string) $operation->id : null);

        foreach ($normalized as $rowData) {
            $this->auditLogService->logChange(
                $table,
                'DELETE',
                $this->auditLogService->buildRowPkFromData($table, $rowData),
                $rowData,
                null,
                $operationId
            );
        }

        return $operationId;
    }
}
