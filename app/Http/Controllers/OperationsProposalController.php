<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\NameSearchIndexService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationsProposalController extends Controller {
    protected $operationRepository;
    protected $nameSearchIndexService;

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

    public function __construct(OperationRepository $operationRepository, NameSearchIndexService $nameSearchIndexService) {
        $this->operationRepository = $operationRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
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

        $data = $this->sanitizePayload($payload);
        $comment = trim((string) $request->input('review_comment', ''));

        try {
            DB::transaction(function () use ($opType, $table, $data, $keyColumns, $original, $operation, $comment) {
                if ($opType === Operation::TYPE_PROPOSAL_CREATE) {
                    $appliedRow = $this->applyCreateProposal($table, $data, $keyColumns);
                } else {
                    $appliedRow = $this->applyUpdateProposal($table, $data, $keyColumns, $original);
                }

                $this->logFinalOperation($operation, $appliedRow, $original, $opType);
                $this->writeAuditLogForApproval($operation, $appliedRow, $original, $opType);
                $this->updateProposalStatus(
                    $operation,
                    'approved',
                    $comment,
                    $opType === Operation::TYPE_PROPOSAL_CREATE ? $appliedRow : null,
                    $keyColumns,
                    $opType === Operation::TYPE_PROPOSAL_CREATE
                );
            });
        } catch (\Throwable $e) {
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
        if (!Auth::check() || !Auth::user()->canRestoreOperations()) {
            abort(403, '無權審核提案。');
        }

        $opType = (int) $operation->op_type;
        if (!in_array($opType, [Operation::TYPE_PROPOSAL_CREATE, Operation::TYPE_PROPOSAL_UPDATE], true)) {
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

    protected function sanitizePayload(array $payload): array {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && strpos($key, '__') === 0) {
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    protected function applyCreateProposal(string $table, array $data, array $keyColumns): array {
        $data = $this->assignAutoKeyIfNeeded($table, $keyColumns, $data);

        if (!$this->hasKeyValues($keyColumns, $data)) {
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

    protected function applyUpdateProposal(string $table, array $data, array $keyColumns, array $original): array {
        if (empty($original)) {
            throw new \RuntimeException('缺少原始資料，無法更新。');
        }

        $conditions = $this->buildKeyConditions($keyColumns, $original);

        // 檢查是否有對應的模型類，如果有則使用 Eloquent 模型以觸發觀察者
        if (isset($this->tableModelMap[$table])) {
            $modelClass = $this->tableModelMap[$table];
            $model = $modelClass::where($conditions)->first();
            if (!$model) {
                throw new \RuntimeException('資料不存在或已被刪除，無法更新。');
            }

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
            DB::table($table)->where($conditions)->update($updatePayload);
        }

        $row = DB::table($table)->where($conditions)->first();
        if (!$row) {
            throw new \RuntimeException('更新後讀取資料失敗。');
        }

        // 特殊處理：ALTNAME_DATA 需要手動調用索引服務
        if ($table === 'ALTNAME_DATA') {
            $this->indexAltnameAfterUpdate($original, $data);
        }

        return $this->convertRowToArray($row);
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

    protected function hasKeyValues(array $keyColumns, array $row): bool {
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                return false;
            }
        }

        return true;
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

        (new AuditLogService())->write(
            $proposal->resource,
            $proposalType === Operation::TYPE_PROPOSAL_CREATE ? 'INSERT' : 'UPDATE',
            $rowPk,
            $proposalType === Operation::TYPE_PROPOSAL_CREATE ? null : $original,
            $appliedRow,
            'user',
            (string) Auth::id(),
            (string) $proposal->id
        );
    }

    protected function logFinalOperation(Operation $proposal, array $appliedRow, array $original, int $proposalType): void {
        $proposalData = json_decode($proposal->resource_data, true) ?? [];
        $keyColumns = $proposalData['__key_columns'] ?? [];
        $resourceId = $this->buildCompositeId($keyColumns, $appliedRow);
        $type = $proposalType === Operation::TYPE_PROPOSAL_CREATE
            ? Operation::TYPE_CREATE
            : Operation::TYPE_UPDATE;

        // 對於 BiogMain 相關提案，使用實際的 c_personid；對於 Codes 提案使用 0
        $personId = $proposal->c_personid ?? 0;

        $this->operationRepository->store(
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
