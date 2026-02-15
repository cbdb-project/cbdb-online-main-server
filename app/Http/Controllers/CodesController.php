<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CodesController extends Controller {
    protected $codesrepostory;
    protected $operationRepository;

    protected $allowedTables = [];
    protected $allowedTablesMap = [];
    /**
     * Cache of dynasty ID to name mapping for current request.
     *
     * @var array<int|string, string>|null
     */
    protected $dynastyNameMap = null;
    /**
     * Tables treated as read-only within the generic codes UI.
     *
     * @var array<int, string>
     */
    protected $readOnlyTables = [
        'CBDB__NAME_FTS',
        'CBDB__TRAD_SIMP_MAP',
        'DYNASTIES',
        'GANZHI_CODES',
    ];
    /**
     * Copyright notices for specific tables.
     *
     * @var array<string, string>
     */
    protected $tableCopyrightNotes = [
        'CBDB__TRAD_SIMP_MAP' => '此表格數據來自 <a href="https://github.com/BYVoid/OpenCC" target="_blank">OpenCC 項目</a>的字典文件，該文件以 <a href="https://www.apache.org/licenses/LICENSE-2.0" target="_blank">Apache 2.0 License</a> 授權，因此這個表格的授權也是 Apache 2.0，而非 CC BY-NC-SA 4.0 International 授權。',
    ];
    /**
     * Custom column configurations for specific tables.
     *
     * @var array<string, array<int, string>>
     */
    protected $tableColumnOverrides = [
        'ADDR_BELONGS_DATA' => ['c_addr_id', 'c_belongs_to', 'c_firstyear', 'c_lastyear'],
        'ADDR_CODES' => ['c_addr_id', 'c_name_chn', 'c_name', 'c_firstyear', 'c_lastyear', 'c_admin_type'],
        'CBDB__NAME_FTS' => [
            'id',
            'c_personid',
            'name_type_code',
            'name_type_desc',
            'name_type_desc_chn',
            'search_term',
            'full_name',
            'source',
            'is_simplified',
        ],
        'CBDB__TRAD_SIMP_MAP' => ['trad_char', 'simp_char'],
        'ADDRESSES' => [
            'c_addr_id',
            'c_addr_cbd',
            'c_name',
            'c_name_chn',
            'c_admin_type',
            'c_firstyear',
            'c_lastyear',
            'x_coord',
            'y_coord',
            'belongs1_ID',
            'belongs1_Name',
            'belongs2_ID',
            'belongs2_Name',
            'belongs3_ID',
            'belongs3_Name',
            'belongs4_ID',
            'belongs4_Name',
            'belongs5_ID',
            'belongs5_Name',
        ],
        'ADMIN_CAT_CODES' => [
            'c_admin_cat_code',
            'c_admin_cat_py',
            'c_admin_cat_hz',
            'c_admin_cat_trans',
            'c_notes',
        ],
        'ADMIN_CAT_CODE_TYPE_REL' => [
            'c_admin_cat_code',
            'admin_cat_name',
            'c_admin_cat_type_code',
            'admin_cat_type_name',
        ],
        'APPOINTMENT_CODE_TYPE_REL' => [
            'c_appt_code',
            'appt_name',
            'c_appt_type_code',
            'appt_type_name',
        ],
        'ENTRY_CODE_TYPE_REL' => [
            'c_entry_code',
            'entry_name',
            'c_entry_type',
            'entry_type_name',
        ],
        'OFFICE_CODE_TYPE_REL' => [
            'c_office_id',
            'office_name',
            'c_office_tree_id',
            'office_type_name',
        ],
        'ASSOC_CODE_TYPE_REL' => [
            'c_assoc_code',
            'assoc_name',
            'c_assoc_type_code',
            'assoc_type_name',
        ],
        'STATUS_CODE_TYPE_REL' => [
            'c_status_code',
            'status_name',
            'c_status_type_code',
            'status_type_name',
        ],
        'TEXT_BIBLCAT_CODE_TYPE_REL' => [
            'c_text_cat_code',
            'text_cat_name',
            'c_text_cat_type_id',
            'text_cat_type_name',
        ],
        'DYNASTIES' => ['c_dy', 'c_dynasty_chn', 'c_dynasty', 'c_start', 'c_end', 'c_sort'],
        'TEXT_INSTANCE_DATA' => [
            'c_textid',
            'c_text_edition_id',
            'c_text_instance_id',
            'c_instance_title_chn',
            'c_publisher',
            'c_print',
            'c_instance_title',
        ],
        'MERGED_PERSON_DATA' => [
            'c_personid',
            'c_merged_from_personid',
            'c_notes',
            'c_source',
            'c_pages',
        ],
        'OFFICE_CODES' => [
            'c_office_id',
            'c_dy',
            'c_office_chn',
            'c_office_chn_alt',
            'c_office_trans',
        ],
        'ALTNAME_CODES' => ['c_name_type_code', 'c_name_type_desc_chn', 'c_name_type_desc'],
        'APPOINTMENT_CODES' => ['c_appt_code', 'c_appt_desc_chn', 'c_appt_desc'],
        'ASSOC_CODES' => [
            'c_assoc_code',
            'c_assoc_pair',
            'c_assoc_pair2',
            'c_assoc_desc',
            'c_assoc_desc_chn',
            'c_assoc_role_type',
            'c_sortorder',
            'c_example',
        ],
        'TEXT_CODES' => ['c_textid', 'c_title_chn', 'c_title'],
        'SOCIAL_INSTITUTION_CODES' => ['c_inst_name_code', 'c_inst_code', 'c_inst_type_code'],
    ];
    /**
     * JOIN configurations for relationship tables.
     *
     * @var array<string, array<string, mixed>>
     */
    protected $tableJoinConfigurations = [
        'ADMIN_CAT_CODE_TYPE_REL' => [
            'base_table' => 'ADMIN_CAT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'ADMIN_CAT_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_admin_cat_code', '=', 'code.c_admin_cat_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'ADMIN_CAT_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_admin_cat_type_code', '=', 'type.c_admin_cat_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_admin_cat_code',
                'code.c_admin_cat_hz as admin_cat_name',
                'rel.c_admin_cat_type_code',
                'type.c_admin_cat_type_hz as admin_cat_type_name',
            ],
        ],
        'APPOINTMENT_CODE_TYPE_REL' => [
            'base_table' => 'APPOINTMENT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'APPOINTMENT_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_appt_code', '=', 'code.c_appt_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'APPOINTMENT_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_appt_type_code', '=', 'type.c_appt_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_appt_code',
                'code.c_appt_desc_chn as appt_name',
                'rel.c_appt_type_code',
                'type.c_appt_type_desc_chn as appt_type_name',
            ],
        ],
        'ENTRY_CODE_TYPE_REL' => [
            'base_table' => 'ENTRY_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'ENTRY_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_entry_code', '=', 'code.c_entry_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'ENTRY_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_entry_type', '=', 'type.c_entry_type'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_entry_code',
                'code.c_entry_desc_chn as entry_name',
                'rel.c_entry_type',
                'type.c_entry_type_desc_chn as entry_type_name',
            ],
        ],
        'OFFICE_CODE_TYPE_REL' => [
            'base_table' => 'OFFICE_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'OFFICE_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_office_id', '=', 'code.c_office_id'],
                    'type' => 'left',
                ],
                [
                    'table' => 'OFFICE_TYPE_TREE',
                    'alias' => 'type',
                    'on' => ['rel.c_office_tree_id', '=', 'type.c_office_type_node_id'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_office_id',
                'code.c_office_chn as office_name',
                'rel.c_office_tree_id',
                'type.c_office_type_desc_chn as office_type_name',
            ],
        ],
        'ASSOC_CODE_TYPE_REL' => [
            'base_table' => 'ASSOC_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'ASSOC_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_assoc_code', '=', 'code.c_assoc_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'ASSOC_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_assoc_type_code', '=', 'type.c_assoc_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_assoc_code',
                'code.c_assoc_desc_chn as assoc_name',
                'rel.c_assoc_type_code',
                'type.c_assoc_type_desc_chn as assoc_type_name',
            ],
        ],
        'STATUS_CODE_TYPE_REL' => [
            'base_table' => 'STATUS_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'STATUS_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_status_code', '=', 'code.c_status_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'STATUS_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_status_type_code', '=', 'type.c_status_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_status_code',
                'code.c_status_desc_chn as status_name',
                'rel.c_status_type_code',
                'type.c_status_type_chn as status_type_name',
            ],
        ],
        'TEXT_BIBLCAT_CODE_TYPE_REL' => [
            'base_table' => 'TEXT_BIBLCAT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'TEXT_BIBLCAT_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_text_cat_code', '=', 'code.c_text_cat_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'TEXT_BIBLCAT_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_text_cat_type_id', '=', 'type.c_text_cat_type_id'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_text_cat_code',
                'code.c_text_cat_desc_chn as text_cat_name',
                'rel.c_text_cat_type_id',
                'type.c_text_cat_type_desc_chn as text_cat_type_name',
            ],
        ],
    ];
    /**
     * Explicit primary key definitions for code tables.
     *
     * @var array<string, array<int, string>>
     */
    protected $tablePrimaryKeyOverrides = [
        'CBDB__NAME_FTS' => ['id'],
        'CBDB__TRAD_SIMP_MAP' => ['trad_char'],
        'POSSESSION_DATA' => ['c_possession_record_id'],
        'TEXT_CODES' => ['c_textid'],
    ];
    /**
     * Table column listings cached per request.
     *
     * @var array<string, array<int, string>>
     */
    protected $tableColumnsCache = [];

    public function __construct(CodesRepository $codesRepository, OperationRepository $operationRepository) {
        $this->codesrepostory = $codesRepository;
        $this->operationRepository = $operationRepository;
        $this->allowedTables = $this->codesrepostory->allowedTables();

        // 直接从配置构建大小写映射，避免 SHOW TABLES 查询
        $this->allowedTablesMap = [];
        foreach ($this->allowedTables as $table) {
            $this->allowedTablesMap[strtoupper($table)] = $table;
        }
    }

    protected function guardTable(string $table): string {
        $key = strtoupper($table);
        if (!isset($this->allowedTablesMap[$key])) {
            abort(404);
        }

        return $this->allowedTablesMap[$key];
    }

    public function index() {
        $data = $this->codesrepostory->codes();

        return view('codes.index', [
            'page_title' => '全部表格',
            'page_description' => '全部表格',
            'page_url' => '/codes',
            'data' => $data,
        ]);
    }

    public function show(Request $request, $table_name) {
        $table = $this->guardTable($table_name);
        $search = trim((string) $request->query('search', ''));

        try {
            $perPage = config('codes.per_page', 20);
            $upperTable = strtoupper($table);

            // Check if this table needs JOIN
            $joinConfig = $this->tableJoinConfigurations[$upperTable] ?? null;
            if ($joinConfig) {
                $query = $this->buildJoinQuery($joinConfig);
            } else {
                $query = DB::table($table);
            }

            // 只在没有列配置时才查询样本行，避免不必要的数据库查询
            $hasColumnConfig = isset($this->tableColumnOverrides[$upperTable]);
            $sampleRow = $hasColumnConfig ? null : (clone $query)->first();

            $thead = $this->buildTableHead($table, $sampleRow);
            $searchableColumns = $this->determineSearchableColumns($table, $thead);

            // 使用游标分页的大表列表
            $cursorPaginationTables = ['CBDB__NAME_FTS'];
            $useCursorPagination = in_array(strtoupper($table), $cursorPaginationTables, true);

            if ($search !== '' && !empty($searchableColumns)) {
                $query->where(function ($subQuery) use ($searchableColumns, $search, $useCursorPagination, $joinConfig) {
                    foreach ($searchableColumns as $column) {
                        // 对于使用 JOIN 的表，需要将别名转换为原始表达式
                        $searchColumn = $column;
                        if ($joinConfig) {
                            $baseAlias = $joinConfig['base_alias'];
                            $selectList = $joinConfig['select'] ?? [];

                            // 查找该列是否是别名，如果是，提取原始表达式
                            $foundOriginalExpr = false;
                            foreach ($selectList as $selectExpr) {
                                if (strpos($selectExpr, ' as ' . $column) !== false) {
                                    // 提取 "expression as alias" 中的 expression
                                    $parts = explode(' as ', $selectExpr);
                                    if (count($parts) === 2) {
                                        $searchColumn = trim($parts[0]);
                                        $foundOriginalExpr = true;

                                        break;
                                    }
                                }
                            }

                            // 如果不是别名且没有表前缀，添加基表别名
                            if (!$foundOriginalExpr && !str_contains($column, '.')) {
                                $searchColumn = $baseAlias . '.' . $column;
                            }
                        }

                        // 对于游标分页的大表，使用前缀搜索以利用索引
                        if ($useCursorPagination) {
                            $subQuery->orWhere($searchColumn, 'like', $search . '%');
                        } else {
                            $subQuery->orWhere($searchColumn, 'like', '%' . $search . '%');
                        }
                    }
                });
            }

            if ($useCursorPagination) {
                return $this->showWithCursorPagination($request, $table, $query, $search, $perPage, $thead);
            }

            $data = $query->paginate($perPage)->appends(['search' => $search]);

            $dynastyMap = [];
            if (in_array('c_dy', $thead, true)) {
                $dynastyMap = $this->getDynastyNameMap();
            }

            $isReadOnly = $this->isReadOnlyTable($table);
            $keyColumns = $this->getKeyColumns($table);
            $copyrightNote = $this->tableCopyrightNotes[$table] ?? null;

            // 标记哪些列是通过 JOIN 获得的别名列
            $joinedColumns = [];
            if ($joinConfig) {
                $joinedColumns = $this->getJoinedColumnNames($joinConfig);
            }

            return view('codes.show', [
                'page_title' => $table,
                'page_description' => '',
                'page_url' => '/codes',
                'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li>",
                'q' => $table,
                'thead' => $thead,
                'data' => $data,
                'search' => $search,
                'dynastyMap' => $dynastyMap,
                'isReadOnly' => $isReadOnly,
                'keyColumns' => $keyColumns,
                'copyrightNote' => $copyrightNote,
                'joinedColumns' => $joinedColumns,
            ]);
        } catch (\PDOException $e) {
            flash('找不到该数据表', 'warning');

            return redirect()->back();
        }
    }

    public function edit($table_name, $id) {
        //        dd($table_name);
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        if ($table) {
            try {
                $keyColumns = $this->getKeyColumns($table);
                $conditions = $this->buildConditionsFromId($keyColumns, $id);

                $query = DB::table($table);
                foreach ($conditions as $column => $value) {
                    $query->where($column, $value);
                }
                $data = $query->first();

                if (!$data) {
                    flash('找不到该数据表', 'warning');

                    return redirect()->back();
                }

                $rowArray = $this->convertRowToArray($data);
                $rowArray = $this->orderAuditFieldsForDisplay($rowArray);
                $compositeId = $this->buildCompositeId($keyColumns, $rowArray);

                return view('codes.edit', [
                    'page_title' => '編輯',
                    'page_description' => '',
                    'page_url' => '/codes',
                    'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li><li class='breadcrumb-item'><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li>",
                    'id' => $compositeId, 'row' => $rowArray,
                    'table' => $table]);
            } catch (\PDOException $e) {
                flash('找不到该数据表', 'warning');

                return redirect()->back();
            }

        }

        return redirect()->route('codes.index');
    }

    public function update(Request $request, $table_name, $id) {
        $table = $this->guardTable($table_name);
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $keyColumns = $this->getKeyColumns($table);
        $conditions = $this->buildConditionsFromId($keyColumns, $id);
        $originalRow = $this->fetchRowByKeys($table, $keyColumns, $conditions);

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        $data = Arr::except($request->all(), ['_method', '_token', '__proposal_comment']);
        $data = $this->enforceAuditFieldsForUpdate($data, $originalRow ?: []);

        try {
            $query->update($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                flash('更新失敗：主鍵或唯一值已存在。', 'error');

                return redirect()->back()
                    ->withInput()
                    ->withErrors(['duplicate' => '更新失敗：主鍵或唯一值已存在。']);
            }

            throw $e;
        }

        $updatedRow = $this->fetchRowByKeys($table, $keyColumns, $conditions) ?: ($originalRow ? array_merge($originalRow, $data) : $data);

        $this->recordOperation(2, $table, $keyColumns, $updatedRow, $originalRow ?: []);

        flash('Update success @ '.Carbon::now(), 'success');

        $id = $this->buildCompositeId($keyColumns, $updatedRow);

        return redirect()->route('codes.edit', ['table_name' => $table, 'id' => $id]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設遮除的第1個欄位呈現。
    public function create($table_name) {
        //        dd($table_name);
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $columns = $this->getTableColumns($table);
        $keyColumns = $this->getKeyColumns($table);
        $columns = $this->orderColumnsForCreate($columns, $keyColumns);

        $defaults = [];
        $firstKey = $keyColumns[0] ?? null;
        if ($firstKey && in_array($firstKey, $columns, true)) {
            $nextValue = $this->guessNextKeyValue($table, $firstKey);
            if ($nextValue !== null) {
                $defaults[$firstKey] = $nextValue;
            }
        }

        $firstColumn = $columns[0] ?? null;
        $id = $firstColumn && isset($defaults[$firstColumn]) ? $defaults[$firstColumn] : null;

        return view('codes.create', [
            'page_title' => '新增',
            'page_description' => '',
            'page_url' => '/codes',
            'archer' => "<li class='breadcrumb-item'><a href='/codes'>Codes</a></li><li class='breadcrumb-item'><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li>",
            'row' => $columns,
            'id' => $id,
            'defaults' => $defaults,
            'table' => $table,
        ]);
    }

    public function proposalStore(Request $request, $table_name) {
        $table = $this->guardTable($table_name);
        if ($redirect = $this->ensureEditableAccess($table)) {
            return $redirect;
        }

        $payload = $this->extractFormData($request);
        $keyColumns = $this->getKeyColumns($table);

        if (!$this->hasPrimaryKeyValues($keyColumns, $payload)) {
            flash('提案失敗：請確認主鍵欄位已填寫完整。', 'error');

            return redirect()->back()->withInput();
        }

        $conditions = $this->buildConditionsFromRow($keyColumns, $payload);
        $existing = $this->fetchRowByKeys($table, $keyColumns, $conditions);
        if ($existing) {
            flash('提案失敗：資料已存在，請改用修改提案。', 'warning');

            return redirect()->back()->withInput();
        }

        if ($this->hasActiveCreateProposalConflict($table, $keyColumns, $payload)) {
            flash('提案失敗：已有其他新增提案使用相同主鍵，請調整後再提交。', 'warning');

            return redirect()->back()->withInput();
        }

        $meta = $this->buildProposalMeta('create', $table, $request);
        $operation = $this->recordProposalOperation(
            Operation::TYPE_PROPOSAL_CREATE,
            $table,
            $keyColumns,
            $payload,
            [],
            $meta
        );

        if ($operation) {
            flash('已提交新增提案，等待管理員審核 @ '.Carbon::now(), 'info');
        }

        return redirect()->route('codes.show', ['table_name' => $table]);
    }

    public function proposalEdit($table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);

        $columns = Schema::getColumnListing($table);
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $payload[$column] ?? '';
        }

        return view('codes.proposal-edit', [
            'table' => $table,
            'columns' => $columns,
            'values' => $values,
            'operationId' => $operation['id'],
            'keyColumns' => $payload['__key_columns'] ?? $this->getKeyColumns($table),
            'proposalMeta' => $payload['__proposal_meta'] ?? [],
            'reviewStatus' => $payload['__review_status'] ?? 'pending',
            'reviewComment' => $payload['__review_comment'] ?? null,
            'isCreateProposal' => (int) $operation['op_type'] === Operation::TYPE_PROPOSAL_CREATE,
            'page_title' => 'Codes',
            'page_description' => $table . ' 提案調整',
            'page_url' => route('codes.show', ['table_name' => $table]),
            'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li><li class='breadcrumb-item'><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li><li class='breadcrumb-item active'>提案調整</li>",
        ]);
    }

    public function proposalUpdateExisting(Request $request, $table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);
        $keyColumns = $payload['__key_columns'] ?? $this->getKeyColumns($table);

        $data = $this->extractFormData($request);
        $isCreate = (int) $operation['op_type'] === Operation::TYPE_PROPOSAL_CREATE;

        if ($isCreate) {
            if (!$this->hasPrimaryKeyValues($keyColumns, $data)) {
                flash('提案失敗：請確認主鍵欄位已填寫完整。', 'error');

                return redirect()->back()->withInput();
            }

            $conditions = $this->buildConditionsFromRow($keyColumns, $data);
            if (!empty($conditions) && $this->fetchRowByKeys($table, $keyColumns, $conditions)) {
                flash('提案失敗：資料已存在，請改用修改提案。', 'warning');

                return redirect()->back()->withInput();
            }

            if ($this->hasActiveCreateProposalConflict($table, $keyColumns, $data, $operation['id'])) {
                flash('提案失敗：已有其他新增提案使用相同主鍵，請調整後再提交。', 'warning');

                return redirect()->back()->withInput();
            }
        } else {
            $original = $this->decodeResourceOriginal($operation);
            if (!empty($original)) {
                $data = $this->enforceAuditFieldsForUpdate($data, $original);
            }
        }

        $meta = $payload['__proposal_meta'] ?? [];
        $comment = trim((string) $request->input('__proposal_comment', ''));
        if ($comment !== '') {
            $meta['comment'] = $comment;
        } else {
            unset($meta['comment']);
        }
        unset($meta['cancelled_at'], $meta['cancelled_by'], $meta['cancelled_by_id'], $meta['cancel_reason']);
        $meta['updated_at'] = Carbon::now()->format('Y-m-d H:i:s');

        $newPayload = $data;
        $newPayload['__proposal_meta'] = $meta;
        $newPayload['__key_columns'] = $keyColumns;
        $this->resetProposalReviewState($newPayload);

        $updates = [
            'resource_data' => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        if ($isCreate) {
            $resourceId = $this->buildCompositeId($keyColumns, $newPayload);
            if ($resourceId === '') {
                $resourceId = 'proposal:' . $operation['id'];
            }
            $updates['resource_id'] = $resourceId;
        }

        DB::table('operations')->where('id', $operation['id'])->update($updates);

        flash('提案內容已更新，等待審核 @ '.Carbon::now(), 'success');

        return redirect()->route('operations.index', ['proposals_only' => 1]);
    }

    public function proposalCancel(Request $request, $table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);

        if (!isset($payload['__proposal_meta']) || !is_array($payload['__proposal_meta'])) {
            $payload['__proposal_meta'] = [];
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            unset($payload['__proposal_meta']['cancel_reason']);
        } else {
            $payload['__proposal_meta']['cancel_reason'] = $reason;
        }

        $payload['__proposal_meta']['cancelled_at'] = Carbon::now()->format('Y-m-d H:i:s');
        $payload['__proposal_meta']['cancelled_by'] = Auth::user()->name ?? Auth::id();
        $payload['__proposal_meta']['cancelled_by_id'] = Auth::id();
        $this->markProposalCancelled($payload);

        DB::table('operations')->where('id', $operation['id'])->update([
            'resource_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        flash('提案已撤回 @ '.Carbon::now(), 'info');

        return redirect()->route('operations.index', ['proposals_only' => 1]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設自動增加的$id遮除。
    public function store(Request $request, $table_name) {
        $table = $this->guardTable($table_name);
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $data = Arr::except($request->all(), ['_token', '__proposal_comment']);
        $keyColumns = $this->getKeyColumns($table);
        if (!$this->hasPrimaryKeyValues($keyColumns, $data)) {
            flash('新增失敗：請確認主鍵欄位已填寫完整。', 'error');

            return redirect()->back()
                ->withInput()
                ->withErrors(['missing_keys' => '新增失敗：請確認主鍵欄位已填寫完整。']);
        }
        $data = $this->enforceAuditFieldsForCreate($table, $data);
        //20210323遮除「第一欄預設隱藏」
        //$id_ = $this->getIdName($table_name);
        //if($table_name != 'SOCIAL_INSTITUTION_CODES') {
        //$id = DB::table($table_name)->max($id_) + 1;
        //$data[$id_] = $id;
        //}
        //else {
        //當資料表等於SOCIAL_INSTITUTION_CODES，$id從表單取值。
        //$id = $data[$id_];
        //}
        //20210323插入聯合主鍵的邏輯
        $id_name = $this->getIdName($table);
        $id_name_1 = $this->getIdName_1($table);
        $id_name_2 = $this->getIdName_2($table);
        $id = $data[$id_name].'_._'.$data[$id_name_1];

        //$id = $data[$id_name].'_._'.$data[$id_name_1].'_._'.$data[$id_name_2];
        //修改結束
        try {
            DB::table($table)->insert($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                flash('新增失敗：主鍵或唯一值已存在。', 'error');

                return redirect()->back()
                    ->withInput()
                    ->withErrors(['duplicate' => '新增失敗：主鍵或唯一值已存在。']);
            }

            throw $e;
        }

        $storedRow = $this->fetchRowByKeys($table, $keyColumns, $this->buildConditionsFromRow($keyColumns, $data));
        $rowData = $storedRow ?: $data;
        $this->recordOperation(1, $table, $keyColumns, $rowData);

        $id = $this->buildCompositeId($keyColumns, $rowData);

        flash('Store success @ '.Carbon::now(), 'success');

        return redirect()->route('codes.edit', ['table_name' => $table, 'id' => $id]);
    }

    public function proposalUpdate(Request $request, $table_name, $id) {
        $table = $this->guardTable($table_name);
        if ($redirect = $this->ensureEditableAccess($table)) {
            return $redirect;
        }

        $keyColumns = $this->getKeyColumns($table);
        $conditions = $this->buildConditionsFromId($keyColumns, $id);
        $originalRow = $this->fetchRowByKeys($table, $keyColumns, $conditions);
        if (!$originalRow) {
            flash('提案失敗：找不到對應的資料列。', 'error');

            return redirect()->back()->withInput();
        }

        $payload = $this->enforceAuditFieldsForUpdate(
            $this->extractFormData($request),
            $originalRow
        );

        $diff = $this->operationRepository->getArrDiff($payload, $originalRow, $originalRow);
        if ($diff === null) {
            flash('提案失敗：未偵測到任何修改內容。', 'warning');

            return redirect()->back()->withInput();
        }

        $meta = $this->buildProposalMeta('update', $table, $request);
        $operation = $this->recordProposalOperation(
            Operation::TYPE_PROPOSAL_UPDATE,
            $table,
            $keyColumns,
            $payload,
            $originalRow,
            $meta
        );

        if ($operation) {
            flash('已提交修改提案，等待管理員審核 @ '.Carbon::now(), 'info');
        }

        return redirect()->route('codes.edit', ['table_name' => $table, 'id' => $id]);
    }

    public function destroy($table_name, $id) {
        $table = $this->guardTable($table_name);
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止刪除。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $keyColumns = $this->getKeyColumns($table);
        $conditions = $this->buildConditionsFromId($keyColumns, $id);
        $row = $this->fetchRowByKeys($table, $keyColumns, $conditions);

        $this->recordOperation(4, $table, $keyColumns, $row ?: $conditions, $row ?: []);

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        $query->delete();

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('codes.show', ['table_name' => $table]);
    }

    protected function getIdName($table_name) {
        return $columns = Schema::getColumnListing($table_name)[0];
    }

    protected function getIdName_1($table_name) {
        return $columns = Schema::getColumnListing($table_name)[1];
    }

    protected function getIdName_2($table_name) {
        return $columns = Schema::getColumnListing($table_name)[2];
    }

    protected function buildTableHead(string $table, $sampleRow): array {
        $upperTable = strtoupper($table);
        if (isset($this->tableColumnOverrides[$upperTable])) {
            // 对于有 JOIN 配置的表，直接返回配置的列，因为它们包含别名列
            if (isset($this->tableJoinConfigurations[$upperTable])) {
                return $this->tableColumnOverrides[$upperTable];
            }

            // 对于没有 JOIN 的表，与 Schema 进行交集验证
            $availableColumns = Schema::getColumnListing($table);
            $overrideColumns = array_values(array_intersect($this->tableColumnOverrides[$upperTable], $availableColumns));
            if (!empty($overrideColumns)) {
                return $overrideColumns;
            }
        }

        $thead = [];
        if ($sampleRow) {
            $count = 0;
            foreach ((array) $sampleRow as $key => $value) {
                if ($count > 2) {
                    break;
                }

                if (
                    Str::contains($key, 'name') ||
                    Str::contains($key, 'desc') ||
                    Str::contains($key, 'code') ||
                    Str::contains($key, 'id') ||
                    Str::contains($key, 'sequence') ||
                    Str::contains($key, 'chn') ||
                    Str::contains($key, 'dy')
                ) {
                    $thead[] = $key;
                    $count++;
                }
            }

            if (empty($thead)) {
                $thead = array_keys((array) $sampleRow);
            }
        } else {
            $thead = Schema::getColumnListing($table);
        }

        return array_values(array_unique($thead));
    }

    protected function determineSearchableColumns(string $table, array $thead): array {
        $upperTable = strtoupper($table);
        if (isset($this->tableColumnOverrides[$upperTable])) {
            return $this->tableColumnOverrides[$upperTable];
        }

        if (!empty($thead)) {
            return $thead;
        }

        return Schema::getColumnListing($table);
    }

    protected function getKeyColumns(string $table): array {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $upperTable = strtoupper($table);

        // 先检查配置，有配置则直接返回，不需要查询数据库
        if (isset($this->tablePrimaryKeyOverrides[$upperTable])) {
            $overrideKeys = array_values(array_unique(array_filter($this->tablePrimaryKeyOverrides[$upperTable])));
            if (!empty($overrideKeys)) {
                return $cache[$table] = $overrideKeys;
            }
        }

        $keys = [];

        try {
            $connection = DB::connection();
            $details = $connection->getDoctrineSchemaManager()->listTableDetails($table);
            if ($details->hasPrimaryKey()) {
                $keys = $details->getPrimaryKey()->getColumns();
            }
        } catch (\Throwable $e) {
            $keys = [];
        }

        // 只有在需要时才查询列（作为 fallback）
        if (empty($keys)) {
            $columns = Schema::getColumnListing($table);
            $keys[] = $columns[0] ?? 'id';
            if (isset($columns[1])) {
                $keys[] = $columns[1];
            }
        }

        return $cache[$table] = array_values(array_unique(array_filter($keys)));
    }

    /**
     * Determine whether the given table should be treated as read-only.
     *
     * @param string $table
     * @return bool
     */
    protected function isReadOnlyTable(string $table): bool {
        return in_array(strtoupper($table), $this->readOnlyTables, true);
    }

    /**
     * Retrieve mapping of dynasty IDs to Chinese dynasty names.
     *
     * @return array<int|string, string>
     */
    protected function getDynastyNameMap(): array {
        if ($this->dynastyNameMap !== null) {
            return $this->dynastyNameMap;
        }

        try {
            $map = DB::table('DYNASTIES')
                ->select('c_dy', 'c_dynasty_chn')
                ->get()
                ->pluck('c_dynasty_chn', 'c_dy')
                ->toArray();
        } catch (\Throwable $e) {
            $map = [];
        }

        $normalized = [];
        foreach ($map as $id => $name) {
            $normalized[(string) $id] = $name;
        }

        return $this->dynastyNameMap = $normalized;
    }

    protected function buildCompositeId(array $keyColumns, array $row): string {
        $parts = [];
        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $row)) {
                $parts[] = (string) $row[$column];
            }
        }

        return implode('_._', array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }

    /**
     * Apply audit columns for create operations when the table supports them.
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function enforceAuditFieldsForCreate(string $table, array $data): array {
        $columns = $this->getTableColumns($table);
        $now = Carbon::now();

        if (in_array('c_created_by', $columns, true) && Auth::check()) {
            $data['c_created_by'] = Auth::user()->name;
        }

        if (in_array('c_created_date', $columns, true)) {
            // Store as Carbon object (Laravel will convert to TIMESTAMP in DB)
            $data['c_created_date'] = $now;
        }

        return $data;
    }

    /**
     * Retrieve (and cache) table columns.
     *
     * @param string $table
     * @return array<int, string>
     */
    protected function getTableColumns(string $table): array {
        if (!array_key_exists($table, $this->tableColumnsCache)) {
            try {
                $this->tableColumnsCache[$table] = Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                $this->tableColumnsCache[$table] = [];
            }
        }

        return $this->tableColumnsCache[$table];
    }

    /**
     * Ensure primary key columns appear first when rendering create form.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $keyColumns
     * @return array<int, string>
     */
    protected function orderColumnsForCreate(array $columns, array $keyColumns): array {
        $keyColumns = array_values(array_intersect($keyColumns, $columns));
        $nonKey = array_values(array_diff($columns, $keyColumns));

        return array_merge($keyColumns, $nonKey);
    }

    /**
     * Guess the next key value for auto-increment-like columns.
     *
     * @param string $table
     * @param string $column
     * @return string|null
     */
    protected function guessNextKeyValue(string $table, string $column): ?string {

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
     * Ensure audit columns cannot be tampered with via requests.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    protected function enforceAuditFieldsForUpdate(array $data, array $original): array {
        $now = Carbon::now();

        // 保护 created_* 字段不被修改
        foreach (['c_created_by', 'c_created_date'] as $field) {
            if (array_key_exists($field, $data) && array_key_exists($field, $original)) {
                $data[$field] = $original[$field];
            }
        }

        // 更新 c_modified_by
        if (array_key_exists('c_modified_by', $data)) {
            if (Auth::check()) {
                $data['c_modified_by'] = Auth::user()->name;
            } elseif (array_key_exists('c_modified_by', $original)) {
                $data['c_modified_by'] = $original['c_modified_by'];
            }
        }

        // 更新 c_modified_date
        if (array_key_exists('c_modified_date', $data)) {
            // Store as Carbon object (Laravel will convert to TIMESTAMP in DB)
            $data['c_modified_date'] = $now;
        } elseif (array_key_exists('c_modified_date', $original)) {
            $data['c_modified_date'] = $original['c_modified_date'];
        }

        return $data;
    }

    /**
     * Reorder fields to display audit columns in a logical sequence.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function orderAuditFieldsForDisplay(array $row): array {
        // 定义审计字段的理想顺序
        $auditFieldOrder = [
            'c_created_by',
            'c_created_date',
            'c_modified_by',
            'c_modified_date',
        ];

        // 时间戳字段需要转换时区
        $timestampFields = [
            'c_created_date',
            'c_modified_date',
        ];

        // 分离审计字段和其他字段
        $auditFields = [];
        $otherFields = [];

        foreach ($row as $key => $value) {
            if (in_array($key, $auditFieldOrder, true)) {
                // 时间戳字段转换为应用配置的时区
                if (in_array($key, $timestampFields, true) && $value !== null && $value !== '') {
                    try {
                        $carbon = Carbon::parse($value);
                        // 转换为应用配置的时区（与写入时保持一致）
                        $carbon->setTimezone(config('app.timezone'));
                        $auditFields[$key] = $carbon->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        // 如果解析失败，保持原值
                        $auditFields[$key] = $value;
                    }
                } else {
                    $auditFields[$key] = $value;
                }
            } else {
                $otherFields[$key] = $value;
            }
        }

        // 按照定义的顺序重新排列审计字段
        $orderedAuditFields = [];
        foreach ($auditFieldOrder as $field) {
            if (array_key_exists($field, $auditFields)) {
                $orderedAuditFields[$field] = $auditFields[$field];
            }
        }

        // 合并：其他字段在前，审计字段在后（按顺序）
        return array_merge($otherFields, $orderedAuditFields);
    }

    protected function buildConditionsFromRow(array $keyColumns, array $row): array {
        $conditions = [];
        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $row)) {
                $conditions[$column] = $row[$column];
            }
        }

        return $conditions;
    }

    protected function buildConditionsFromId(array $keyColumns, string $id): array {
        $conditions = [];
        $parts = explode('_._', $id);
        foreach ($keyColumns as $index => $column) {
            if (isset($parts[$index]) && $parts[$index] !== '') {
                $conditions[$column] = $parts[$index];
            }
        }

        return $conditions;
    }

    protected function fetchRowByKeys(string $table, array $keyColumns, array $conditions) {
        if (empty($conditions)) {
            return null;
        }

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        $row = $query->first();

        return $row ? $this->convertRowToArray($row) : null;
    }

    protected function hasActiveCreateProposalConflict(string $table, array $keyColumns, array $row, ?int $excludeOperationId = null): bool {
        if (!Schema::hasTable('operations')) {
            return false;
        }

        if (count($keyColumns) === 1) {
            return false;
        }

        $resourceId = $this->buildCompositeId($keyColumns, $row);
        if ($resourceId === '') {
            return false;
        }

        return $this->operationRepository->hasPendingCreateProposal($table, $resourceId, $excludeOperationId);
    }

    protected function findOperationOrAbort(int $operationId): array {
        if (!Schema::hasTable('operations')) {
            abort(404);
        }

        $row = DB::table('operations')->where('id', $operationId)->first();
        if (!$row) {
            abort(404);
        }

        return (array) $row;
    }

    protected function ensureProposalEditable(array $operation, string $table): array {
        if (!Auth::check()) {
            abort(403, '請登入後再試。');
        }

        if (($operation['resource'] ?? null) !== $table) {
            abort(404);
        }

        $payload = json_decode($operation['resource_data'] ?? '', true);
        $payload = is_array($payload) ? $payload : [];

        $meta = $payload['__proposal_meta'] ?? [];
        $submittedById = $meta['submitted_by_id'] ?? ($operation['user_id'] ?? null);

        if ($submittedById === null || (int) $submittedById !== (int) Auth::id()) {
            abort(403, '僅提案者本人可編輯或撤回該提案。');
        }

        $status = $payload['__review_status'] ?? 'pending';
        if (!in_array($status, ['pending', 'rejected'], true)) {
            abort(403, '該提案目前不可修改或撤回。');
        }

        return $payload;
    }

    protected function resetProposalReviewState(array &$payload): void {
        $payload['__review_status'] = 'pending';
        unset(
            $payload['__review_comment'],
            $payload['__reviewed_by'],
            $payload['__reviewed_by_id'],
            $payload['__reviewed_at'],
            $payload['__cancelled_at'],
            $payload['__cancelled_by'],
            $payload['__cancelled_by_id']
        );
    }

    protected function markProposalCancelled(array &$payload): void {
        $payload['__review_status'] = 'cancelled';
        unset(
            $payload['__review_comment'],
            $payload['__reviewed_by'],
            $payload['__reviewed_by_id'],
            $payload['__reviewed_at']
        );
    }

    protected function decodeResourceOriginal(array $operation): array {
        $original = json_decode($operation['resource_original'] ?? '', true);

        return is_array($original) ? $original : [];
    }

    protected function ensureEditableAccess(string $table): ?RedirectResponse {
        if (!Auth::check()) {
            flash('請登入後再進行操作 @ '.Carbon::now(), 'error');

            return redirect()->back()->withInput();
        }
        if (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back()->withInput();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯或提案。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }

        return null;
    }

    protected function extractFormData(Request $request): array {
        return Arr::except($request->all(), ['_token', '_method', '__proposal_comment']);
    }

    protected function hasPrimaryKeyValues(array $keyColumns, array $data): bool {
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $data) || $data[$column] === '' || $data[$column] === null) {
                return false;
            }
        }

        return true;
    }

    protected function buildProposalMeta(string $action, string $table, Request $request): array {
        $user = Auth::user();
        $meta = [
            'action' => $action,
            'table' => $table,
            'submitted_by' => $user ? ($user->name ?: $user->email ?: $user->id) : null,
            'submitted_by_id' => $user ? $user->id : null,
            'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        $comment = trim((string) $request->input('__proposal_comment', ''));
        if ($comment !== '') {
            $meta['comment'] = $comment;
        }

        return array_filter($meta, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    protected function recordProposalOperation(int $type, string $table, array $keyColumns, array $data, array $original = [], array $meta = []) {
        if (!Auth::check()) {
            return null;
        }

        $resourceRow = $type === Operation::TYPE_PROPOSAL_CREATE ? $data : ($original ?: $data);
        $resourceId = $this->buildCompositeId($keyColumns, $resourceRow);
        if ($resourceId === '') {
            $resourceId = 'proposal:' . uniqid();
        }

        $payload = $data;
        if (!empty($meta)) {
            $payload['__proposal_meta'] = $meta;
        }
        $payload['__key_columns'] = $keyColumns;
        $payload['__review_status'] = 'pending';

        return $this->operationRepository->store(
            Auth::id(),
            0,
            $type,
            $table,
            $resourceId,
            $payload,
            $original
        );
    }

    protected function recordOperation(int $type, string $table, array $keyColumns, array $data, array $original = []) {
        if (!Auth::check()) {
            return;
        }

        $resourceId = $this->buildCompositeId($keyColumns, $data);
        if ($resourceId === '' && !empty($original)) {
            $resourceId = $this->buildCompositeId($keyColumns, $original);
        }

        $this->operationRepository->store(
            Auth::id(),
            0,
            $type,
            $table,
            $resourceId,
            $data,
            $original
        );
    }

    protected function convertRowToArray($row): array {
        if (is_null($row)) {
            return [];
        }

        if (is_array($row)) {
            return $row;
        }

        if ($row instanceof \ArrayAccess) {
            return (array) $row;
        }

        return json_decode(json_encode($row), true) ?: [];
    }

    protected function isDuplicateKeyException(\Illuminate\Database\QueryException $exception): bool {
        if ($exception->getCode() === '23000') {
            return true;
        }

        $message = $exception->getMessage();

        return strpos($message, 'Duplicate entry') !== false;
    }

    /**
     * Extract joined column names (aliases) from JOIN configuration.
     *
     * @param array $config
     * @return array
     */
    protected function getJoinedColumnNames(array $config): array {
        $joinedColumns = [];
        $select = $config['select'] ?? [];

        foreach ($select as $selectExpr) {
            // 提取 "column as alias" 中的 alias
            if (strpos($selectExpr, ' as ') !== false) {
                $parts = explode(' as ', $selectExpr);
                if (count($parts) === 2) {
                    $joinedColumns[] = trim($parts[1]);
                }
            }
        }

        return $joinedColumns;
    }

    /**
     * Build a JOIN query based on configuration.
     *
     * @param array $config
     * @return \Illuminate\Database\Query\Builder
     */
    protected function buildJoinQuery(array $config) {
        $baseTable = $config['base_table'];
        $baseAlias = $config['base_alias'];
        $joins = $config['joins'] ?? [];
        $select = $config['select'] ?? [];

        // Start query with base table alias
        $query = DB::table($baseTable . ' as ' . $baseAlias);

        // Apply JOINs
        foreach ($joins as $join) {
            $joinTable = $join['table'] . ' as ' . $join['alias'];
            $joinType = $join['type'] ?? 'left';

            if ($joinType === 'left') {
                $query->leftJoin($joinTable, $join['on'][0], $join['on'][1], $join['on'][2]);
            } elseif ($joinType === 'inner') {
                $query->join($joinTable, $join['on'][0], $join['on'][1], $join['on'][2]);
            }
        }

        // Apply SELECT if specified
        if (!empty($select)) {
            $query->select($select);
        }

        return $query;
    }

    /**
     * Show table with cursor-based pagination (for large tables).
     *
     * @param Request $request
     * @param string $table
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $search
     * @param int $perPage
     * @param array $thead
     * @return \Illuminate\View\View
     */
    protected function showWithCursorPagination(Request $request, string $table, $query, string $search, int $perPage, array $thead) {
        $after = $request->query('after');   // 下一页游标 (id)
        $before = $request->query('before'); // 上一页游标 (id)

        // 游标查询逻辑
        if ($before) {
            // 上一页：取 id < before 的最后 N+1 条（倒序），然后反转
            $results = (clone $query)
                ->where('id', '<', $before)
                ->orderBy('id', 'desc')
                ->limit($perPage + 1)
                ->get();

            $hasMore = $results->count() > $perPage;
            if ($hasMore) {
                $results = $results->slice(0, $perPage);
            }
            $results = $results->reverse()->values();
            $hasPrev = $hasMore;
            $hasNext = true; // 既然有 before，说明肯定有下一页
        } else {
            // 下一页或首页：取 id > after 的前 N+1 条
            if ($after) {
                $query->where('id', '>', $after);
            }
            $results = $query
                ->orderBy('id', 'asc')
                ->limit($perPage + 1)
                ->get();

            $hasMore = $results->count() > $perPage;
            if ($hasMore) {
                $results = $results->slice(0, $perPage)->values();
            }
            $hasNext = $hasMore;
            $hasPrev = (bool)$after; // 有 after 说明不是首页，肯定有上一页
        }

        // 构建游标元数据
        $firstId = $results->first()->id ?? null;
        $lastId = $results->last()->id ?? null;

        $cursorMeta = [
            'type' => 'cursor',
            'data' => $results,
            'per_page' => $perPage,
            'first_id' => $firstId,
            'last_id' => $lastId,
            'has_more_pages' => $hasNext,
            'has_prev_pages' => $hasPrev,
            'next_cursor' => $hasNext ? $lastId : null,
            'prev_cursor' => $hasPrev ? $firstId : null,
            'search' => $search,
        ];

        // 其他元数据
        $dynastyMap = [];
        if (in_array('c_dy', $thead, true)) {
            $dynastyMap = $this->getDynastyNameMap();
        }

        $isReadOnly = $this->isReadOnlyTable($table);
        $keyColumns = $this->getKeyColumns($table);
        $copyrightNote = $this->tableCopyrightNotes[$table] ?? null;

        // 标记哪些列是通过 JOIN 获得的别名列
        $upperTable = strtoupper($table);
        $joinConfig = $this->tableJoinConfigurations[$upperTable] ?? null;
        $joinedColumns = [];
        if ($joinConfig) {
            $joinedColumns = $this->getJoinedColumnNames($joinConfig);
        }

        return view('codes.show', [
            'page_title' => $table,
            'page_description' => '',
            'page_url' => '/codes',
            'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li>",
            'q' => $table,
            'thead' => $thead,
            'data' => $cursorMeta,  // 传递游标元数据而非标准分页对象
            'search' => $search,
            'dynastyMap' => $dynastyMap,
            'isReadOnly' => $isReadOnly,
            'keyColumns' => $keyColumns,
            'copyrightNote' => $copyrightNote,
            'joinedColumns' => $joinedColumns,
            'useCursorPagination' => true,  // 标记使用游标分页
        ]);
    }
}
