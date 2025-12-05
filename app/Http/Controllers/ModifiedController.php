<?php

namespace App\Http\Controllers;

use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Operation;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Illuminate\Support\Facades\DB;

class ModifiedController extends Controller {
    protected $operationRepository;
    protected $toolRepository;

    public function __construct(ToolsRepository $toolsRepository, OperationRepository $operationRepository) {
        $this->toolRepository = $toolsRepository;
        $this->operationRepository = $operationRepository;
    }

    public function store() {
        //        Operation::all();
    }

    public function index() {
        $lists = Operation::whereIn('crowdsourcing_status', [0,1])->orderBy('updated_at', 'desc')->limit(100)->paginate(20);
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
                    default:
                        $arr3 = [];

                        break;
                }
            } else {
                $arr3 = [];
            }

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

        return view('modified.index', ['lists' => $lists,
            'page_title' => '修改紀錄', 'page_description' => '最近修改紀錄',
            'page_url' => '/modified'
        ]);
    }
}
