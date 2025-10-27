<?php

namespace App\Http\Controllers;

use App\Operation;
use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Repositories\OperationRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OperationsController extends Controller
{
    protected $operationRepository;

    public function __construct(OperationRepository $operationRepository)
    {
        $this->operationRepository = $operationRepository;
    }

    public function store()
    {
//        Operation::all();
    }

    public function index()
    {
        $lists = Operation::where('crowdsourcing_status', 0)->orderBy('updated_at', 'desc')->limit(100)->paginate(20);
        //將物件轉為陣列進行陣列比對
        $listsArr = $this->operationRepository->objectToArray($lists);
        $all = count($listsArr['data']);
        for($x=0;$x<$all;$x++) {
            $c_personid = '';
            $arr3 = array();
            $resource = $listsArr['data'][$x]['resource'];
            $arr1 = $listsArr['data'][$x]['resource_data'];
            $arr2 = $listsArr['data'][$x]['resource_original'];
            //20191225實時比對的程式判斷
            if(!empty($c_personid = $listsArr['data'][$x]['c_personid']) && $listsArr['data'][$x]['resource'] == "BIOG_MAIN") { $arr3 = BiogMain::find($c_personid)->toArray(); }
            elseif(!empty($resource_id = $listsArr['data'][$x]['resource_id']) && !empty($resource = $listsArr['data'][$x]['resource'])) {
                switch ($resource) {
                    case "OFFICE_CODES":
                        if(count((Array)OfficeCode::find($resource_id))) {
                            $arr3 = OfficeCode::find($resource_id)->toArray();
                        }
                        break;
                    case "OFFICE_CODE_TYPE_REL":
                        $temp_l = explode("-", $resource_id);
                        if(count(OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first())) {
                            $arr3 = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first()->toArray();
                        }
                        break;
                    case "OFFICE_TYPE_TREE":
                        if(count(OfficeTypeTree::find($resource_id))) {
                            $arr3 = OfficeTypeTree::find($resource_id)->toArray();
                        }
                        break;
                    //20251213新增差異比對紀錄
                    case "BIOG_ADDR_DATA":
                        $addr_l = explode("-", $resource_id);
                        $arr3 = DB::table('BIOG_ADDR_DATA')->where([
                            ['c_personid', '=', $addr_l[0]],
                            ['c_addr_id', '=', $addr_l[1]],
                            ['c_addr_type', '=', $addr_l[2]],
                            ['c_sequence', '=', $addr_l[3]]
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    case "ALTNAME_DATA":
                        $addr_l = explode("-", $resource_id);
                        $arr3 = DB::table('ALTNAME_DATA')->where([
                            ['c_personid', '=', $addr_l[0]],
                            ['c_sequence', '=', $addr_l[1]],
                            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
                            ['c_alt_name_type_code', '=', $addr_l[3]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    //20251214新增差異比對紀錄
                    case "BIOG_TEXT_DATA":
                        $temp_l = explode("-", $resource_id);
                        $arr3 = DB::table('BIOG_TEXT_DATA')->where([
                            ['c_personid', '=', $temp_l[0]],
                            ['c_textid', '=', $temp_l[1]],
                            ['c_role_id', '=', $temp_l[2]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    case "POSTED_TO_OFFICE_DATA":
                        $temp_l = explode("-", $resource_id);
                        $_officeid = $temp_l[0];
                        $_postingid = $temp_l[1];
                        $arr3 = DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    case "POSTED_TO_ADDR_DATA":
                        $addrParts = explode('-', $resource_id);
                        $postingOfficeId = $addrParts[0] ?? null;
                        $postingId = $addrParts[1] ?? null;
                        $personId = $listsArr['data'][$x]['c_personid'] ?? null;
                        if ($personId === null) {
                            $decoded = json_decode($arr1, true);
                            if (is_array($decoded) && isset($decoded['rows'][0]['c_personid'])) {
                                $personId = (int) $decoded['rows'][0]['c_personid'];
                            }
                        }
                        if ($personId !== null && $postingId !== null) {
                            $query = DB::table('POSTED_TO_ADDR_DATA')
                                ->where('c_personid', $personId)
                                ->where('c_posting_id', $postingId);
                            if ($postingOfficeId !== null && $postingOfficeId !== '') {
                                $query->where('c_office_id', $postingOfficeId);
                            }
                            $rows = $query->get()->map(function ($row) {
                                return [
                                    'c_personid' => (int) $row->c_personid,
                                    'c_posting_id' => (int) $row->c_posting_id,
                                    'c_office_id' => (int) $row->c_office_id,
                                    'c_addr_id' => (int) $row->c_addr_id,
                                ];
                            })->values()->all();
                            $arr3 = ['rows' => $rows];
                        } else {
                            $arr3 = [];
                        }
                        break;
                    default:
                        $arr3 = array();
                        break;
                }
            }
            else { $arr3 = array(); }

            $arr1Decoded = json_decode($arr1, true);
            $arr2Decoded = json_decode($arr2, true);
            $arr1Decoded = is_array($arr1Decoded) ? $arr1Decoded : [];
            $arr2Decoded = is_array($arr2Decoded) ? $arr2Decoded : [];

            if ($resource === 'POSTED_TO_ADDR_DATA') {
                $currentRows = is_array($arr3) ? ($arr3['rows'] ?? []) : [];
                $diffPayload = $this->operationRepository->buildPostedToAddrDiff(
                    $arr1Decoded['rows'] ?? [],
                    $arr2Decoded['rows'] ?? [],
                    $currentRows
                );
                $lists[$x]->setAttribute('resource_diff', $diffPayload);
            } elseif (!empty($arr2)) {
                $ans = $this->operationRepository->getArrDiff($arr1Decoded, $arr2Decoded, $arr3);
                $lists[$x]->setAttribute('resource_diff', $ans);
            } else {
                $lists[$x]->setAttribute('resource_diff', null);
            }
        }
        //echo "<pre><code>";
        //print_r($lists[0]['resource_original']); //成功
        //echo "</code></pre>";
        return view('operations.index', ['lists' => $lists,
            'page_title' => 'NewUpdate', 'page_description' => '最近編輯列表',
            'page_url' => '/operations'
        ]);
    }

    public function restore(Request $request, Operation $operation)
    {
        if (!Auth::check()) {
            flash('請登入後再試。', 'error');
            return redirect()->back();
        }
        if (Auth::user()->is_active != 1 || Auth::user()->is_admin != 1) {
            flash('該用戶沒有權限，請聯絡管理員。', 'error');
            return redirect()->back();
        }

        if ($operation->resource === 'POSTED_TO_ADDR_DATA') {
            flash('該類操作暫不支援復原。', 'warning');
            return redirect()->back();
        }

        try {
            $result = DB::transaction(function () use ($operation) {
                return $this->performRestore($operation);
            });
            $this->recordRestoreOperation($operation, (array) $result);
            flash('恢復成功 @ '.Carbon::now(), 'success');
        } catch (\Throwable $e) {
            Log::error('Operation restore failed', [
                'operation_id' => $operation->id,
                'error' => $e->getMessage(),
            ]);
            flash('恢復失敗：'.$e->getMessage().' @ '.Carbon::now(), 'error');
        }

        return redirect()->route('operations.index');
    }

    protected function performRestore(Operation $operation)
    {
        switch ((int) $operation->op_type) {
            case 3:
                return $this->restoreUpdate($operation);
            case 4:
                return $this->restoreDelete($operation);
            default:
                throw new \RuntimeException('尚未支援的操作類型');
        }
    }

    protected function restoreUpdate(Operation $operation)
    {
        $table = $operation->resource;
        $current = $this->decodeJson($operation->resource_data);
        $target = $this->getPreviousSnapshot($operation);
        if (empty($target)) {
            throw new \RuntimeException('找不到可恢復的資料內容');
        }
        $conditions = $this->buildKeyConditions($operation, $current, $target);
        if (empty($conditions)) {
            throw new \RuntimeException('缺少主鍵條件，無法更新記錄');
        }
        $payload = $this->filterColumns($table, $target);
        if (empty($payload)) {
            throw new \RuntimeException('恢復內容經過過濾後為空');
        }
        if (in_array('updated_at', array_keys($payload))) {
            $payload['updated_at'] = Carbon::now();
        } elseif ($this->hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = Carbon::now();
        }
        $query = DB::table($table)->where($conditions);
        if (!$query->exists()) {
            throw new \RuntimeException('無法找到要恢復的資料列');
        }
        $query->update($payload);

        return [
            'restored' => $payload,
            'previous' => $current,
        ];
    }

    protected function restoreDelete(Operation $operation)
    {
        $table = $operation->resource;
        $target = $this->decodeJson($operation->resource_data);
        if (empty($target)) {
            throw new \RuntimeException('找不到可還原的刪除資料');
        }
        $payload = $this->filterColumns($table, $target);
        if ($this->hasColumn($table, 'created_at') && !isset($payload['created_at'])) {
            $payload['created_at'] = Carbon::now();
        }
        if ($this->hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = Carbon::now();
        }
        $conditions = $this->buildKeyConditions($operation, $target, $target);
        if (!empty($conditions)) {
            DB::table($table)->updateOrInsert($conditions, $payload);
        } else {
            DB::table($table)->insert($payload);
        }

        return [
            'restored' => $payload,
            'previous' => [],
        ];
    }

    protected function getPreviousSnapshot(Operation $operation)
    {
        $previous = Operation::where('resource', $operation->resource)
            ->where('resource_id', $operation->resource_id)
            ->where('id', '<', $operation->id)
            ->orderBy('id', 'desc')
            ->first();

        if ($previous) {
            $decoded = $this->decodeJson($previous->resource_data);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        $original = $this->decodeJson($operation->resource_original);
        return $original ?? [];
    }

    protected function decodeJson($payload)
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (empty($payload)) {
            return [];
        }
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    protected function filterColumns($table, array $data)
    {
        $columns = $this->getColumnListing($table);
        if (empty($columns)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($columns));
    }

    protected $columnCache = [];

    protected function getColumnListing($table)
    {
        if (!isset($this->columnCache[$table])) {
            try {
                $this->columnCache[$table] = Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                $this->columnCache[$table] = [];
            }
        }
        return $this->columnCache[$table];
    }

    protected function hasColumn($table, $column)
    {
        $columns = $this->getColumnListing($table);
        return in_array($column, $columns, true);
    }

    protected function buildKeyConditions(Operation $operation, array $current, array $fallback)
    {
        $resource = $operation->resource;
        $keys = $this->resourceKeyColumns($resource);
        if (empty($keys)) {
            return [];
        }

        $conditions = [];
        foreach ($keys as $column) {
            if (array_key_exists($column, $current)) {
                $conditions[$column] = $current[$column];
            } elseif (array_key_exists($column, $fallback)) {
                $conditions[$column] = $fallback[$column];
            }
        }

        if (count($conditions) !== count($keys)) {
            $parsed = $this->parseCompoundKey($operation->resource_id);
            if (count($parsed) === count($keys)) {
                foreach ($keys as $index => $column) {
                    if (!array_key_exists($column, $conditions) || $conditions[$column] === null || $conditions[$column] === '') {
                        $conditions[$column] = $parsed[$index];
                    }
                }
            }
        }

        return $conditions;
    }

    protected function parseCompoundKey($key)
    {
        if ($key === null) {
            return [];
        }
        $decoded = str_replace(['(slash)', '(backslash)', '(brackets)', '(brackets_r)'], ['/', '\\', '{', '}'], $key);
        $decoded = str_replace(['(brackets)(brackets)', '(brackets_r)(brackets_r)'], ['{ { ', '} } '], $decoded);
        $segments = explode('-', $decoded);
        foreach ($segments as $index => $segment) {
            $segments[$index] = str_replace('minus', '-', $segment);
        }
        return $segments;
    }

    protected function resourceKeyColumns($resource)
    {
        $map = [
            'BIOG_MAIN' => ['c_personid'],
            'BIOG_ADDR_DATA' => ['c_personid','c_addr_id','c_addr_type','c_sequence'],
            'ALTNAME_DATA' => ['c_personid','c_sequence','c_alt_name_type_code'],
            'BIOG_TEXT_DATA' => ['c_personid','c_textid','c_role_id'],
            'POSTED_TO_OFFICE_DATA' => ['c_office_id','c_posting_id'],
            'OFFICE_CODES' => ['c_office_id'],
            'OFFICE_CODE_TYPE_REL' => ['c_office_id','c_office_tree_id'],
            'OFFICE_TYPE_TREE' => ['c_office_type_node_id'],
            'BIOG_SOURCE_DATA' => ['c_personid','c_textid','c_pages'],
            'ENTRY_DATA' => ['tts_sysno'],
            'STATUS_DATA' => ['c_personid','c_sequence','c_status_code'],
            'KIN_DATA' => ['c_personid','c_kin_id','c_kin_code'],
            'POSSESSION_DATA' => ['c_possession_record_id'],
            'BIOG_INST_DATA' => ['c_personid','c_inst_code','c_inst_name_code'],
            'EVENTS_DATA' => ['c_personid','c_sequence'],
            'ASSOC_DATA' => ['c_personid','c_assoc_code','c_assoc_id','c_kin_code','c_kin_id','c_assoc_kin_code','c_assoc_kin_id','c_text_title'],
        ];

        return $map[$resource] ?? [];
    }

    protected function recordRestoreOperation(Operation $originalOperation, array $result): void
    {
        if (!Auth::check()) {
            return;
        }

        $restored = isset($result['restored']) ? (array) $result['restored'] : [];
        if (empty($restored)) {
            return;
        }

        $previous = isset($result['previous']) ? (array) $result['previous'] : [];
        $personId = $this->resolvePersonId($originalOperation, $restored, $previous);

        $this->operationRepository->store(
            Auth::id(),
            $personId,
            3,
            $originalOperation->resource,
            $originalOperation->resource_id,
            $restored,
            $previous
        );
    }

    protected function resolvePersonId(Operation $operation, array $restored, array $previous): ?int
    {
        if (isset($restored['c_personid'])) {
            return (int) $restored['c_personid'];
        }

        if (isset($previous['c_personid'])) {
            return (int) $previous['c_personid'];
        }

        if (!empty($operation->c_personid)) {
            return (int) $operation->c_personid;
        }

        $decoded = $this->decodeJson($operation->resource_data);
        if (isset($decoded['c_personid'])) {
            return (int) $decoded['c_personid'];
        }

        $original = $this->decodeJson($operation->resource_original);
        if (isset($original['c_personid'])) {
            return (int) $original['c_personid'];
        }

        return null;
    }
}
