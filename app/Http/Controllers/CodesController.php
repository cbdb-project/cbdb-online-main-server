<?php

namespace App\Http\Controllers;

use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use phpDocumentor\Reflection\Types\Null_;

class CodesController extends Controller
{
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
        'DYNASTIES',
        'GANZHI_CODES',
    ];
    /**
     * Custom column configurations for specific tables.
     *
     * @var array<string, array<int, string>>
     */
    protected $tableColumnOverrides = [
        'ADDR_BELONGS_DATA' => ['c_addr_id', 'c_belongs_to', 'c_firstyear', 'c_lastyear'],
        'ADDR_CODES' => ['c_addr_id', 'c_name', 'c_name_chn', 'c_firstyear', 'c_lastyear', 'c_admin_type'],
        'DYNASTIES' => ['c_dy', 'c_dynasty_chn', 'c_dynasty', 'c_start', 'c_end'],
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
            'c_merged_to_personid',
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
    ];
    /**
     * Explicit primary key definitions for code tables.
     *
     * @var array<string, array<int, string>>
     */
    protected $tablePrimaryKeyOverrides = [
        'TEXT_CODES' => ['c_textid'],
    ];

    public function __construct(CodesRepository $codesRepository, OperationRepository $operationRepository)
    {
        $this->codesrepostory = $codesRepository;
        $this->operationRepository = $operationRepository;
        $this->allowedTables = $this->codesrepostory->allowedTables();
        $map = $this->codesrepostory->allowedTableMap();
        $this->allowedTablesMap = $map;
    }

    protected function guardTable(string $table): string
    {
        $key = strtoupper($table);
        if (!isset($this->allowedTablesMap[$key])) {
            abort(404);
        }
        return $this->allowedTablesMap[$key];
    }

    public function index()
    {
        $data = $this->codesrepostory->codes();
        return view('codes.index',[
            'page_title' => 'Codes',
            'page_description' => '代碼表',
            'page_url' => '/codes',
            'data' => $data]);
    }

    public function show(Request $request, $table_name)
    {
        $table = $this->guardTable($table_name);
        $search = trim((string) $request->query('search', ''));
        try {
            $perPage = config('codes.per_page', 20);
            $query = DB::table($table);
            $sampleRow = (clone $query)->first();
            $thead = $this->buildTableHead($table, $sampleRow);
            $searchableColumns = $this->determineSearchableColumns($table, $thead);

            if ($search !== '' && !empty($searchableColumns)) {
                $query->where(function ($subQuery) use ($searchableColumns, $search) {
                    foreach ($searchableColumns as $column) {
                        $subQuery->orWhere($column, 'like', '%' . $search . '%');
                    }
                });
            }

            $data = $query->paginate($perPage)->appends(['search' => $search]);

            $dynastyMap = [];
            if (in_array('c_dy', $thead, true)) {
                $dynastyMap = $this->getDynastyNameMap();
            }

            $isReadOnly = $this->isReadOnlyTable($table);
            $keyColumns = $this->getKeyColumns($table);

            return view('codes.show', [
                'page_title' => 'Codes',
                'page_description' => $table,
                'page_url' => '/codes',
                'archer' => "<li class='active'>".e($table)."</li>",
                'q' => $table,
                'thead' => $thead,
                'data' => $data,
                'search' => $search,
                'dynastyMap' => $dynastyMap,
                'isReadOnly' => $isReadOnly,
                'keyColumns' => $keyColumns,
            ]);
        }catch (\PDOException $e){
            flash('找不到该数据表', 'warning');
            return redirect()->back();
        }
    }

    public function edit($table_name,$id)
    {
//        dd($table_name);
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯。', 'warning');
            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        if($table){
            try{
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

                $rowArray = $this->prepareAuditFieldsForDisplay($this->convertRowToArray($data));
                $compositeId = $this->buildCompositeId($keyColumns, $rowArray);

                return view('codes.edit', [
                    'page_title' => 'Codes',
                    'page_description' => $table,
                    'page_url' => '/codes',
                    'archer' => "<li><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li>",
                    'id' => $compositeId, 'row' => $rowArray,
                    'table' => $table]);
            }catch (\PDOException $e) {
                flash('找不到该数据表', 'warning');
                return redirect()->back();
            }

        }
        return redirect()->route('codes.index');
    }

    public function update(Request $request, $table_name, $id)
    {
        $table = $this->guardTable($table_name);
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
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
        $data = array_except($request->all(), ['_method', '_token']);
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
    public function create($table_name)
    {
//        dd($table_name);
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');
            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $data = Schema::getColumnListing($table);
        $id_ = $data[0];
        //20210323遮除「第一欄預設隱藏」
        //if($table_name != 'SOCIAL_INSTITUTION_CODES') {
            //$data = array_splice($data, 1);
        //}
        $id = DB::table($table)->max($id_) + 1;
        return view('codes.create',[
            'page_title' => 'Codes',
            'page_description' => $table,
            'page_url' => '/codes',
            'archer' => "<li><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li>",
            'row' => $data,
            'id' => $id, 'table' => $table]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設自動增加的$id遮除。
    public function store(Request $request, $table_name)
    {
        $table = $this->guardTable($table_name);
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');
            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $data = array_except($request->all(), ['_token']);
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

        $keyColumns = $this->getKeyColumns($table);
        $storedRow = $this->fetchRowByKeys($table, $keyColumns, $this->buildConditionsFromRow($keyColumns, $data));
        $rowData = $storedRow ?: $data;
        $this->recordOperation(1, $table, $keyColumns, $rowData);

        $id = $this->buildCompositeId($keyColumns, $rowData);

        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('codes.edit', ['table_name' => $table, 'id' => $id]);
    }

    public function destroy($table_name, $id)
    {
        $table = $this->guardTable($table_name);
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
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

    protected function getIdName($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[0];
    }

    protected function getIdName_1($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[1];
    }

    protected function getIdName_2($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[2];
    }

    protected function buildTableHead(string $table, $sampleRow): array
    {
        $upperTable = strtoupper($table);
        if (isset($this->tableColumnOverrides[$upperTable])) {
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
                    str_contains($key, 'name') ||
                    str_contains($key, 'desc') ||
                    str_contains($key, 'code') ||
                    str_contains($key, 'id') ||
                    str_contains($key, 'sequence') ||
                    str_contains($key, 'chn') ||
                    str_contains($key, 'dy')
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

    protected function determineSearchableColumns(string $table, array $thead): array
    {
        $upperTable = strtoupper($table);
        if (isset($this->tableColumnOverrides[$upperTable])) {
            return $this->tableColumnOverrides[$upperTable];
        }

        if (!empty($thead)) {
            return $thead;
        }

        return Schema::getColumnListing($table);
    }

    protected function getKeyColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = Schema::getColumnListing($table);
        $keys = [];
        $upperTable = strtoupper($table);

        if (isset($this->tablePrimaryKeyOverrides[$upperTable])) {
            $overrideKeys = array_values(array_unique(array_filter($this->tablePrimaryKeyOverrides[$upperTable])));
            if (!empty($overrideKeys)) {
                return $cache[$table] = $overrideKeys;
            }
        }

        try {
            $connection = DB::connection();
            $details = $connection->getDoctrineSchemaManager()->listTableDetails($table);
            if ($details->hasPrimaryKey()) {
                $keys = $details->getPrimaryKey()->getColumns();
            }
        } catch (\Throwable $e) {
            $keys = [];
        }

        if (empty($keys)) {
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
    protected function isReadOnlyTable(string $table): bool
    {
        return in_array(strtoupper($table), $this->readOnlyTables, true);
    }

    /**
     * Retrieve mapping of dynasty IDs to Chinese dynasty names.
     *
     * @return array<int|string, string>
     */
    protected function getDynastyNameMap(): array
    {
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

    protected function buildCompositeId(array $keyColumns, array $row): string
    {
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
     * Prepare immutable/mutable audit columns before rendering edit form.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function prepareAuditFieldsForDisplay(array $row): array
    {
        if (array_key_exists('c_modified_by', $row) && Auth::check()) {
            $row['c_modified_by'] = Auth::user()->name;
        }

        if (array_key_exists('c_modified_date', $row)) {
            $row['c_modified_date'] = Carbon::now()->format('Ymd');
        }

        return $row;
    }

    /**
     * Ensure audit columns cannot be tampered with via requests.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    protected function enforceAuditFieldsForUpdate(array $data, array $original): array
    {
        foreach (['c_created_by', 'c_created_date'] as $field) {
            if (array_key_exists($field, $data) && array_key_exists($field, $original)) {
                $data[$field] = $original[$field];
            }
        }

        if (array_key_exists('c_modified_by', $data)) {
            if (Auth::check()) {
                $data['c_modified_by'] = Auth::user()->name;
            } elseif (array_key_exists('c_modified_by', $original)) {
                $data['c_modified_by'] = $original['c_modified_by'];
            }
        }

        if (array_key_exists('c_modified_date', $data)) {
            $data['c_modified_date'] = Carbon::now()->format('Ymd');
        } elseif (array_key_exists('c_modified_date', $original)) {
            $data['c_modified_date'] = $original['c_modified_date'];
        }

        return $data;
    }

    protected function buildConditionsFromRow(array $keyColumns, array $row): array
    {
        $conditions = [];
        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $row)) {
                $conditions[$column] = $row[$column];
            }
        }
        return $conditions;
    }

    protected function buildConditionsFromId(array $keyColumns, string $id): array
    {
        $conditions = [];
        $parts = explode('_._', $id);
        foreach ($keyColumns as $index => $column) {
            if (isset($parts[$index]) && $parts[$index] !== '') {
                $conditions[$column] = $parts[$index];
            }
        }
        return $conditions;
    }

    protected function fetchRowByKeys(string $table, array $keyColumns, array $conditions)
    {
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

    protected function recordOperation(int $type, string $table, array $keyColumns, array $data, array $original = [])
    {
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

    protected function convertRowToArray($row): array
    {
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

    protected function isDuplicateKeyException(\Illuminate\Database\QueryException $exception): bool
    {
        if ($exception->getCode() === '23000') {
            return true;
        }

        $message = $exception->getMessage();
        return strpos($message, 'Duplicate entry') !== false;
    }
}
