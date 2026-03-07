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
use Illuminate\Support\Facades\Schema;

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

        $data = $this->sanitizePayload($payload, $table);
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
                    $this->logFinalOperation($operation, $appliedRow, $original, $opType);
                    $this->writeAuditLogForApproval($operation, $appliedRow, $original, $opType);
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
        if ($table === 'KIN_DATA') {
            return [$this->applyKinshipProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'ASSOC_DATA') {
            return [$this->applyAssocProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'POSTED_TO_OFFICE_DATA') {
            return [$this->applyOfficeProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ($table === 'EVENTS_DATA') {
            return [$this->applyEventProposal($operation, $data, $original, $auxiliaryPayload), true];
        }

        if ((int) $operation->op_type === Operation::TYPE_PROPOSAL_CREATE) {
            return [$this->applyCreateProposal($table, $data, $keyColumns), false];
        }

        return [$this->applyUpdateProposal($table, $data, $keyColumns, $original), false];
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
            return $this->biogMainRepository->kinshipStoreById($request, $personId);
        }

        if (empty($original)) {
            throw new \RuntimeException('缺少原始資料，無法更新。');
        }

        $result = $this->biogMainRepository->kinshipUpdateById(
            $request,
            $personId,
            $this->buildLegacyKinshipId($original)
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
            $result = $this->biogMainRepository->assocStoreById($request, $personId);

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
            $personId
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

        $updatePayload = $this->buildUpdatePayload($data, $keyColumns, $original);
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
