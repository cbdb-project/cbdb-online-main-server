<?php

namespace App\Services;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 为自然语言查询提供工具方法
 * 这些工具可以被 LLM 调用以获取额外的上下文信息
 */
class NlQueryToolsService {
    protected DatabaseSchemaService $schemaService;
    protected ?ReadOnlyTableQueryService $readOnlyService;

    public function __construct(DatabaseSchemaService $schemaService, ?ReadOnlyTableQueryService $readOnlyService = null) {
        $this->schemaService = $schemaService;
        $this->readOnlyService = $readOnlyService;
    }

    protected function readOnlyService(): ReadOnlyTableQueryService {
        if ($this->readOnlyService instanceof ReadOnlyTableQueryService) {
            return $this->readOnlyService;
        }

        $this->readOnlyService = app(ReadOnlyTableQueryService::class);

        return $this->readOnlyService;
    }

    /**
     * 获取表格的 schema 信息
     *
     * @param string $tableName 表名
     * @return array ['success' => bool, 'schema' => array|null, 'error' => string|null]
     */
    public function getTableSchema(string $tableName): array {
        // 验证表名是否在白名单中
        $allowedTables = array_keys(config('codes.tables', []));

        $isAllowed = false;
        foreach ($allowedTables as $allowed) {
            if (strcasecmp($allowed, $tableName) === 0) {
                $isAllowed = true;
                $tableName = $allowed; // 使用白名单中的原始大小写

                break;
            }
        }

        if (!$isAllowed) {
            return [
                'success' => false,
                'schema' => null,
                'error' => "表格 '{$tableName}' 不在允許的表格清單中",
            ];
        }

        try {
            // 使用 DatabaseSchemaService 获取 schema
            $schema = $this->schemaService->getTableSchema($tableName);

            if (isset($schema['error'])) {
                return [
                    'success' => false,
                    'schema' => $schema,
                    'error' => $schema['error'],
                ];
            }

            return [
                'success' => true,
                'schema' => $schema,
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::warning("获取表格 schema 失败: {$tableName}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'schema' => null,
                'error' => "獲取表格結構時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 获取表格的样例数据
     *
     * @param string $tableName 表名
     * @param int $limit 返回的记录数（默认 10）
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function getSampleDataForTable(string $tableName, int $limit = 10): array {
        // 验证表名是否在白名单中
        $allowedTables = array_keys(config('codes.tables', []));

        $isAllowed = false;
        foreach ($allowedTables as $allowed) {
            if (strcasecmp($allowed, $tableName) === 0) {
                $isAllowed = true;
                $tableName = $allowed; // 使用白名单中的原始大小写

                break;
            }
        }

        if (!$isAllowed) {
            return [
                'success' => false,
                'data' => null,
                'error' => "表格 '{$tableName}' 不在允許的表格清單中",
            ];
        }

        try {
            // 获取样例数据（前 N 条）
            $data = DB::table($tableName)
                ->limit($limit)
                ->get()
                ->toArray();

            // 转换为关联数组格式，便于 JSON 序列化
            $formattedData = array_map(function ($row) {
                return (array) $row;
            }, $data);

            return [
                'success' => true,
                'data' => $formattedData,
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::warning("获取表格样例数据失败: {$tableName}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "獲取樣例數據時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 获取代码表的值
     *
     * @param string $codeType 代码类型（如 'dynasties', 'sex', 'entry_codes' 等）
     * @param int $limit 返回的记录数（默认 50）
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function getCodeValues(string $codeType, int $limit = 50): array {
        // 代码类型映射到表名和字段
        $codeTypeMap = [
            'dynasties' => ['table' => 'DYNASTIES', 'id' => 'c_dy', 'name' => 'c_dynasty_chn', 'order' => 'c_sort'],
            'sex' => ['table' => 'BIOG_MAIN', 'id' => 'c_female', 'name' => 'c_female', 'distinct' => true],
            'entry_codes' => ['table' => 'ENTRY_CODES', 'id' => 'c_entry_code', 'name' => 'c_entry_desc'],
            'kinship_codes' => ['table' => 'KINSHIP_CODES', 'id' => 'c_kincode', 'name' => 'c_kinrel_chn'],
            'status_codes' => ['table' => 'STATUS_CODES', 'id' => 'c_status_code', 'name' => 'c_status_desc'],
            'address_types' => ['table' => 'BIOG_ADDR_CODES', 'id' => 'c_addr_type', 'name' => 'c_addr_desc_chn'],
        ];

        if (!isset($codeTypeMap[$codeType])) {
            return [
                'success' => false,
                'data' => null,
                'error' => "未知的代碼類型: {$codeType}。可用類型: " . implode(', ', array_keys($codeTypeMap)),
            ];
        }

        $config = $codeTypeMap[$codeType];

        try {
            $query = DB::table($config['table']);

            // 如果需要去重（如性别字段）
            if (isset($config['distinct']) && $config['distinct']) {
                $query->distinct();
            }

            // 选择字段
            $fields = [$config['id']];
            if ($config['id'] !== $config['name']) {
                $fields[] = $config['name'];
            }
            $query->select($fields);

            // 如果有排序字段，使用排序
            if (isset($config['order'])) {
                $query->orderBy($config['order']);
            }

            // 限制结果数量
            $data = $query->limit($limit)->get()->toArray();

            // 转换为关联数组格式
            $formattedData = array_map(function ($row) {
                return (array) $row;
            }, $data);

            return [
                'success' => true,
                'data' => $formattedData,
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::warning("获取代码值失败: {$codeType}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "獲取代碼值時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 根据人名搜索人物 ID
     *
     * @param string $personName 人名（支持模糊匹配）
     * @param int $limit 返回的记录数（默认 20）
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function getPersonIds(string $personName, int $limit = 20): array {
        if (empty($personName)) {
            return [
                'success' => false,
                'data' => null,
                'error' => '人名不能為空',
            ];
        }

        try {
            // 使用现有的 /api/name 后端逻辑（BiogMainRepository::namesByQuery）
            $request = new \Illuminate\Http\Request();
            $request->merge(['q' => $personName, 'num' => $limit]);

            $paginator = \App\Repositories\BiogMainRepository::namesByQuery($request, $limit);

            // 从 Paginator 中提取数据并缩减字段，避免返回模型内部结构
            $data = $paginator->items();
            $formattedData = array_map(function ($row) {
                return [
                    'c_personid' => data_get($row, 'c_personid'),
                    'c_name_chn' => data_get($row, 'c_name_chn'),
                    'c_name' => data_get($row, 'c_name'),
                    'c_dynasty_chn' => data_get($row, 'c_dynasty_chn'),
                    'c_index_year' => data_get($row, 'c_index_year'),
                    'ADDR_c_name_chn' => data_get($row, 'ADDR_c_name_chn'),
                    'c_alt_name_chn_zi' => data_get($row, 'c_alt_name_chn_zi'),
                    'c_alt_name_chn_hao' => data_get($row, 'c_alt_name_chn_hao'),
                ];
            }, $data);

            return [
                'success' => true,
                'data' => $formattedData,
                'count' => count($formattedData),
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::warning("搜索人名失败: {$personName}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "搜索人名時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 列出所有可查詢白名單表格（與 MCP list_allowed_tables 對齊）
     *
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function listAllowedTables(): array {
        try {
            return [
                'success' => true,
                'data' => $this->readOnlyService()->listAllowedTables(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('列出允許表格失敗', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "列出允許表格時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 獲取完整表格結構（欄位、索引、外鍵、metadata）
     *
     * @param string $tableName
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function queryTableSchema(string $tableName): array {
        try {
            return [
                'success' => true,
                'data' => $this->readOnlyService()->queryTableSchema($tableName),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("查詢表格結構失敗: {$tableName}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "查詢表格結構時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 查詢指定表格（可帶 filters/columns/limit/offset）
     *
     * @param string $tableName
     * @param array|string|null $filters
     * @param array|string|null $columns
     * @param int $limit
     * @param int $offset
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function queryTable(string $tableName, array|string|null $filters = null, array|string|null $columns = null, int $limit = 10, int $offset = 0): array {
        try {
            return [
                'success' => true,
                'data' => $this->readOnlyService()->queryTable($tableName, $filters, $columns, $limit, $offset),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("查詢表格資料失敗: {$tableName}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "查詢表格資料時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 依照主鍵欄位抓取單筆資料
     *
     * @param string $tableName
     * @param string $idColumn
     * @param int|string|float|bool|null $idValue
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function getTableRowById(string $tableName, string $idColumn, int|string|float|bool|null $idValue): array {
        try {
            return [
                'success' => true,
                'data' => $this->readOnlyService()->getTableRowById($tableName, $idColumn, $idValue),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("依主鍵抓取單筆資料失敗: {$tableName}.{$idColumn}", [
                'id_value' => $idValue,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "依主鍵抓取單筆資料時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 執行只讀 SQL（SELECT / WITH）
     *
     * @param string $sql
     * @param int $limit
     * @param int $offset
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function queryReadOnlySql(string $sql, int $limit = 20, int $offset = 0): array {
        try {
            return [
                'success' => true,
                'data' => $this->readOnlyService()->queryReadOnlySql($sql, $limit, $offset),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('執行只讀 SQL 失敗', [
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => "執行只讀 SQL 時發生錯誤: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 获取所有可用的工具定义（用于 LLM function calling）
     *
     * @return array
     */
    public function getToolDefinitions(): array {
        return config('nl_query_tools.tools', []);
    }

    /**
     * 执行工具调用
     *
     * @param string $toolName 工具名称
     * @param array $arguments 工具参数
     * @return array
     */
    public function executeTool(string $toolName, array $arguments): array {
        switch ($toolName) {
            case 'list_allowed_tables':
                return $this->listAllowedTables();

            case 'get_table_schema':
                return $this->getTableSchema(
                    $arguments['table_name'] ?? ''
                );

            case 'query_table_schema':
                return $this->queryTableSchema(
                    $arguments['table_name'] ?? ''
                );

            case 'get_sample_data_for_table':
                return $this->getSampleDataForTable(
                    $arguments['table_name'] ?? '',
                    $arguments['limit'] ?? 10
                );

            case 'query_table':
                return $this->queryTable(
                    $arguments['table_name'] ?? '',
                    $arguments['filters'] ?? null,
                    $arguments['columns'] ?? null,
                    (int) ($arguments['limit'] ?? 10),
                    (int) ($arguments['offset'] ?? 0)
                );

            case 'get_table_row_by_id':
                return $this->getTableRowById(
                    $arguments['table_name'] ?? '',
                    $arguments['id_column'] ?? '',
                    $arguments['id_value'] ?? null
                );

            case 'query_read_only_sql':
                return $this->queryReadOnlySql(
                    $arguments['sql'] ?? '',
                    (int) ($arguments['limit'] ?? 20),
                    (int) ($arguments['offset'] ?? 0)
                );

            case 'get_code_values':
                return $this->getCodeValues(
                    $arguments['code_type'] ?? '',
                    $arguments['limit'] ?? 50
                );

            case 'get_person_ids':
                return $this->getPersonIds(
                    $arguments['person_name'] ?? '',
                    $arguments['limit'] ?? 20
                );

            default:
                return [
                    'success' => false,
                    'data' => null,
                    'error' => "未知的工具: {$toolName}",
                ];
        }
    }
}
