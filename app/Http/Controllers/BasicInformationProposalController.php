<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BasicInformationProposalController extends Controller {
    protected $biogMainRepository;
    protected $operationRepository;
    protected array $tableColumnCache = [];

    /**
     * 資源配置數組
     * 定義每個資源類型的表名、主鍵、控制器等信息
     */
    protected $resourceConfigs = [
        'biogmain' => [
            'table' => 'BIOG_MAIN',
            'key_columns' => ['c_personid'],
            'controller' => 'BasicInformationController',
            'route_prefix' => 'basicinformation',
            'display_name' => '基本資料',
        ],
        'altnames' => [
            'table' => 'ALTNAME_DATA',
            'key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            'controller' => 'BasicInformationAltnamesController',
            'route_prefix' => 'basicinformation.altnames',
            'display_name' => '別名',
        ],
        'addresses' => [
            'table' => 'BIOG_ADDR_DATA',
            'key_columns' => ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'],
            'controller' => 'BasicInformationAddressesController',
            'route_prefix' => 'basicinformation.addresses',
            'display_name' => '地址',
        ],
        'texts' => [
            'table' => 'BIOG_TEXT_DATA',
            'key_columns' => ['c_personid', 'c_textid', 'c_role_id'],
            'controller' => 'BasicInformationTextsController',
            'route_prefix' => 'basicinformation.texts',
            'display_name' => '著述',
        ],
        'statuses' => [
            'table' => 'STATUS_DATA',
            'key_columns' => ['c_personid', 'c_sequence', 'c_status_code'],
            'controller' => 'BasicInformationStatusesController',
            'route_prefix' => 'basicinformation.statuses',
            'display_name' => '身份',
        ],
        'possessions' => [
            'table' => 'POSSESSION_DATA',
            'key_columns' => ['c_possession_record_id'],
            'controller' => 'BasicInformationPossessionController',
            'route_prefix' => 'basicinformation.possession',
            'display_name' => '所有物',
        ],
        'offices' => [
            'table' => 'POSTED_TO_OFFICE_DATA',
            'key_columns' => ['c_office_id', 'c_posting_id'],
            'controller' => 'BasicInformationOfficesController',
            'route_prefix' => 'basicinformation.offices',
            'display_name' => '官名',
        ],
        'assoc' => [
            'table' => 'ASSOC_DATA',
            'key_columns' => ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'],
            'optional_key_columns' => ['c_text_title'],
            'controller' => 'BasicInformationAssocController',
            'route_prefix' => 'basicinformation.assoc',
            'display_name' => '社會關係',
        ],
        'entries' => [
            'table' => 'ENTRY_DATA',
            'key_columns' => ['c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'],
            'controller' => 'BasicInformationEntriesController',
            'route_prefix' => 'basicinformation.entries',
            'display_name' => '入仕',
        ],
        'events' => [
            'table' => 'EVENTS_DATA',
            'key_columns' => ['c_personid', 'c_sequence', 'c_event_code'],
            'controller' => 'BasicInformationEventsController',
            'route_prefix' => 'basicinformation.events',
            'display_name' => '事件',
        ],
        'kinship' => [
            'table' => 'KIN_DATA',
            'key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            'controller' => 'BasicInformationKinshipController',
            'route_prefix' => 'basicinformation.kinship',
            'display_name' => '親屬',
        ],
        'socialinst' => [
            'table' => 'BIOG_INST_DATA',
            'key_columns' => ['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code'],
            'controller' => 'BasicInformationSocialInstController',
            'route_prefix' => 'basicinformation.socialinst',
            'display_name' => '社交機構',
        ],
        'sources' => [
            'table' => 'BIOG_SOURCE_DATA',
            'key_columns' => ['c_personid', 'c_textid', 'c_pages'],
            'controller' => 'BasicInformationSourcesController',
            'route_prefix' => 'basicinformation.sources',
            'display_name' => '出處',
        ],
    ];

    public function __construct(
        BiogMainRepository $biogMainRepository,
        OperationRepository $operationRepository
    ) {
        $this->biogMainRepository = $biogMainRepository;
        $this->operationRepository = $operationRepository;
    }

    /**
     * 提交新增提案（子資源）
     */
    public function proposalStore(Request $request, $personid, $resourceType) {
        if (!Auth::check()) {
            abort(403);
        }

        $this->ensureCanPropose();

        $config = $this->getResourceConfig($resourceType);
        $table = $config['table'];
        $keyColumns = $config['key_columns'];
        $optionalKeyColumns = $config['optional_key_columns'] ?? [];

        // 提取表單數據
        $formData = $this->extractFormData($request);
        [$payload, $auxiliaryPayload] = $this->splitFormDataByTable($table, $formData);
        $payload['c_personid'] = $personid;
        $payload = $this->assignSingleNumericPrimaryKeyIfNeeded($table, $keyColumns, $payload);
        $payload = $this->normalizePayloadForTable($table, $payload);

        // 驗證主鍵完整性
        if (!$this->hasPrimaryKeyValues($keyColumns, $payload, $optionalKeyColumns)) {
            flash('提案失敗：請確認主鍵欄位已填寫完整。', 'error');

            return redirect()->back()->withInput();
        }

        // 檢查資料是否已存在
        $conditions = $this->buildConditionsFromRow($keyColumns, $payload);
        $existing = $this->fetchRowByKeys($table, $keyColumns, $conditions);
        if ($existing) {
            flash('提案失敗：資料已存在，請改用修改提案。', 'warning');

            return redirect()->back()->withInput();
        }

        // 檢查是否有待審核的新增提案衝突
        if ($this->hasActiveProposalConflict($table, $keyColumns, $payload, Operation::TYPE_PROPOSAL_CREATE)) {
            flash('提案失敗：已有其他新增提案使用相同主鍵，請調整後再提交。', 'warning');

            return redirect()->back()->withInput();
        }

        // 構建提案元數據
        $meta = $this->buildProposalMeta('create', $resourceType, $request);

        // 記錄提案操作
        $operation = $this->recordProposalOperation(
            Operation::TYPE_PROPOSAL_CREATE,
            $table,
            $keyColumns,
            $personid,
            $payload,
            [],
            $meta,
            $auxiliaryPayload
        );

        if ($operation) {
            flash('已提交新增提案，等待管理員審核 @ ' . Carbon::now(), 'info');
        }

        return redirect()->route($config['route_prefix'] . '.index', [
            'basicinformation' => $personid,
        ]);
    }

    /**
     * 提交修改提案（子資源）- 舊格式（使用編碼的複合主鍵字串）
     *
     * @deprecated 請使用 proposalUpdateWithPk() 方法，直接傳遞主鍵陣列
     */
    public function proposalUpdate(Request $request, $personid, $resourceType, $id) {
        if (!Auth::check()) {
            abort(403);
        }

        $this->ensureCanPropose();

        $config = $this->getResourceConfig($resourceType);
        $table = $config['table'];
        $keyColumns = $config['key_columns'];

        // 解析複合主鍵
        $conditions = $this->parseCompositeId($id, $keyColumns);

        return $this->doProposalUpdate($request, $personid, $resourceType, $conditions, $id);
    }

    /**
     * 提交修改提案（子資源）- 新格式（直接使用主鍵陣列）
     *
     * @param Request $request HTTP 請求
     * @param int $personid 人物 ID
     * @param string $resourceType 資源類型
     * @param array $originalPk 原始複合主鍵陣列（用於定位記錄）
     * @return \Illuminate\Http\RedirectResponse
     */
    public function proposalUpdateWithPk(Request $request, $personid, $resourceType, array $originalPk) {
        $this->ensureCanPropose();

        return $this->doProposalUpdate($request, $personid, $resourceType, $originalPk, null);
    }

    /**
     * 執行修改提案的核心邏輯
     *
     * @param Request $request HTTP 請求
     * @param int $personid 人物 ID
     * @param string $resourceType 資源類型
     * @param array $conditions 查詢條件（原始主鍵）
     * @param string|null $legacyId 舊格式的編碼主鍵字串（用於重定向，若為 null 則使用查詢參數模式）
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function doProposalUpdate(Request $request, $personid, $resourceType, array $conditions, $legacyId) {
        $config = $this->getResourceConfig($resourceType);
        $table = $config['table'];
        $keyColumns = $config['key_columns'];

        $originalRow = $this->fetchRowByKeys($table, $keyColumns, $conditions);

        if (!$originalRow) {
            flash('提案失敗：找不到對應的資料列。', 'error');

            return redirect()->back()->withInput();
        }

        // 提取表單數據
        $formData = $this->extractFormData($request);
        [$payload, $auxiliaryPayload] = $this->splitFormDataByTable($table, $formData);
        $payload['c_personid'] = $personid;
        $payload = $this->normalizePayloadForTable($table, $payload);

        // 檢查是否有實際修改
        $diff = $this->operationRepository->getArrDiff($payload, $originalRow, $originalRow);
        if ($diff === null && !$this->hasAuxiliaryChanges($table, $conditions, $originalRow, $auxiliaryPayload)) {
            flash('提案失敗：未偵測到任何修改內容。', 'warning');

            return redirect()->back()->withInput();
        }

        // 構建提案元數據
        $meta = $this->buildProposalMeta('update', $resourceType, $request);

        // 記錄提案操作
        $operation = $this->recordProposalOperation(
            Operation::TYPE_PROPOSAL_UPDATE,
            $table,
            $keyColumns,
            $personid,
            $payload,
            $originalRow,
            $meta,
            $auxiliaryPayload
        );

        if ($operation) {
            flash('已提交修改提案，等待管理員審核 @ ' . Carbon::now(), 'info');
        }

        // 針對 biogmain (基本資料) 的特殊處理，不使用查詢參數模式
        if ($resourceType === 'biogmain') {
            return redirect()->route('basicinformation.edit', [
                'basicinformation' => $personid,
            ]);
        }

        // 根據是否有舊格式 ID 決定重定向方式
        if ($legacyId !== null) {
            // 舊格式：使用路徑參數
            return redirect()->route($config['route_prefix'] . '.edit', [
                'basicinformation' => $personid,
                Str::singular($resourceType) => $legacyId,
            ]);
        } else {
            // 新格式：使用查詢參數（重定向回原始記錄，使用原始 PK）
            $queryParams = [];
            foreach ($keyColumns as $column) {
                $queryParams[$column] = $conditions[$column] ?? '';
            }

            return redirect()->route($config['route_prefix'] . '.edit.query', [
                'id' => $personid,
            ] + $queryParams);
        }
    }

    /**
     * 獲取資源配置
     */
    protected function getResourceConfig($resourceType) {
        if (!isset($this->resourceConfigs[$resourceType])) {
            abort(404, "未知的資源類型：{$resourceType}");
        }

        return $this->resourceConfigs[$resourceType];
    }

    /**
     * 構建提案元數據
     */
    protected function buildProposalMeta($action, $resourceType, Request $request) {
        $config = $this->getResourceConfig($resourceType);

        return [
            'action' => $action,
            'resource_type' => $resourceType,
            'table' => $config['table'],
            'display_name' => $config['display_name'],
            'submitted_by' => Auth::user()->name ?? Auth::id(),
            'submitted_by_id' => Auth::id(),
            'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'comment' => $request->input('__proposal_comment', ''),
        ];
    }

    /**
     * 記錄提案操作
     */
    protected function recordProposalOperation(
        $opType,
        $table,
        $keyColumns,
        $personId,
        $data,
        $original,
        $meta,
        array $auxiliaryPayload = []
    ) {
        $resourceId = $this->buildCompositeId($keyColumns, $data, $table);

        $resourceData = $data;
        if ($auxiliaryPayload !== []) {
            $resourceData['__proposal_aux'] = $auxiliaryPayload;
        }
        $resourceData['__proposal_meta'] = $meta;
        $resourceData['__review_status'] = 'pending';
        $resourceData['__key_columns'] = $keyColumns;

        return $this->operationRepository->store(
            Auth::id(),
            $personId,
            $opType,
            $table,
            $resourceId,
            $resourceData,
            $original
        );
    }

    /**
     * 構建複合主鍵 ID
     *
     * 使用 query-string 格式（http_build_query），與 CompositePrimaryKey::buildStoredResourceId() 一致，
     * 所有特殊字符（中文、連字符等）自動 URL 編碼，消除舊 dash 分隔符的解析歧義。
     */
    protected function buildCompositeId($keyColumns, $data, ?string $table = null) {
        $pk = [];
        foreach ($keyColumns as $column) {
            $value = $data[$column] ?? null;
            if ($value === '' && !$this->shouldPreserveEmptyKeyValue($table, $column)) {
                $value = null;
            }
            $pk[$column] = $value;
        }

        return CompositePrimaryKey::buildStoredResourceId($pk);
    }

    /**
     * 解析複合主鍵 ID
     *
     * 支援兩種格式：
     * - query-string 格式（新）：c_personid=1&c_alt_name_chn=%E5%BC%B5%E4%B8%89&c_alt_name_type_code=10
     * - dash 分隔格式（舊，@deprecated）：1-張三-10
     */
    protected function parseCompositeId($id, $keyColumns) {
        // 嘗試 query-string 解析：若所有 keyColumn 都出現在結果中，視為 query-string 格式
        parse_str($id, $parsed);
        $allKeysPresent = true;
        foreach ($keyColumns as $col) {
            if (!array_key_exists($col, $parsed)) {
                $allKeysPresent = false;

                break;
            }
        }

        if ($allKeysPresent) {
            $conditions = [];
            foreach ($keyColumns as $column) {
                $value = $parsed[$column];
                if ($value === 'NULL') {
                    $value = null;
                }
                $conditions[$column] = $value;
            }

            return $conditions;
        }

        // 舊格式：dash 分隔
        $id = $this->biogMainRepository->unionPKDef_decode($id);

        $parts = explode('-', $id);

        $conditions = [];
        foreach ($keyColumns as $index => $column) {
            if (!isset($parts[$index])) {
                throw new \Exception("主鍵解析失敗：缺少 {$column}");
            }

            $value = $parts[$index];
            // 還原特殊字符
            $value = str_replace('minus', '-', $value);
            // 處理 NULL
            if ($value === 'NULL') {
                $value = null;
            }

            $conditions[$column] = $value;
        }

        return $conditions;
    }

    /**
     * 提取表單數據
     */
    protected function extractFormData(Request $request) {
        return Arr::except($request->all(), ['_token', '_method', 'action', '__proposal_comment']);
    }

    protected function splitFormDataByTable(string $table, array $payload): array {
        if (!Schema::hasTable($table)) {
            return [$payload, []];
        }

        $columns = array_flip($this->getTableColumns($table));
        $data = [];
        $auxiliary = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                $data[$key] = $value;

                continue;
            }

            if (isset($columns[$key])) {
                $data[$key] = $value;
            } else {
                $auxiliary[$key] = $value;
            }
        }

        return [$data, $auxiliary];
    }

    protected function normalizePayloadForTable(string $table, array $payload): array {
        if ($table === 'BIOG_SOURCE_DATA') {
            $payload['c_pages'] = (string) ($payload['c_pages'] ?? '');

            if (array_key_exists('c_textid', $payload) && $payload['c_textid'] !== null && $payload['c_textid'] !== '') {
                $payload['c_textid'] = (int) $payload['c_textid'] === -999 ? 0 : (int) $payload['c_textid'];
            }
        }

        // ASSOC_DATA 的 c_text_title 統一用 '[n/a]' 作為「未知出處」哨兵，
        // c_assoc_first_year 統一用 '-9999' 作為「未知年份」哨兵。
        // 兩者皆為 9-key 複合主鍵的一部分，提案與直接寫入需維持同一慣例。
        if ($table === 'ASSOC_DATA') {
            $payload['c_text_title'] = CompositePrimaryKey::emptyToSentinel($payload['c_text_title'] ?? null, '[n/a]');
            $payload['c_assoc_first_year'] = CompositePrimaryKey::emptyToSentinel($payload['c_assoc_first_year'] ?? null, '-9999');
        }

        return $payload;
    }

    protected function shouldPreserveEmptyKeyValue(?string $table, string $column): bool {
        return $table === 'BIOG_SOURCE_DATA' && $column === 'c_pages';
    }

    protected function hasAuxiliaryChanges(string $table, array $conditions, array $originalRow, array $auxiliaryPayload): bool {
        if ($auxiliaryPayload === []) {
            return false;
        }

        return match ($table) {
            'POSTED_TO_OFFICE_DATA' => $this->hasOfficeAddressChanges($conditions, $originalRow, $auxiliaryPayload),
            'EVENTS_DATA' => $this->hasEventAddressChanges($conditions, $originalRow, $auxiliaryPayload),
            'KIN_DATA' => $this->hasKinshipPairChanges($originalRow, $auxiliaryPayload),
            'ASSOC_DATA' => $this->hasAssocPairChanges($originalRow, $auxiliaryPayload),
            default => false,
        };
    }

    protected function hasOfficeAddressChanges(array $conditions, array $originalRow, array $auxiliaryPayload): bool {
        $incomingAddr = array_key_exists('c_addr', $auxiliaryPayload)
            ? (array) $auxiliaryPayload['c_addr']
            : (($auxiliaryPayload['c_addr_cleared'] ?? '0') === '1' ? [] : null);

        if ($incomingAddr === null) {
            return false;
        }

        $currentAddr = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $originalRow['c_personid'] ?? $conditions['c_personid'] ?? null)
            ->where('c_posting_id', $originalRow['c_posting_id'] ?? $conditions['c_posting_id'] ?? null)
            ->pluck('c_addr_id')
            ->all();

        return $this->normalizeSelectionList($incomingAddr) !== $this->normalizeSelectionList($currentAddr);
    }

    protected function hasEventAddressChanges(array $conditions, array $originalRow, array $auxiliaryPayload): bool {
        if (!array_key_exists('c_addr_id', $auxiliaryPayload)) {
            return false;
        }

        $incomingAddr = (array) $auxiliaryPayload['c_addr_id'];
        $currentAddr = DB::table('EVENTS_ADDR')
            ->where('c_personid', $originalRow['c_personid'] ?? $conditions['c_personid'] ?? null)
            ->where('c_sequence', $originalRow['c_sequence'] ?? $conditions['c_sequence'] ?? null)
            ->where('c_event_code', $originalRow['c_event_code'] ?? $conditions['c_event_code'] ?? null)
            ->pluck('c_addr_id')
            ->all();

        return $this->normalizeSelectionList($incomingAddr) !== $this->normalizeSelectionList($currentAddr);
    }

    protected function hasKinshipPairChanges(array $originalRow, array $auxiliaryPayload): bool {
        if (!array_key_exists('c_kinship_pair', $auxiliaryPayload)) {
            return false;
        }

        // #87：kin 鏡像的身分不含 c_autogen_notes；proposal 的「是否改了 pair」判斷需與 direct/proposal approve
        // 共用同一套反向定位語義，避免因 autogen 不對稱把既有同段鏡像誤判成缺邊/已變更。
        $currentPairs = app(\App\Services\RelationshipMirrorService::class)
            ->locateOppositeEdges('kinship', [
                'person_id' => $originalRow['c_personid'] ?? null,
                'opposite_id' => $originalRow['c_kin_id'] ?? null,
                'autogen_notes' => $originalRow['c_autogen_notes'] ?? null,
                'forward_code' => $originalRow['c_kin_code'] ?? null,
            ])
            ->pluck('c_kin_code')
            ->map(static fn ($code) => (int) $code)
            ->all();

        return !in_array((int) ($auxiliaryPayload['c_kinship_pair'] ?? 0), $currentPairs, true);
    }

    protected function hasAssocPairChanges(array $originalRow, array $auxiliaryPayload): bool {
        $pairKeys = ['c_assocship_pair', 'c_kinship_pair', 'c_assoc_kinship_pair'];
        if (array_intersect($pairKeys, array_keys($auxiliaryPayload)) === []) {
            return false;
        }

        $mirrorRow = DB::table('ASSOC_DATA')
            ->where('c_personid', $originalRow['c_assoc_id'] ?? null)
            ->where('c_assoc_id', $originalRow['c_personid'] ?? null)
            ->where('c_text_title', $originalRow['c_text_title'] ?? '')
            ->where('c_assoc_first_year', $originalRow['c_assoc_first_year'] ?? '-9999')
            ->first();

        $current = [
            'c_assocship_pair' => (int) ($mirrorRow->c_assoc_code ?? 0),
            'c_kinship_pair' => (int) ($mirrorRow->c_kin_code ?? 0),
            'c_assoc_kinship_pair' => (int) ($mirrorRow->c_assoc_kin_code ?? 0),
        ];

        foreach ($pairKeys as $key) {
            if (array_key_exists($key, $auxiliaryPayload) && (int) $auxiliaryPayload[$key] !== $current[$key]) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeSelectionList(array $values): array {
        $normalized = array_map(static function ($value) {
            $value = (int) $value;

            return $value === -999 ? 0 : $value;
        }, $values);

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    protected function getTableColumns(string $table): array {
        if (!array_key_exists($table, $this->tableColumnCache)) {
            $this->tableColumnCache[$table] = Schema::getColumnListing($table);
        }

        return $this->tableColumnCache[$table];
    }

    /**
     * 檢查主鍵完整性
     */
    protected function hasPrimaryKeyValues($keyColumns, $row, array $optionalKeyColumns = []) {
        foreach ($keyColumns as $column) {
            if (in_array($column, $optionalKeyColumns, true)) {
                continue;
            }
            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 從資料表讀取行
     */
    protected function fetchRowByKeys($table, $keyColumns, $conditions) {
        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }
        $row = $query->first();

        return $row ? (array) $row : null;
    }

    /**
     * 構建查詢條件
     */
    protected function buildConditionsFromRow($keyColumns, $row) {
        $conditions = [];
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new \RuntimeException("缺少主鍵欄位 {$column}");
            }
            $conditions[$column] = $row[$column];
        }

        return $conditions;
    }

    /**
     * 檢查是否有待審核的提案衝突
     *
     * 注意：不使用 JSON_EXTRACT 直接在 SQL 中比較，因為 MySQL/MariaDB 的 JSON_EXTRACT
     * 返回帶引號的 JSON 字符串（如 "pending"），需要 JSON_UNQUOTE 才能正確比較。
     * 為了跨資料庫兼容性（SQLite/MySQL），採用在 PHP 端解析 JSON 的方式。
     */
    protected function hasActiveProposalConflict($table, $keyColumns, $data, $opType) {
        if (count($keyColumns) === 1) {
            return false;
        }

        // 新格式（query-string）
        $resourceId = $this->buildCompositeId($keyColumns, $data, $table);
        $candidateIds = [$resourceId];

        if ($table === 'BIOG_SOURCE_DATA' && (($data['c_pages'] ?? '') === '')) {
            $legacyNullEncoded = $data;
            $legacyNullEncoded['c_pages'] = null;
            $candidateIds[] = $this->buildCompositeId($keyColumns, $legacyNullEncoded, null);
        }

        // 舊格式（dash 分隔 + bare minus 編碼），用於匹配歷史 pending 提案
        $legacyParts = [];
        foreach ($keyColumns as $column) {
            $value = $data[$column] ?? null;
            $legacyParts[] = ($value === null || $value === '')
                ? 'NULL'
                : str_replace('-', 'minus', (string) $value);
        }
        $legacyResourceId = implode('-', $legacyParts);

        if ($legacyResourceId !== $resourceId) {
            $candidateIds[] = $legacyResourceId;
        }

        $operations = Operation::where('resource', $table)
            ->whereIn('resource_id', $candidateIds)
            ->where('op_type', $opType)
            ->get();

        return $operations->contains(function (Operation $operation) {
            $payload = json_decode($operation->resource_data, true);
            $status = is_array($payload) ? ($payload['__review_status'] ?? null) : null;

            return $status === 'pending';
        });
    }

    /**
     * 確保用戶可以提案
     */
    protected function ensureCanPropose() {
        if (!Auth::check()) {
            abort(403, '請登入後提交提案');
        }

        if (!Auth::user()->isActive()) {
            abort(403, '該用戶沒有權限，請聯絡管理員');
        }
    }

    /**
     * 確保用戶可以直接儲存
     */
    protected function ensureCanDirectSave() {
        if (!Auth::check()) {
            abort(403, '請登入後編輯');
        }

        if (!Auth::user()->canWriteDirectly()) {
            abort(403, '該用戶沒有權限，請聯絡管理員');
        }
    }

    protected function assignSingleNumericPrimaryKeyIfNeeded(string $table, array $keyColumns, array $payload): array {
        if (count($keyColumns) !== 1) {
            return $payload;
        }

        $keyColumn = $keyColumns[0];
        $keyValue = $payload[$keyColumn] ?? null;
        if ($keyValue !== null && $keyValue !== '') {
            return $payload;
        }

        try {
            $max = DB::table($table)->max($keyColumn);
        } catch (\Throwable $e) {
            return $payload;
        }

        if ($max === null) {
            $payload[$keyColumn] = 1;

            return $payload;
        }

        if (is_numeric($max)) {
            $payload[$keyColumn] = (int) $max + 1;
        }

        return $payload;
    }
}
