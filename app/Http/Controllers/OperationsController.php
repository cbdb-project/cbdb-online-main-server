<?php

namespace App\Http\Controllers;

use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Operation;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OperationsController extends Controller {
    protected $operationRepository;

    public function __construct(OperationRepository $operationRepository) {
        $this->operationRepository = $operationRepository;
    }

    public function store() {
        //        Operation::all();
    }

    public function index(Request $request) {
        $proposalsOnly = filter_var($request->input('proposals_only', false), FILTER_VALIDATE_BOOLEAN);

        $query = Operation::where('crowdsourcing_status', 0);
        $statusFilters = [];

        if ($proposalsOnly) {
            $rawStatuses = $request->input('status', []);
            if (!is_array($rawStatuses)) {
                $rawStatuses = [$rawStatuses];
            }
            $allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
            $statusFilters = array_values(array_intersect($rawStatuses, $allowedStatuses));

            $query->whereIn('op_type', [
                Operation::TYPE_PROPOSAL_CREATE,
                Operation::TYPE_PROPOSAL_UPDATE,
            ]);

            if (!empty($statusFilters)) {
                $query->where(function ($subQuery) use ($statusFilters) {
                    foreach ($statusFilters as $status) {
                        $subQuery->orWhere('resource_data', 'like', '%"__review_status":"' . $status . '"%');
                    }
                });
            }
        }

        $lists = $query->orderBy('updated_at', 'desc')->paginate(20);
        $lists->appends($request->except('page'));
        //將物件轉為陣列進行陣列比對
        $listsArr = $this->operationRepository->objectToArray($lists);
        $dataRows = isset($listsArr['data']) && is_array($listsArr['data']) ? $listsArr['data'] : [];
        $all = count($dataRows);
        for ($x = 0;$x < $all;$x++) {
            $c_personid = '';
            $arr3 = [];
            $resource = $dataRows[$x]['resource'];
            $arr1 = $dataRows[$x]['resource_data'];
            $arr2 = $dataRows[$x]['resource_original'];
            //20191225實時比對的程式判斷
            if (!empty($c_personid = $dataRows[$x]['c_personid']) && $dataRows[$x]['resource'] == "BIOG_MAIN") {
                $arr3 = BiogMain::find($c_personid)->toArray();
            } elseif (!empty($resource_id = $dataRows[$x]['resource_id']) && !empty($resource = $dataRows[$x]['resource'])) {
                switch ($resource) {
                    case "OFFICE_CODES":
                        if ($office = OfficeCode::find($resource_id)) {
                            $arr3 = $office->toArray();
                        }

                        break;
                    case "OFFICE_CODE_TYPE_REL":
                        $temp_l = explode("-", $resource_id);
                        $relation = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])
                            ->where('c_office_tree_id', $temp_l[1])
                            ->first();
                        if ($relation) {
                            $arr3 = $relation->toArray();
                        }

                        break;
                    case "OFFICE_TYPE_TREE":
                        if ($tree = OfficeTypeTree::find($resource_id)) {
                            $arr3 = $tree->toArray();
                        }

                        break;
                        //20251213新增差異比對紀錄
                    case "BIOG_ADDR_DATA":
                        $addr_l = explode("-", $resource_id);
                        $arr3 = DB::table('BIOG_ADDR_DATA')->where([
                            ['c_personid', '=', $addr_l[0]],
                            ['c_addr_id', '=', $addr_l[1]],
                            ['c_addr_type', '=', $addr_l[2]],
                            ['c_sequence', '=', $addr_l[3]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                    case "ALTNAME_DATA":
                        //20251201先遮除原本存取方式，改使用聯合主鍵解析
                        //$arr3 = $this->fetchAltnameCurrentRow($listsArr['data'][$x]);

                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $addr_l = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $addr_l = explode("-", $alt);
                            foreach ($addr_l as $key => $value) {
                                $addr_l[$key] = str_replace("minus", "-", $value);
                            }
                        }

                        // 檢查陣列長度是否足夠（ALTNAME_DATA 需要 4 個欄位）
                        if (count($addr_l) < 4) {
                            // 記錄錯誤並跳過此筆資料
                            \Log::warning("ALTNAME_DATA resource_id 格式不正確: {$resource_id}", [
                                'parsed' => $addr_l,
                                'expected_count' => 4,
                                'actual_count' => count($addr_l),
                                'operation_id' => $listsArr['data'][$x]['id'] ?? null,
                            ]);
                            $arr3 = null;

                            break;
                        }

                        if (isset($addr_l[1]) && $addr_l[1] == 'NULL') {
                            $addr_l[1] = null;
                        }
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
                        $personId = $dataRows[$x]['c_personid'] ?? null;
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
                        //20251205新增入仕差異比對紀錄
                    case "ENTRY_DATA":
                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $entry_1 = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $entry_1 = explode("-", $alt);
                            foreach ($entry_1 as $key => $value) {
                                $entry_1[$key] = str_replace("minus", "-", $value);
                            }
                        }
                        $arr3 = DB::table('ENTRY_DATA')->where([
                                        ['c_personid', '=', $entry_1[0]],
                                        ['c_entry_code', '=', $entry_1[1]],
                                        ['c_sequence', '=', $entry_1[2]],
                                        ['c_kin_code', '=', $entry_1[3]],
                                        ['c_assoc_code', '=', $entry_1[4]],
                                        ['c_kin_id', '=', $entry_1[5]],
                                        ['c_year', '=', $entry_1[6]],
                                        ['c_assoc_id', '=', $entry_1[7]],
                                        ['c_inst_code', '=', $entry_1[8]],
                                        ['c_inst_name_code', '=', $entry_1[9]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        //dd($arr3);
                        break;
                        //20251208新增事件差異比對紀錄
                    case "EVENTS_DATA":
                        $arr3 = DB::table('EVENTS_DATA')->where('c_personid', $c_personid)->where('c_sequence', $resource_id)->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                        //20251208新增社會區別差異比對紀錄
                    case "STATUS_DATA":
                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $status_1 = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $status_1 = explode("-", $alt);
                            foreach ($status_1 as $key => $value) {
                                $status_1[$key] = str_replace("minus", "-", $value);
                            }
                        }
                        $arr3 = DB::table('STATUS_DATA')->where([
                            ['c_personid', '=', $status_1[0]],
                            ['c_sequence', '=', $status_1[1]],
                            ['c_status_code', '=', $status_1[2]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                        //20251208新增親屬差異比對紀錄
                    case "KIN_DATA":
                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $kin_1 = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $kin_1 = explode("-", $alt);
                            foreach ($kin_1 as $key => $value) {
                                $kin_1[$key] = str_replace("minus", "-", $value);
                            }
                        }
                        $arr3 = DB::table('KIN_DATA')->where([
                            ['c_personid', '=', $kin_1[0]],
                            ['c_kin_id', '=', $kin_1[1]],
                            ['c_kin_code', '=', $kin_1[2]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                        //20251208新增社會關係差異比對紀錄
                    case "ASSOC_DATA":
                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $assoc_1 = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $assoc_1 = explode("-", $alt);
                            foreach ($assoc_1 as $key => $value) {
                                $assoc_1[$key] = str_replace("minus", "-", $value);
                            }
                            //防止c_text_title欄位內含負號所做的字串重組
                            $new_c_text_title = '';
                            if (!empty($assoc_1[8])) {
                                for ($i = 7; $i < count($assoc_1); $i++) {
                                    if (empty($new_c_text_title)) {
                                        $new_c_text_title .= $assoc_1[$i];
                                    } else {
                                        $new_c_text_title .= "-".$assoc_1[$i];
                                    }
                                }
                                $assoc_1[7] = $new_c_text_title;
                            }
                            //end
                        }
                        $arr3 = DB::table('ASSOC_DATA')->where([
                            ['c_personid', '=', $assoc_1[0]],
                            ['c_assoc_code', '=', $assoc_1[1]],
                            ['c_assoc_id', '=', $assoc_1[2]],
                            ['c_kin_code', '=', $assoc_1[3]],
                            ['c_kin_id', '=', $assoc_1[4]],
                            ['c_assoc_kin_code', '=', $assoc_1[5]],
                            ['c_assoc_kin_id', '=', $assoc_1[6]],
                            ['c_text_title', '=', $assoc_1[7]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                        //20251208新增財產差異比對紀錄
                    case "POSSESSION_DATA":
                        $arr3 = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $resource_id)->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                        //20251208新增社交機構差異比對紀錄
                    case "BIOG_INST_DATA":
                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $inst_1 = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $inst_1 = explode("-", $alt);
                            foreach ($inst_1 as $key => $value) {
                                $inst_1[$key] = str_replace("minus", "-", $value);
                            }
                            if ($inst_1[1] == '') {
                                $inst_1[1] = null;
                            }
                            if ($inst_1[2] == '') {
                                $inst_1[2] = null;
                            }
                        }
                        $arr3 = DB::table('BIOG_INST_DATA')->where([
                            ['c_personid', '=', $inst_1[0]],
                            ['c_inst_code', '=', $inst_1[1]],
                            ['c_inst_name_code', '=', $inst_1[2]],
                            ['c_bi_role_code', '=', $inst_1[3]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                        //20251208新增出處差異比對紀錄
                    case "BIOG_SOURCE_DATA":
                        // 檢測分隔符類型：支持兩種格式 '_._' (CodesController) 或 '-' (BasicInformationProposalController)
                        if (strpos($resource_id, '_._') !== false) {
                            // 使用 _._格式
                            $source_1 = explode('_._', $resource_id);
                        } else {
                            // 使用 - 格式
                            $alt = str_replace("--", "-minus", $resource_id);
                            //聯合主鍵保留字弱點防禦函式，解析保留字。
                            $alt = $this->unionPKDef_decode($alt);
                            $source_1 = explode("-", $alt);
                            foreach ($source_1 as $key => $value) {
                                $source_1[$key] = str_replace("minus", "-", $value);
                            }
                            //防止c_pages欄位內含負號所做的字串重組
                            $new_c_pages = '';
                            if (!empty($source_1[3])) {
                                for ($i = 2; $i < count($source_1); $i++) {
                                    if (empty($new_c_pages)) {
                                        $new_c_pages .= $source_1[$i];
                                    } else {
                                        $new_c_pages .= "-".$source_1[$i];
                                    }
                                }
                                $source_1[2] = $new_c_pages;
                            }

                        }

                        // 檢查陣列長度是否足夠（BIOG_SOURCE_DATA 需要 3 個欄位）
                        if (count($source_1) < 3) {
                            // 記錄錯誤並跳過此筆資料
                            \Log::warning("BIOG_SOURCE_DATA resource_id 格式不正確: {$resource_id}", [
                                'parsed' => $source_1,
                                'expected_count' => 3,
                                'actual_count' => count($source_1),
                                'operation_id' => $listsArr['data'][$x]['id'] ?? null,
                            ]);
                            $arr3 = null;

                            break;
                        }

                        $arr3 = DB::table('BIOG_SOURCE_DATA')->where([
                            ['c_personid', '=', $source_1[0]],
                            ['c_textid', '=', $source_1[1]],
                            ['c_pages', '=', $source_1[2]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);

                        break;
                    default:
                        $arr3 = [];

                        break;
                }
            } else {
                $arr3 = [];
            }

            $arr1Decoded = $arr1 ? json_decode($arr1, true) : null;
            $arr2Decoded = $arr2 ? json_decode($arr2, true) : null;
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
        $pageTitle = $proposalsOnly ? '操作記錄（提案）' : '操作記錄';
        $pageDescription = $proposalsOnly ? '最近提案列表' : '最近編輯列表';
        $pageUrl = $proposalsOnly ? '/operations?proposals_only=1' : '/operations';

        return view('operations.index', ['lists' => $lists,
            'page_title' => $pageTitle, 'page_description' => $pageDescription,
            'page_url' => $pageUrl,
            'proposals_only' => $proposalsOnly,
            'status_filters' => $statusFilters,
        ]);
    }

    public function restore(Request $request, Operation $operation) {
        if (!Auth::check()) {
            flash('請登入後再試。', 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canRestoreOperations()) {
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

    protected function performRestore(Operation $operation) {
        switch ((int) $operation->op_type) {
            case 3:
                return $this->restoreUpdate($operation);
            case 4:
                return $this->restoreDelete($operation);
            default:
                throw new \RuntimeException('尚未支援的操作類型');
        }
    }

    protected function restoreUpdate(Operation $operation) {
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

    protected function restoreDelete(Operation $operation) {
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

    protected function getPreviousSnapshot(Operation $operation) {
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

    protected function decodeJson($payload) {
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

    protected function filterColumns($table, array $data) {
        $columns = $this->getColumnListing($table);
        if (empty($columns)) {
            return $data;
        }

        return array_intersect_key($data, array_flip($columns));
    }

    protected $columnCache = [];

    protected function getColumnListing($table) {
        if (!isset($this->columnCache[$table])) {
            try {
                $this->columnCache[$table] = Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                $this->columnCache[$table] = [];
            }
        }

        return $this->columnCache[$table];
    }

    protected function hasColumn($table, $column) {
        $columns = $this->getColumnListing($table);

        return in_array($column, $columns, true);
    }

    protected function buildKeyConditions(Operation $operation, array $current, array $fallback) {
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

    protected function parseCompoundKey($key) {
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

    protected function fetchAltnameCurrentRow(array $operation) {
        $decoded = $this->decodeJson($operation['resource_data'] ?? null);
        $decoded = is_array($decoded) ? $decoded : [];

        $personId = $decoded['c_personid'] ?? null;
        $sequence = $decoded['c_sequence'] ?? null;
        $typeCode = $decoded['c_alt_name_type_code'] ?? null;

        if ($personId === null || $sequence === null || $typeCode === null) {
            $parts = $this->parseCompoundKey($operation['resource_id'] ?? null);
            if ($personId === null && isset($parts[0]) && is_numeric($parts[0])) {
                $personId = (int) $parts[0];
            }
            if ($sequence === null && isset($parts[1]) && is_numeric($parts[1])) {
                $sequence = (int) $parts[1];
            }
            if ($typeCode === null && isset($parts[3]) && is_numeric($parts[3])) {
                $typeCode = (int) $parts[3];
            }
        }

        if ($personId === null || $sequence === null || $typeCode === null) {
            return null;
        }

        return DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $personId],
            ['c_sequence', '=', $sequence],
            ['c_alt_name_type_code', '=', $typeCode],
        ])->first();
    }

    protected function resourceKeyColumns($resource) {
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

    protected function recordRestoreOperation(Operation $originalOperation, array $result): void {
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

    protected function resolvePersonId(Operation $operation, array $restored, array $previous): ?int {
        if (!empty($operation->c_personid)) {
            return (int) $operation->c_personid;
        }

        if (isset($restored['c_personid'])) {
            return (int) $restored['c_personid'];
        }

        if (isset($previous['c_personid'])) {
            return (int) $previous['c_personid'];
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

    protected function unionPKDef_decode($key) {
        $key = str_replace("(slash)", "/", $key);
        $key = str_replace("(backslash)", "\\", $key);
        $key = str_replace("(brackets)", "{", $key);
        $key = str_replace("(brackets_r)", "}", $key);
        $result = $key;

        return $result;
    }
}
