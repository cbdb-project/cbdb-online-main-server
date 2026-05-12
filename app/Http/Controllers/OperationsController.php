<?php

namespace App\Http\Controllers;

use App\Models\BiogMain;
use App\Models\OfficeCode;
use App\Models\OfficeCodeTypeRel;
use App\Models\OfficeTypeTree;
use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Support\BasicInformationHistory;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OperationsController extends Controller {
    protected $operationRepository;
    protected array $auditCurrentRowCache = [];

    public function __construct(OperationRepository $operationRepository) {
        $this->operationRepository = $operationRepository;
    }

    public function store() {
        //        Operation::all();
    }

    public function index(Request $request) {
        $proposalsOnly = filter_var($request->input('proposals_only', false), FILTER_VALIDATE_BOOLEAN);
        $historyContext = $this->resolveHistoryContext($request);

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
        } else {
            $query->whereNotIn('op_type', [
                Operation::TYPE_PROPOSAL_CREATE,
                Operation::TYPE_PROPOSAL_UPDATE,
            ]);
        }

        // 修改人篩選（支援 user_id 或 name 模糊匹配）
        $editorFilter = trim($request->input('editor', ''));
        if ($editorFilter !== '') {
            $query->whereHas('user', function ($q) use ($editorFilter) {
                if (ctype_digit($editorFilter)) {
                    $q->where('id', (int) $editorFilter);
                } else {
                    $q->where('name', 'like', '%' . $editorFilter . '%');
                }
            });
        }

        // 修改類型篩選（多選 op_type，僅非 proposals 模式）
        if (!$proposalsOnly) {
            $rawOpTypes = $request->input('op_type', []);
            if (!is_array($rawOpTypes)) {
                $rawOpTypes = [$rawOpTypes];
            }
            $opTypeFilter = array_values(array_intersect(
                array_filter(array_map('intval', $rawOpTypes)),
                [1, 2, 3, 4, 8, 9]
            ));
            if (!empty($opTypeFilter)) {
                $query->whereIn('op_type', $opTypeFilter);
            }
        }

        if ($historyContext !== null) {
            $this->applyHistoryFilter($query, $historyContext);
        }

        $lists = $query->orderBy('updated_at', 'desc')->paginate(20);
        $lists->appends($request->except('page'));
        //將物件轉為陣列進行陣列比對
        $listsArr = $this->operationRepository->objectToArray($lists);
        $dataRows = isset($listsArr['data']) && is_array($listsArr['data']) ? $listsArr['data'] : [];
        $auditLogsByOperation = $this->loadAuditLogsByOperation($dataRows);
        $all = count($dataRows);
        for ($x = 0;$x < $all;$x++) {
            $c_personid = '';
            $arr3 = [];
            $resource = $dataRows[$x]['resource'];
            $arr1 = $dataRows[$x]['resource_data'];
            $arr2 = $dataRows[$x]['resource_original'];
            $operationId = (string) ($dataRows[$x]['id'] ?? '');
            $auditLogsForOperation = $auditLogsByOperation[$operationId] ?? [];
            $lists[$x]->setAttribute('audit_logs', $auditLogsForOperation);
            $lists[$x]->setAttribute(
                'affected_person_ids',
                $this->extractAffectedPersonIds($dataRows[$x], $auditLogsForOperation)
            );
            //20191225實時比對的程式判斷
            if (!empty($c_personid = $dataRows[$x]['c_personid']) && $dataRows[$x]['resource'] == "BIOG_MAIN") {
                $arr3 = BiogMain::find($c_personid)->toArray();
            } elseif (!empty($resource_id = $dataRows[$x]['resource_id']) && !empty($resource = $dataRows[$x]['resource'])) {
                // 新格式：query-string 格式的 resource_id（由 buildStoredResourceId() 產生）
                // 若 schema key 驗證失敗（舊格式值恰好含 '='），自動回退到舊格式 switch/case
                $usedNewFormat = false;
                if (str_contains($resource_id, '=') && !str_contains($resource_id, '_._')) {
                    $parsedPk = CompositePrimaryKey::parseStoredResourceId($resource_id, $resource);
                    if ($parsedPk !== null) {
                        $usedNewFormat = true;
                        // BIOG_TEXT_DATA 在 SCHEMAS 中有別名 TEXT_DATA，但資料表名為 BIOG_TEXT_DATA
                        $tableName = ($resource === 'TEXT_DATA') ? 'BIOG_TEXT_DATA' : $resource;
                        $query = DB::table($tableName);
                        foreach ($parsedPk as $col => $val) {
                            if ($val === 'NULL' || $val === null) {
                                $query->whereNull($col);
                            } else {
                                $query->where($col, '=', $val);
                            }
                        }

                        if ($resource === 'POSTED_TO_ADDR_DATA') {
                            // POSTED_TO_ADDR_DATA 使用 rows 格式
                            $personId = $dataRows[$x]['c_personid'] ?? null;
                            if ($personId === null) {
                                $decoded = json_decode($arr1, true);
                                if (is_array($decoded) && isset($decoded['rows'][0]['c_personid'])) {
                                    $personId = (int) $decoded['rows'][0]['c_personid'];
                                }
                            }
                            if ($personId !== null) {
                                $query->where('c_personid', $personId);
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
                            $arr3 = json_decode(json_encode($query->first()), true);
                        }
                    }
                }
                if (!$usedNewFormat) {
                    switch ($resource) {
                        case "OFFICE_CODES":
                            if ($office = OfficeCode::find($resource_id)) {
                                $arr3 = $office->toArray();
                            }

                            break;
                        case "OFFICE_CODE_TYPE_REL":
                            if (strpos($resource_id, '_._') !== false) {
                                $temp_l = explode('_._', $resource_id);
                            } else {
                                $temp_l = explode('-', $resource_id);
                            }
                            if (count($temp_l) >= 2) {
                                $relation = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])
                                    ->where('c_office_tree_id', $temp_l[1])
                                    ->first();
                                if ($relation) {
                                    $arr3 = $relation->toArray();
                                }
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
                            // 使用 CompositePrimaryKey 解析 resource_id（支援歷史 4-key 與新 3-key），返回 3-key 查詢
                            $parsedAlt = CompositePrimaryKey::parseStoredResourceId($resource_id, 'ALTNAME_DATA');
                            if ($parsedAlt !== null) {
                                $altQuery = DB::table('ALTNAME_DATA');
                                foreach ($parsedAlt as $col => $val) {
                                    if ($val === 'NULL' || $val === null) {
                                        $altQuery->whereNull($col);
                                    } else {
                                        $altQuery->where($col, '=', $val);
                                    }
                                }
                                $arr3 = $altQuery->first();
                            } else {
                                \Log::warning("ALTNAME_DATA resource_id 格式不正確: {$resource_id}", [
                                    'operation_id' => $listsArr['data'][$x]['id'] ?? null,
                                ]);
                                $arr3 = null;
                            }
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
                                    // 注意：必須先處理 (minus) 再處理 minus
                                    $value = str_replace("(minus)", "-", $value);
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
                            // 支持新格式 "c_personid-c_sequence-c_event_code" 和舊格式 "c_sequence"
                            $eventParts = explode('-', $resource_id);
                            $eventQuery = DB::table('EVENTS_DATA')->where('c_personid', $c_personid);
                            if (count($eventParts) >= 3) {
                                // 新格式：resource_id = "c_personid-c_sequence-c_event_code"
                                $eventQuery->where('c_sequence', $eventParts[1])
                                           ->where('c_event_code', $eventParts[2]);
                            } else {
                                // 舊格式：resource_id = "c_sequence"
                                $eventQuery->where('c_sequence', $resource_id);
                            }
                            $arr3 = $eventQuery->first();
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
                                    // 注意：必須先處理 (minus) 再處理 minus
                                    $value = str_replace("(minus)", "-", $value);
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
                                    // 注意：必須先處理 (minus) 再處理 minus
                                    $value = str_replace("(minus)", "-", $value);
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
                                $arr3 = DB::table('ASSOC_DATA')->where([
                                    ['c_personid', '=', $assoc_1[0]],
                                    ['c_assoc_code', '=', $assoc_1[1]],
                                    ['c_assoc_id', '=', $assoc_1[2]],
                                    ['c_kin_code', '=', $assoc_1[3]],
                                    ['c_kin_id', '=', $assoc_1[4]],
                                    ['c_assoc_kin_code', '=', $assoc_1[5]],
                                    ['c_assoc_kin_id', '=', $assoc_1[6]],
                                    ['c_text_title', '=', $assoc_1[7] ?? ''],
                                    ['c_assoc_first_year', '=', $assoc_1[8] ?? '-9999'],
                                ])->first();
                            } else {
                                // 使用 - 格式
                                // 策略：先嘗試新格式（不做 -- 替換），如果找不到再嘗試舊格式（做 -- 替換）
                                // 這樣可以正確處理新格式中空 c_text_title 導致的 -- 情況

                                // 輔助函數：解碼保留字（不包括 -- 和 minus）
                                $decodeReserved = function ($str) {
                                    $str = str_replace("(slash)", "/", $str);
                                    $str = str_replace("(backslash)", "\\", $str);
                                    $str = str_replace("(brackets)", "{", $str);
                                    $str = str_replace("(brackets_r)", "}", $str);
                                    $str = str_replace("(question)", "?", $str);
                                    $str = str_replace("(hash)", "#", $str);
                                    $str = str_replace("(amp)", "&", $str);

                                    return $str;
                                };

                                // 輔助函數：解析 segments 中的 minus 編碼
                                $decodeMinusInSegments = function ($segments) {
                                    foreach ($segments as $key => $value) {
                                        // 注意：必須先處理 (minus) 再處理 minus，否則 (minus) 會變成 (-)
                                        $value = str_replace("(minus)", "-", $value);  // 新版 (minus) 編碼
                                        $value = str_replace("minus", "-", $value);  // 舊版 -- 格式的向後兼容
                                        $segments[$key] = $value;
                                    }

                                    return $segments;
                                };

                                $arr3 = null;

                                // 第一次嘗試：新格式（不做 -- 替換，保留空欄位邊界）
                                $alt_new = $decodeReserved($resource_id);
                                $assoc_1_new = explode("-", $alt_new);
                                $assoc_1_new = $decodeMinusInSegments($assoc_1_new);

                                $totalParts = count($assoc_1_new);
                                if ($totalParts >= 9) {
                                    $c_assoc_first_year_new = $assoc_1_new[$totalParts - 1];
                                    if ($totalParts > 9) {
                                        $c_text_title_new = implode('-', array_slice($assoc_1_new, 7, $totalParts - 8));
                                    } else {
                                        $c_text_title_new = $assoc_1_new[7] ?? '';
                                    }

                                    $arr3 = DB::table('ASSOC_DATA')->where([
                                        ['c_personid', '=', $assoc_1_new[0]],
                                        ['c_assoc_code', '=', $assoc_1_new[1]],
                                        ['c_assoc_id', '=', $assoc_1_new[2]],
                                        ['c_kin_code', '=', $assoc_1_new[3]],
                                        ['c_kin_id', '=', $assoc_1_new[4]],
                                        ['c_assoc_kin_code', '=', $assoc_1_new[5]],
                                        ['c_assoc_kin_id', '=', $assoc_1_new[6]],
                                        ['c_text_title', '=', $c_text_title_new],
                                        ['c_assoc_first_year', '=', $c_assoc_first_year_new],
                                    ])->first();
                                }

                                // 第二次嘗試：舊格式（做 -- 替換，用於向後兼容舊版 ID）
                                if (!$arr3) {
                                    $alt_old = str_replace("--", "-minus", $resource_id);
                                    $alt_old = $decodeReserved($alt_old);
                                    $assoc_1_old = explode("-", $alt_old);
                                    $assoc_1_old = $decodeMinusInSegments($assoc_1_old);

                                    $totalPartsOld = count($assoc_1_old);

                                    // 嘗試新 9-field 格式（舊編碼）
                                    if ($totalPartsOld >= 9) {
                                        $c_assoc_first_year_try = $assoc_1_old[$totalPartsOld - 1];
                                        if ($totalPartsOld > 9) {
                                            $c_text_title_try = implode('-', array_slice($assoc_1_old, 7, $totalPartsOld - 8));
                                        } else {
                                            $c_text_title_try = $assoc_1_old[7] ?? '';
                                        }

                                        $arr3 = DB::table('ASSOC_DATA')->where([
                                            ['c_personid', '=', $assoc_1_old[0]],
                                            ['c_assoc_code', '=', $assoc_1_old[1]],
                                            ['c_assoc_id', '=', $assoc_1_old[2]],
                                            ['c_kin_code', '=', $assoc_1_old[3]],
                                            ['c_kin_id', '=', $assoc_1_old[4]],
                                            ['c_assoc_kin_code', '=', $assoc_1_old[5]],
                                            ['c_assoc_kin_id', '=', $assoc_1_old[6]],
                                            ['c_text_title', '=', $c_text_title_try],
                                            ['c_assoc_first_year', '=', $c_assoc_first_year_try],
                                        ])->first();
                                    }

                                    // 嘗試舊 8-field 格式
                                    if (!$arr3 && $totalPartsOld >= 8) {
                                        $c_text_title_old = implode('-', array_slice($assoc_1_old, 7));
                                        $c_assoc_first_year_old = '-9999';

                                        $arr3 = DB::table('ASSOC_DATA')->where([
                                            ['c_personid', '=', $assoc_1_old[0]],
                                            ['c_assoc_code', '=', $assoc_1_old[1]],
                                            ['c_assoc_id', '=', $assoc_1_old[2]],
                                            ['c_kin_code', '=', $assoc_1_old[3]],
                                            ['c_kin_id', '=', $assoc_1_old[4]],
                                            ['c_assoc_kin_code', '=', $assoc_1_old[5]],
                                            ['c_assoc_kin_id', '=', $assoc_1_old[6]],
                                            ['c_text_title', '=', $c_text_title_old],
                                            ['c_assoc_first_year', '=', $c_assoc_first_year_old],
                                        ])->first();
                                    }
                                }
                            }
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
                                    // 注意：必須先處理 (minus) 再處理 minus
                                    $value = str_replace("(minus)", "-", $value);
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
                                    // 注意：必須先處理 (minus) 再處理 minus
                                    $value = str_replace("(minus)", "-", $value);
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
                } // end if (!$usedNewFormat)
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
        $this->attachAffectedPeople($lists);
        //echo "<pre><code>";
        //print_r($lists[0]['resource_original']); //成功
        //echo "</code></pre>";
        $pageTitle = $proposalsOnly ? '最近提案列表' : '最近操作記錄';
        $pageDescription = $proposalsOnly ? '最近提案列表' : '最近編輯列表';
        $pageUrl = $proposalsOnly ? '/operations?proposals_only=1' : '/operations';
        $pageTitleKey = $proposalsOnly ? 'OperationsProposals' : 'NewUpdate';

        return view('operations.index', [
            'lists' => $lists,
            'page_title' => $pageTitle,
            'page_title_key' => $pageTitleKey,
            'page_description' => $pageDescription,
            'page_url' => $pageUrl,
            'proposals_only' => $proposalsOnly,
            'status_filters' => $statusFilters,
            'history_context' => $historyContext,
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

        // 優先使用 resource_original（操作時保存的原始快照），因為它是最準確的「修改前」狀態。
        // 當主鍵欄位（如 c_office_id）有變動時，用 resource_id 去搜尋前一筆操作的 resource_data
        // 可能找到的是「更早之前同 resource_id」的操作（主鍵值相同但不是本次的直接前身），
        // 導致復原到錯誤的狀態。
        // getPreviousSnapshot 僅在 resource_original 為空時作為回退方案。
        $target = $this->decodeJson($operation->resource_original);
        if (empty($target)) {
            $target = $this->getPreviousSnapshot($operation);
        }
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
        // 排除提案操作（op_type 8/9），因為提案的 resource_data 存的是「修改後的值」，
        // 不是「修改前的快照」，用於復原會導致恢復到修改後的狀態（等於沒復原）。
        $previous = Operation::where('resource', $operation->resource)
            ->where('resource_id', $operation->resource_id)
            ->where('id', '<', $operation->id)
            ->whereNotIn('op_type', [Operation::TYPE_PROPOSAL_CREATE, Operation::TYPE_PROPOSAL_UPDATE])
            ->orderBy('id', 'desc')
            ->first();

        if ($previous) {
            $decoded = $this->decodeJson($previous->resource_data);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        // ALTNAME 跨格式回退 (#834)
        // 同一 ALTNAME 行的操作可能以不同格式儲存 resource_id（4-key vs 3-key），
        // 精確匹配會遺漏。透過解析 resource_id 再逐一比對來搜尋前次操作。
        if ($previous === null && $operation->resource === 'ALTNAME_DATA') {
            $previous = $this->findPreviousAltnameOperation($operation);
            if ($previous) {
                $decoded = $this->decodeJson($previous->resource_data);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }
        }

        $original = $this->decodeJson($operation->resource_original);

        return $original ?? [];
    }

    /**
     * 跨格式搜尋前一筆 ALTNAME 操作 (#834)
     *
     * 先從當前操作的 resource_id 解析出 3-key 值，
     * 再比對歷史操作的 resource_id（解析後）是否指向同一行。
     */
    protected function findPreviousAltnameOperation(Operation $operation): ?Operation {
        $parsed = CompositePrimaryKey::parseStoredResourceId(
            $operation->resource_id ?? '',
            'ALTNAME_DATA'
        );
        if ($parsed === null) {
            return null;
        }

        // 以 c_personid 縮小範圍（operations 表直接欄位），
        // 避免全域掃描導致目標操作超出固定窗口而遺漏。
        $candidates = Operation::where('resource', 'ALTNAME_DATA')
            ->where('c_personid', $operation->c_personid)
            ->where('id', '<', $operation->id)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($candidates as $candidate) {
            $candidateParsed = CompositePrimaryKey::parseStoredResourceId(
                $candidate->resource_id ?? '',
                'ALTNAME_DATA'
            );
            if ($candidateParsed !== null && $candidateParsed == $parsed) {
                return $candidate;
            }
        }

        return null;
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
            // 優先嘗試 query-string 格式（新格式）
            $namedParsed = CompositePrimaryKey::parseStoredResourceId(
                $operation->resource_id ?? '',
                $resource
            );
            if ($namedParsed !== null) {
                foreach ($keys as $column) {
                    if ((!array_key_exists($column, $conditions) || $conditions[$column] === null || $conditions[$column] === '')
                        && array_key_exists($column, $namedParsed)) {
                        $val = $namedParsed[$column];
                        $conditions[$column] = ($val === 'NULL') ? null : $val;
                    }
                }
            } else {
                // 回退到舊格式位置解析
                $parsed = $this->parseCompoundKey($operation->resource_id);
                if (count($parsed) === count($keys)) {
                    foreach ($keys as $index => $column) {
                        if (!array_key_exists($column, $conditions) || $conditions[$column] === null || $conditions[$column] === '') {
                            $conditions[$column] = $parsed[$index];
                        }
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
            // 注意：必須先處理 (minus) 再處理 minus，否則 (minus) 會變成 (-)
            $segment = str_replace('(minus)', '-', $segment);  // 新版 (minus) 編碼
            $segments[$index] = str_replace('minus', '-', $segment);  // 舊版 -- 格式
        }

        return $segments;
    }

    /**
     * 使用 3-key 查詢 ALTNAME_DATA 現行資料列
     */
    protected function fetchAltnameCurrentRow(array $operation) {
        $decoded = $this->decodeJson($operation['resource_data'] ?? null);
        $decoded = is_array($decoded) ? $decoded : [];

        $personId = $decoded['c_personid'] ?? null;
        $altNameChn = $decoded['c_alt_name_chn'] ?? null;
        $typeCode = $decoded['c_alt_name_type_code'] ?? null;

        // 從 resource_id 補齊缺少的欄位
        if ($personId === null || $altNameChn === null || $typeCode === null) {
            $namedParts = CompositePrimaryKey::parseStoredResourceId(
                $operation['resource_id'] ?? '',
                'ALTNAME_DATA'
            );
            if ($namedParts !== null) {
                if ($personId === null && isset($namedParts['c_personid'])) {
                    $val = $namedParts['c_personid'];
                    $personId = ($val === 'NULL') ? null : (int) $val;
                }
                if ($altNameChn === null && isset($namedParts['c_alt_name_chn'])) {
                    $val = $namedParts['c_alt_name_chn'];
                    $altNameChn = ($val === 'NULL') ? null : $val;
                }
                if ($typeCode === null && isset($namedParts['c_alt_name_type_code'])) {
                    $val = $namedParts['c_alt_name_type_code'];
                    $typeCode = ($val === 'NULL') ? null : (int) $val;
                }
            }
        }

        if ($personId !== null && $altNameChn !== null && $typeCode !== null) {
            return DB::table('ALTNAME_DATA')->where([
                ['c_personid', '=', $personId],
                ['c_alt_name_chn', '=', $altNameChn],
                ['c_alt_name_type_code', '=', $typeCode],
            ])->first();
        }

        return null;
    }

    protected function resourceKeyColumns($resource) {
        $map = [
            'BIOG_MAIN' => ['c_personid'],
            'BIOG_ADDR_DATA' => ['c_personid','c_addr_id','c_addr_type','c_sequence'],
            // ALTNAME_DATA 使用 3-key，c_sequence 不參與定位 (#834)
            'ALTNAME_DATA' => ['c_personid','c_alt_name_chn','c_alt_name_type_code'],
            'BIOG_TEXT_DATA' => ['c_personid','c_textid','c_role_id'],
            'POSTED_TO_OFFICE_DATA' => ['c_office_id','c_posting_id'],
            'OFFICE_CODES' => ['c_office_id'],
            'OFFICE_CODE_TYPE_REL' => ['c_office_id','c_office_tree_id'],
            'OFFICE_TYPE_TREE' => ['c_office_type_node_id'],
            'BIOG_SOURCE_DATA' => ['c_personid','c_textid','c_pages'],
            'ENTRY_DATA' => ['c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code',  'c_assoc_code',  'c_kin_id',  'c_year', 'c_assoc_id',  'c_inst_code',  'c_inst_name_code'],
            'STATUS_DATA' => ['c_personid','c_sequence','c_status_code'],
            'KIN_DATA' => ['c_personid','c_kin_id','c_kin_code'],
            'POSSESSION_DATA' => ['c_possession_record_id'],
            'TEXT_CODES' => ['c_textid'],
            'BIOG_INST_DATA' => ['c_personid','c_inst_code','c_inst_name_code','c_bi_role_code'],
            'EVENTS_DATA' => ['c_personid','c_sequence','c_event_code'],
            'ASSOC_DATA' => ['c_personid','c_assoc_code','c_assoc_id','c_kin_code','c_kin_id','c_assoc_kin_code','c_assoc_kin_id','c_text_title','c_assoc_first_year'],
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

        // 正規化 resource_id，確保新紀錄一律使用最新格式
        $resourceId = $this->normalizeResourceId(
            $originalOperation->resource,
            $originalOperation->resource_id,
            $restored
        );

        // operations.c_personid is NOT NULL with a FK to BIOG_MAIN in the
        // production schema (database/migrations/2025_01_01_000000_import_cbdb_schema.php).
        // Non-person resources (TEXT_CODES, OFFICE_*, …) have no person id to
        // resolve, so coerce null to 0 — matching the CREATE-time convention
        // used by every batch importer in this codebase.
        $auditPersonId = $personId ?? 0;

        $this->operationRepository->store(
            Auth::id(),
            $auditPersonId,
            3,
            $originalOperation->resource,
            $resourceId,
            $restored,
            $previous
        );
    }

    /**
     * 正規化 resource_id
     *
     * 對於有 resourceKeyColumns 定義的資源，嘗試從 $data 提取 key 欄位
     * 並重建 query-string 格式 resource_id。確保新寫入的操作紀錄一律使用最新格式，
     * 且 resource_id 反映復原後的實際 key 值。
     */
    protected function normalizeResourceId(string $resource, string $originalResourceId, array $data): string {
        $keys = $this->resourceKeyColumns($resource);
        if (empty($keys)) {
            return $originalResourceId;
        }

        $pk = [];
        foreach ($keys as $col) {
            if (!array_key_exists($col, $data)) {
                return $originalResourceId;
            }
            $pk[$col] = $data[$col];
        }

        return CompositePrimaryKey::buildStoredResourceId($pk);
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
        // URL 特殊字符處理
        $key = str_replace("(question)", "?", $key);
        $key = str_replace("(hash)", "#", $key);
        $key = str_replace("(amp)", "&", $key);
        // 複合主鍵分隔符處理
        $key = str_replace("(minus)", "-", $key);
        $result = $key;

        return $result;
    }

    protected function loadAuditLogsByOperation(array $dataRows): array {
        if (empty($dataRows) || !Schema::hasTable('audit_log')) {
            return [];
        }

        $operationIds = [];
        foreach ($dataRows as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $operationIds[] = (string) $row['id'];
        }
        $operationIds = array_values(array_unique($operationIds));
        if (empty($operationIds)) {
            return [];
        }

        $logs = DB::table('audit_log')
            ->whereIn('operation_id', $operationIds)
            ->orderBy('id')
            ->get([
                'id',
                'operation_id',
                'table_name',
                'operation',
                'row_pk',
                'row_pk_text',
                'old_data',
                'new_data',
            ]);

        $grouped = [];
        foreach ($logs as $log) {
            $operationId = (string) ($log->operation_id ?? '');
            if ($operationId === '') {
                continue;
            }

            $oldData = $this->decodeJsonNullable($log->old_data ?? null);
            $newData = $this->decodeJsonNullable($log->new_data ?? null);
            $rowPk = $this->decodeJsonNullable($log->row_pk ?? null);
            $currentData = $this->resolveAuditCurrentRow((string) $log->table_name, $rowPk);

            $grouped[$operationId][] = [
                'id' => (int) $log->id,
                'table_name' => (string) $log->table_name,
                'operation' => (string) $log->operation,
                'row_pk_text' => (string) $log->row_pk_text,
                'row_pk' => $rowPk,
                'old_data' => $oldData,
                'new_data' => $newData,
                'diff' => $this->buildAuditDiff($oldData, $newData, $currentData),
            ];
        }

        return $grouped;
    }

    protected function extractAffectedPersonIds(array $operationRow, array $auditLogs): array {
        $personIds = [];
        $pushId = static function ($value) use (&$personIds): void {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return;
            }

            $id = (int) $value;
            if ($id <= 0) {
                return;
            }

            $personIds[] = $id;
        };

        $pushId($operationRow['c_personid'] ?? null);

        foreach ($auditLogs as $audit) {
            if (!is_array($audit)) {
                continue;
            }

            $tableName = strtoupper(trim((string) ($audit['table_name'] ?? '')));
            $rowPk = is_array($audit['row_pk'] ?? null) ? $audit['row_pk'] : [];
            $oldData = is_array($audit['old_data'] ?? null) ? $audit['old_data'] : [];
            $newData = is_array($audit['new_data'] ?? null) ? $audit['new_data'] : [];

            $pushId($rowPk['c_personid'] ?? null);
            $pushId($oldData['c_personid'] ?? null);
            $pushId($newData['c_personid'] ?? null);

            if (in_array($tableName, ['KIN_DATA', 'ASSOC_DATA'], true)) {
                $pushId($rowPk['c_kin_id'] ?? null);
                $pushId($oldData['c_kin_id'] ?? null);
                $pushId($newData['c_kin_id'] ?? null);
            }
        }

        $personIds = array_values(array_unique($personIds));
        sort($personIds);

        return $personIds;
    }

    protected function attachAffectedPeople($lists): void {
        $allPersonIds = [];
        foreach ($lists as $item) {
            $ids = $item->affected_person_ids ?? [];
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $allPersonIds[] = (int) $id;
                    }
                }
            }
        }
        $allPersonIds = array_values(array_unique($allPersonIds));

        $peopleMap = [];
        if (!empty($allPersonIds) && Schema::hasTable('BIOG_MAIN')) {
            $peopleMap = DB::table('BIOG_MAIN')
                ->whereIn('c_personid', $allPersonIds)
                ->get(['c_personid', 'c_name_chn', 'c_name'])
                ->keyBy('c_personid');
        }

        foreach ($lists as $item) {
            $ids = $item->affected_person_ids ?? [];
            if (!is_array($ids) || empty($ids)) {
                $item->setAttribute('affected_people', []);

                continue;
            }

            $primaryId = is_numeric($item->c_personid ?? null) ? (int) $item->c_personid : null;
            $resourceTargets = $this->resolveAffectedPeopleResourceTargets($item);
            $people = [];
            foreach ($ids as $id) {
                if (!is_numeric($id) || (int) $id <= 0) {
                    continue;
                }
                $personId = (int) $id;
                $person = $peopleMap[$personId] ?? null;
                $people[] = [
                    'id' => $personId,
                    'name_chn' => $person->c_name_chn ?? '',
                    'name' => $person->c_name ?? '',
                    'is_primary' => $primaryId !== null && $personId === $primaryId,
                    'resource_id' => $resourceTargets[$personId]['resource_id'] ?? null,
                    'resource_link' => $resourceTargets[$personId]['resource_link'] ?? null,
                ];
            }

            usort($people, function ($a, $b) {
                if (($a['is_primary'] ?? false) !== ($b['is_primary'] ?? false)) {
                    return ($a['is_primary'] ?? false) ? -1 : 1;
                }

                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            });

            $item->setAttribute('affected_people', $people);
        }
    }

    protected function resolveAffectedPeopleResourceTargets($item): array {
        $resource = strtoupper((string) ($item->resource ?? ''));
        if (!in_array($resource, ['KIN_DATA', 'ASSOC_DATA'], true)) {
            return [];
        }

        $allowEditLink = (int) ($item->op_type ?? 0) !== Operation::TYPE_DELETE;
        $targets = [];
        $auditLogs = is_array($item->audit_logs ?? null) ? $item->audit_logs : [];
        foreach ($auditLogs as $audit) {
            if (!is_array($audit)) {
                continue;
            }

            $tableName = strtoupper(trim((string) ($audit['table_name'] ?? '')));
            if ($tableName !== $resource) {
                continue;
            }

            $rowPk = is_array($audit['row_pk'] ?? null) ? $audit['row_pk'] : [];
            $personId = $rowPk['c_personid'] ?? null;
            if (!is_numeric($personId) || (int) $personId <= 0) {
                continue;
            }
            $personId = (int) $personId;

            $personResourceId = trim((string) ($audit['row_pk_text'] ?? ''));
            if ($personResourceId === '' && !empty($rowPk)) {
                $personResourceId = CompositePrimaryKey::buildStoredResourceId($rowPk);
            }

            if ($personResourceId === '') {
                continue;
            }

            $targets[$personId] = [
                'resource_id' => $personResourceId,
                'resource_link' => $allowEditLink
                    ? CompositePrimaryKey::buildResourceEditUrl($resource, $personResourceId, $personId)
                    : null,
            ];
        }

        $primaryId = $item->c_personid ?? null;
        $primaryResourceId = trim((string) ($item->resource_id ?? ''));
        if (is_numeric($primaryId) && (int) $primaryId > 0 && $primaryResourceId !== '') {
            $primaryId = (int) $primaryId;
            if (!isset($targets[$primaryId])) {
                $targets[$primaryId] = [
                    'resource_id' => $primaryResourceId,
                    'resource_link' => $allowEditLink
                        ? CompositePrimaryKey::buildResourceEditUrl($resource, $primaryResourceId, $primaryId)
                        : null,
                ];
            }
        }

        return $targets;
    }

    protected function resolveHistoryContext(Request $request): ?array {
        $personId = trim((string) $request->input('c_personid', ''));
        if ($personId === '' || !ctype_digit($personId) || (int) $personId <= 0) {
            return null;
        }

        $historyConfig = BasicInformationHistory::resolveFromPage($request->input('history_page'));
        if ($historyConfig === null || empty($historyConfig['tables'])) {
            return null;
        }

        return [
            'person_id' => (int) $personId,
            'page' => $historyConfig['page'],
            'tables' => $historyConfig['tables'],
            'label' => $historyConfig['label'],
        ];
    }

    protected function applyHistoryFilter($query, array $historyContext): void {
        $personId = (int) ($historyContext['person_id'] ?? 0);
        $tables = BasicInformationHistory::normalizeTables((array) ($historyContext['tables'] ?? []));

        if ($personId <= 0 || empty($tables)) {
            return;
        }

        $query->where(function ($historyQuery) use ($personId, $tables) {
            $historyQuery->where(function ($legacyQuery) use ($personId, $tables) {
                $legacyQuery->where('c_personid', $personId)
                    ->whereIn('resource', $tables);
            });

            $mirrorFallbackTables = $this->historyLegacyMirrorFallbackTables($tables);
            if (!empty($mirrorFallbackTables)) {
                $historyQuery->orWhere(function ($mirrorQuery) use ($personId, $mirrorFallbackTables) {
                    foreach ($mirrorFallbackTables as $table => $keys) {
                        $mirrorQuery->orWhere(function ($tableQuery) use ($personId, $table, $keys) {
                            $tableQuery->where('resource', $table)
                                ->where(function ($resourceIdQuery) use ($personId, $keys) {
                                    foreach ($keys as $key) {
                                        $this->appendResourceIdPersonKeyLike($resourceIdQuery, $key, $personId);
                                    }
                                });
                        });
                    }
                });
            }

            if (!Schema::hasTable('audit_log')) {
                return;
            }

            $historyQuery->orWhereExists(function ($auditQuery) use ($personId, $tables) {
                $auditQuery->select(DB::raw(1))
                    ->from('audit_log')
                    ->whereColumn('audit_log.operation_id', 'operations.id')
                    ->whereIn('audit_log.table_name', $tables)
                    ->where(function ($personQuery) use ($personId) {
                        foreach (['row_pk_text', 'row_pk', 'old_data', 'new_data'] as $column) {
                            $this->appendAuditPersonIdLike($personQuery, $column, $personId);
                        }
                    });
            });
        });
    }

    protected function historyLegacyMirrorFallbackTables(array $tables): array {
        $definitions = [
            'KIN_DATA' => ['c_kin_id'],
            'ASSOC_DATA' => ['c_assoc_id', 'c_kin_id', 'c_assoc_kin_id'],
        ];

        $resolved = [];
        foreach ($tables as $table) {
            $tableName = strtoupper(trim((string) $table));
            if (isset($definitions[$tableName])) {
                $resolved[$tableName] = $definitions[$tableName];
            }
        }

        return $resolved;
    }

    protected function appendAuditPersonIdLike($query, string $column, int $personId): void {
        if ($column === 'row_pk_text') {
            $query->orWhere(function ($columnQuery) use ($column, $personId) {
                $columnQuery->where($column, 'c_personid=' . $personId)
                    ->orWhere($column, 'like', 'c_personid=' . $personId . '&%')
                    ->orWhere($column, 'like', '%&c_personid=' . $personId)
                    ->orWhere($column, 'like', '%&c_personid=' . $personId . '&%');
            });

            return;
        }

        $patterns = [
            '%"c_personid":' . $personId . ',%',
            '%"c_personid":' . $personId . '}%',
            '%"c_personid": ' . $personId . ',%',
            '%"c_personid": ' . $personId . '}%',
            '%"c_personid":"' . $personId . '",%',
            '%"c_personid":"' . $personId . '"}%',
            '%"c_personid": "' . $personId . '",%',
            '%"c_personid": "' . $personId . '"}%',
        ];

        $query->orWhere(function ($columnQuery) use ($column, $patterns) {
            foreach ($patterns as $pattern) {
                $columnQuery->orWhere($column, 'like', $pattern);
            }
        });
    }

    protected function appendResourceIdPersonKeyLike($query, string $key, int $personId): void {
        $needle = trim($key) . '=' . $personId;
        if ($needle === '=' . $personId) {
            return;
        }

        $query->orWhere(function ($resourceIdQuery) use ($needle) {
            $resourceIdQuery->where('resource_id', $needle)
                ->orWhere('resource_id', 'like', $needle . '&%')
                ->orWhere('resource_id', 'like', '%&' . $needle)
                ->orWhere('resource_id', 'like', '%&' . $needle . '&%');
        });
    }

    protected function decodeJsonNullable($payload): ?array {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function buildAuditDiff(?array $oldData, ?array $newData, ?array $currentData = null): ?array {
        $oldArr = is_array($oldData) ? $oldData : [];
        $newArr = is_array($newData) ? $newData : [];
        $currentArr = is_array($currentData) ? $currentData : [];
        $allKeys = array_unique(array_merge(array_keys($oldArr), array_keys($newArr)));
        if (empty($allKeys)) {
            return null;
        }

        $formatValue = function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if ($value === null) {
                return '(null)';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string) $value;
        };

        $normalizeValue = function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value);
            }
            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return trim((string) $value);
        };

        $rows = [];
        foreach ($allKeys as $key) {
            if (in_array($key, ['_method', '_token'], true)) {
                continue;
            }
            $beforeRaw = array_key_exists($key, $oldArr) ? $oldArr[$key] : null;
            $afterRaw = array_key_exists($key, $newArr) ? $newArr[$key] : null;
            if ($normalizeValue($beforeRaw) === $normalizeValue($afterRaw)) {
                continue;
            }
            $currentExists = array_key_exists($key, $currentArr);
            $currentRaw = $currentExists ? $currentArr[$key] : null;
            $rows[] = [
                'field' => $key,
                'before' => $formatValue($beforeRaw),
                'after' => $formatValue($afterRaw),
                'current' => $currentExists ? $formatValue($currentRaw) : '(未取得)',
                'matches_current' => $currentExists && $normalizeValue($afterRaw) === $normalizeValue($currentRaw),
                'matches_before' => $currentExists && $normalizeValue($beforeRaw) === $normalizeValue($currentRaw),
            ];
        }

        return !empty($rows) ? ['rows' => $rows] : null;
    }

    protected function resolveAuditCurrentRow(string $tableName, ?array $rowPk): ?array {
        $tableName = trim($tableName);
        if ($tableName === '' || empty($rowPk) || !Schema::hasTable($tableName)) {
            return null;
        }

        $cacheKey = $tableName . '|' . json_encode($rowPk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (array_key_exists($cacheKey, $this->auditCurrentRowCache)) {
            return $this->auditCurrentRowCache[$cacheKey];
        }

        $query = DB::table($tableName);
        foreach ($rowPk as $column => $value) {
            if ($value === null || $value === 'NULL') {
                $query->whereNull($column);
            } else {
                $query->where($column, '=', $value);
            }
        }

        $row = $query->first();
        $normalized = $row ? json_decode(json_encode($row), true) : null;
        $this->auditCurrentRowCache[$cacheKey] = $normalized;

        return $normalized;
    }
}
