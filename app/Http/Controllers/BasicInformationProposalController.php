<?php

namespace App\Http\Controllers;

use App\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BasicInformationProposalController extends Controller
{
    protected $biogMainRepository;
    protected $operationRepository;

    /**
     * 資源配置數組
     * 定義每個資源類型的表名、主鍵、控制器等信息
     */
    protected $resourceConfigs = [
        'altnames' => [
            'table' => 'ALTNAME_DATA',
            'key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
            'controller' => 'BasicInformationAltnamesController',
            'route_prefix' => 'basicinformation.altnames',
            'display_name' => '別名',
        ],
        'addresses' => [
            'table' => 'BIOG_ADDR_DATA',
            'key_columns' => ['c_personid', 'c_addr_id', 'c_sequence', 'c_addr_type'],
            'controller' => 'BasicInformationAddressesController',
            'route_prefix' => 'basicinformation.addresses',
            'display_name' => '地址',
        ],
        'texts' => [
            'table' => 'BIOG_TEXT_DATA',
            'key_columns' => ['c_personid', 'c_textid', 'c_year_year', 'c_text_role_code'],
            'controller' => 'BasicInformationTextsController',
            'route_prefix' => 'basicinformation.texts',
            'display_name' => '著述',
        ],
        'statuses' => [
            'table' => 'STATUS_DATA',
            'key_columns' => ['c_personid', 'c_status_code', 'c_sequence'],
            'controller' => 'BasicInformationStatusesController',
            'route_prefix' => 'basicinformation.statuses',
            'display_name' => '身份',
        ],
        'possessions' => [
            'table' => 'POSSESSION_DATA',
            'key_columns' => ['c_personid', 'c_poss_code', 'c_sequence'],
            'controller' => 'BasicInformationPossessionController',
            'route_prefix' => 'basicinformation.possession',
            'display_name' => '所有物',
        ],
        // 其他資源配置待後續添加
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
    public function proposalStore(Request $request, $personid, $resourceType)
    {
        $this->ensureCanPropose();

        $config = $this->getResourceConfig($resourceType);
        $table = $config['table'];
        $keyColumns = $config['key_columns'];

        // 提取表單數據
        $payload = $this->extractFormData($request);
        $payload['c_personid'] = $personid;

        // 驗證主鍵完整性
        if (!$this->hasPrimaryKeyValues($keyColumns, $payload)) {
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
            $meta
        );

        if ($operation) {
            flash('已提交新增提案，等待管理員審核 @ ' . Carbon::now(), 'info');
        }

        return redirect()->route($config['route_prefix'] . '.index', [
            'basicinformation' => $personid,
        ]);
    }

    /**
     * 提交修改提案（子資源）
     */
    public function proposalUpdate(Request $request, $personid, $resourceType, $id)
    {
        $this->ensureCanPropose();

        $config = $this->getResourceConfig($resourceType);
        $table = $config['table'];
        $keyColumns = $config['key_columns'];

        // 解析複合主鍵
        $conditions = $this->parseCompositeId($id, $keyColumns);
        $originalRow = $this->fetchRowByKeys($table, $keyColumns, $conditions);

        if (!$originalRow) {
            flash('提案失敗：找不到對應的資料列。', 'error');
            return redirect()->back()->withInput();
        }

        // 提取表單數據
        $payload = $this->extractFormData($request);
        $payload['c_personid'] = $personid;

        // 檢查是否有實際修改
        $diff = $this->operationRepository->getArrDiff($payload, $originalRow, $originalRow);
        if ($diff === null) {
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
            $meta
        );

        if ($operation) {
            flash('已提交修改提案，等待管理員審核 @ ' . Carbon::now(), 'info');
        }

        return redirect()->route($config['route_prefix'] . '.edit', [
            'basicinformation' => $personid,
            Str::singular($resourceType) => $id,
        ]);
    }

    /**
     * 獲取資源配置
     */
    protected function getResourceConfig($resourceType)
    {
        if (!isset($this->resourceConfigs[$resourceType])) {
            abort(404, "未知的資源類型：{$resourceType}");
        }

        return $this->resourceConfigs[$resourceType];
    }

    /**
     * 構建提案元數據
     */
    protected function buildProposalMeta($action, $resourceType, Request $request)
    {
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
        $meta
    ) {
        $resourceId = $this->buildCompositeId($keyColumns, $data);

        $resourceData = $data;
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
     */
    protected function buildCompositeId($keyColumns, $data)
    {
        $parts = [];
        foreach ($keyColumns as $column) {
            $value = $data[$column] ?? '';
            // 處理 NULL 值
            if ($value === null || $value === '') {
                $value = 'NULL';
            }
            // 處理特殊字符（連字符）
            $value = str_replace('-', 'minus', (string) $value);
            $parts[] = $value;
        }
        return implode('-', $parts);
    }

    /**
     * 解析複合主鍵 ID
     */
    protected function parseCompositeId($id, $keyColumns)
    {
        // 使用現有的 unionPKDef_decode
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
    protected function extractFormData(Request $request)
    {
        return Arr::except($request->all(), ['_token', '_method', 'action', '__proposal_comment']);
    }

    /**
     * 檢查主鍵完整性
     */
    protected function hasPrimaryKeyValues($keyColumns, $row)
    {
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 從資料表讀取行
     */
    protected function fetchRowByKeys($table, $keyColumns, $conditions)
    {
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
    protected function buildConditionsFromRow($keyColumns, $row)
    {
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
     */
    protected function hasActiveProposalConflict($table, $keyColumns, $data, $opType)
    {
        $resourceId = $this->buildCompositeId($keyColumns, $data);

        return Operation::where('resource', $table)
            ->where('resource_id', $resourceId)
            ->where('op_type', $opType)
            ->whereRaw("JSON_EXTRACT(resource_data, '$.__review_status') = 'pending'")
            ->exists();
    }

    /**
     * 確保用戶可以提案
     */
    protected function ensureCanPropose()
    {
        if (!Auth::check()) {
            abort(403, '請登入後提交提案');
        }

        if (!Auth::user()->isActive()) {
            abort(403, '該用戶沒有權限，請聯繫管理員');
        }
    }

    /**
     * 確保用戶可以直接儲存
     */
    protected function ensureCanDirectSave()
    {
        if (!Auth::check()) {
            abort(403, '請登入後編輯');
        }

        if (!Auth::user()->canWriteDirectly()) {
            abort(403, '該用戶沒有權限，請聯繫管理員');
        }
    }
}
