<?php

namespace App\Http\Controllers;

use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class UnidirectionalRelationshipRepairController extends Controller
{
    protected OperationRepository $operationRepository;
    protected ToolsRepository $toolsRepository;

    public function __construct(OperationRepository $operationRepository, ToolsRepository $toolsRepository)
    {
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || !Auth::user()->canRunBatchImport()) {
                abort(403, '此功能僅限活躍管理員使用');
            }
            return $next($request);
        });
    }
    public function index(Request $request)
    {
        return view('admin.unidirectional-relationship-repair', [
            'page_title' => '單向關係修復',
            'page_description' => '修復 CBDB 資料庫中的單向親屬關係和社會關係',
            'page_url' => route('admin.unidirectional-relationship-repair'),
        ]);
    }

    /**
     * 修復親屬關係
     */
    public function repairKinship(Request $request)
    {
        $request->validate([
            'c_personid' => 'required|integer',
            'c_kin_id' => 'required|integer',
            'c_kin_code' => 'required|integer',
            'new_c_kin_code' => 'required|integer',
        ]);

        $c_personid = $request->input('c_personid');
        $c_kin_id = $request->input('c_kin_id');
        $c_kin_code = $request->input('c_kin_code');
        $new_c_kin_code = $request->input('new_c_kin_code');

        try {
            // 確認人物與關係代碼存在，避免觸發外鍵例外
            $this->assertKinshipDependenciesExist($c_personid, $c_kin_id, $c_kin_code, $new_c_kin_code);

            return DB::transaction(function () use ($c_personid, $c_kin_id, $c_kin_code, $new_c_kin_code) {
                // 檢索當前單向關係
                $existingRelations = DB::table('KIN_DATA')
                    ->where('c_personid', $c_personid)
                    ->where('c_kin_id', $c_kin_id)
                    ->where('c_kin_code', $c_kin_code)
                    ->lockForUpdate()
                    ->get();

                // 如果找到多條記錄，提示用戶並暫停操作
                if ($existingRelations->count() > 1) {
                    return response()->json([
                        'success' => false,
                        'message' => '檢索到多條記錄（' . $existingRelations->count() . ' 條），請檢查輸入參數是否正確。',
                        'records' => $existingRelations->toArray()
                    ], 400);
                }

                // 如果找不到記錄
                if ($existingRelations->count() === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => '未找到符合條件的親屬關係記錄。',
                    ], 404);
                }

                $relation = $existingRelations->first();

                // 檢查反向關係是否已存在
                $reverseExists = DB::table('KIN_DATA')
                    ->where('c_personid', $c_kin_id)
                    ->where('c_kin_id', $c_personid)
                    ->where('c_kin_code', $new_c_kin_code)
                    ->exists();

                if ($reverseExists) {
                    return response()->json([
                        'success' => false,
                        'message' => '反向關係已存在，無需創建。',
                    ], 400);
                }

                // 創建反向關係記錄
                // 重要：c_autogen_notes 必須與原始記錄保持一致，這樣刪除時才能找到反向關係
                $newRelation = [
                    'c_personid' => $c_kin_id,
                    'c_kin_id' => $c_personid,
                    'c_kin_code' => $new_c_kin_code,
                    'c_source' => $relation->c_source ?? null,
                    'c_pages' => $relation->c_pages ?? null,
                    'c_notes' => $relation->c_notes ?? null,
                    'c_autogen_notes' => $relation->c_autogen_notes ?? null,
                ];
                $newRelation = $this->toolsRepository->timestamp($newRelation, true);

                DB::table('KIN_DATA')->insert($newRelation);

                $this->operationRepository->store(
                    Auth::id(),
                    $newRelation['c_personid'],
                    1,
                    'KIN_DATA',
                    $newRelation['c_personid'] . '-' . $newRelation['c_kin_id'] . '-' . $newRelation['c_kin_code'],
                    $newRelation,
                    $relation
                );

                return response()->json([
                    'success' => true,
                    'message' => '親屬關係修復成功！已創建反向關係記錄。',
                    'original' => [
                        'c_personid' => $c_personid,
                        'c_kin_id' => $c_kin_id,
                        'c_kin_code' => $c_kin_code,
                    ],
                    'created' => [
                        'c_personid' => $c_kin_id,
                        'c_kin_id' => $c_personid,
                        'c_kin_code' => $new_c_kin_code,
                    ]
                ]);
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Kinship repair error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '修復過程中發生錯誤：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 修復社會關係
     */
    public function repairAssoc(Request $request)
    {
        $request->validate([
            'c_personid' => 'required|integer',
            'c_assoc_id' => 'required|integer',
            'c_assoc_code' => 'required|integer',
            'new_c_assoc_code' => 'required|integer',
        ]);

        $c_personid = $request->input('c_personid');
        $c_assoc_id = $request->input('c_assoc_id');
        $c_assoc_code = $request->input('c_assoc_code');
        $new_c_assoc_code = $request->input('new_c_assoc_code');

        try {
            // 確認人物與關係代碼存在，避免觸發外鍵例外
            $this->assertAssociationDependenciesExist($c_personid, $c_assoc_id, $c_assoc_code, $new_c_assoc_code);

            return DB::transaction(function () use ($c_personid, $c_assoc_id, $c_assoc_code, $new_c_assoc_code) {
                // 檢索當前單向關係
                $existingRelations = DB::table('ASSOC_DATA')
                    ->where('c_personid', $c_personid)
                    ->where('c_assoc_id', $c_assoc_id)
                    ->where('c_assoc_code', $c_assoc_code)
                    ->lockForUpdate()
                    ->get();

                // 如果找到多條記錄，提示用戶並暫停操作
                if ($existingRelations->count() > 1) {
                    return response()->json([
                        'success' => false,
                        'message' => '檢索到多條記錄（' . $existingRelations->count() . ' 條），請檢查輸入參數是否正確。',
                        'records' => $existingRelations->toArray()
                    ], 400);
                }

                // 如果找不到記錄
                if ($existingRelations->count() === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => '未找到符合條件的社會關係記錄。',
                    ], 404);
                }

                $relation = $existingRelations->first();
                $relationFirstYear = $relation->c_assoc_first_year ?? -9999;

                // 檢查反向關係是否已存在（涵蓋主鍵欄位，避免觸發重複鍵例外）
                $reverseExists = DB::table('ASSOC_DATA')
                    ->where('c_personid', $c_assoc_id)
                    ->where('c_assoc_id', $c_personid)
                    ->where('c_assoc_code', $new_c_assoc_code)
                    ->where('c_kin_code', $relation->c_kin_code)
                    ->where('c_kin_id', $relation->c_kin_id)
                    ->where('c_assoc_kin_code', $relation->c_assoc_kin_code)
                    ->where('c_assoc_kin_id', $relation->c_assoc_kin_id)
                    ->where('c_text_title', $relation->c_text_title)
                    ->where('c_assoc_first_year', $relationFirstYear)
                    ->exists();

                if ($reverseExists) {
                    return response()->json([
                        'success' => false,
                        'message' => '反向關係已存在，無需創建。',
                    ], 400);
                }

                // 創建反向關係記錄
                $newRelation = [
                    'c_personid' => $c_assoc_id,
                    'c_assoc_id' => $c_personid,
                    'c_assoc_code' => $new_c_assoc_code,
                    'c_kin_code' => $relation->c_kin_code,
                    'c_kin_id' => $relation->c_kin_id,
                    'c_assoc_kin_code' => $relation->c_assoc_kin_code,
                    'c_assoc_kin_id' => $relation->c_assoc_kin_id,
                    'c_text_title' => $relation->c_text_title,
                    'c_tertiary_personid' => $relation->c_tertiary_personid ?? null,
                    'c_tertiary_type_notes' => $relation->c_tertiary_type_notes ?? null,
                    'c_assoc_count' => $relation->c_assoc_count ?? 1,
                    'c_sequence' => $relation->c_sequence ?? 0,
                    'c_assoc_first_year' => $relationFirstYear,
                    'c_assoc_last_year' => $relation->c_assoc_last_year ?? null,
                    'c_assoc_fy_nh_code' => $relation->c_assoc_fy_nh_code ?? null,
                    'c_assoc_fy_nh_year' => $relation->c_assoc_fy_nh_year ?? null,
                    'c_assoc_fy_range' => $relation->c_assoc_fy_range ?? null,
                    'c_assoc_ly_nh_code' => $relation->c_assoc_ly_nh_code ?? null,
                    'c_assoc_ly_nh_year' => $relation->c_assoc_ly_nh_year ?? null,
                    'c_assoc_ly_range' => $relation->c_assoc_ly_range ?? null,
                    'c_assoc_fy_intercalary' => $relation->c_assoc_fy_intercalary ?? null,
                    'c_assoc_fy_month' => $relation->c_assoc_fy_month ?? null,
                    'c_assoc_fy_day' => $relation->c_assoc_fy_day ?? null,
                    'c_assoc_fy_day_gz' => $relation->c_assoc_fy_day_gz ?? null,
                    'c_assoc_ly_intercalary' => $relation->c_assoc_ly_intercalary ?? null,
                    'c_assoc_ly_month' => $relation->c_assoc_ly_month ?? null,
                    'c_assoc_ly_day' => $relation->c_assoc_ly_day ?? null,
                    'c_assoc_ly_day_gz' => $relation->c_assoc_ly_day_gz ?? null,
                    'c_addr_id' => $relation->c_addr_id ?? null,
                    'c_inst_code' => $relation->c_inst_code ?? 0,
                    'c_inst_name_code' => $relation->c_inst_name_code ?? 0,
                    'c_litgenre_code' => $relation->c_litgenre_code ?? null,
                    'c_occasion_code' => $relation->c_occasion_code ?? null,
                    'c_topic_code' => $relation->c_topic_code ?? null,
                    'c_assoc_claimer_id' => $relation->c_assoc_claimer_id ?? null,
                    'c_source' => $relation->c_source ?? null,
                    'c_pages' => $relation->c_pages ?? null,
                    'c_notes' => $relation->c_notes ?? null,
                ];
                $newRelation = $this->toolsRepository->timestamp($newRelation, true);

                DB::table('ASSOC_DATA')->insert($newRelation);

                $this->operationRepository->store(
                    Auth::id(),
                    $newRelation['c_personid'],
                    1,
                    'ASSOC_DATA',
                    $newRelation['c_personid'] . '-' . $newRelation['c_assoc_code'] . '-' . $newRelation['c_assoc_id'] . '-' . $newRelation['c_kin_code'] . '-' . $newRelation['c_kin_id'] . '-' . $newRelation['c_assoc_kin_code'] . '-' . $newRelation['c_assoc_kin_id'] . '-' . $newRelation['c_text_title'],
                    $newRelation,
                    $relation
                );

                return response()->json([
                    'success' => true,
                    'message' => '社會關係修復成功！已創建反向關係記錄。',
                    'original' => [
                        'c_personid' => $c_personid,
                        'c_assoc_id' => $c_assoc_id,
                        'c_assoc_code' => $c_assoc_code,
                    ],
                    'created' => [
                        'c_personid' => $c_assoc_id,
                        'c_assoc_id' => $c_personid,
                        'c_assoc_code' => $new_c_assoc_code,
                    ]
                ]);
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Association repair error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '修復過程中發生錯誤：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 確保輸入的親屬修復參數所需的主檔存在
     *
     * @throws ValidationException
     */
    protected function assertKinshipDependenciesExist(int $c_personid, int $c_kin_id, int $c_kin_code, int $new_c_kin_code): void
    {
        $missing = [];

        $biogs = DB::table('BIOG_MAIN')->whereIn('c_personid', [$c_personid, $c_kin_id])->pluck('c_personid')->all();
        foreach ([$c_personid, $c_kin_id] as $id) {
            if (!in_array($id, $biogs, true)) {
                $missing[] = "人物 {$id} 不存在";
            }
        }

        $kinCodes = DB::table('KINSHIP_CODES')->whereIn('c_kincode', [$c_kin_code, $new_c_kin_code])->pluck('c_kincode')->all();
        foreach ([$c_kin_code, $new_c_kin_code] as $code) {
            if (!in_array($code, $kinCodes, true)) {
                $missing[] = "親屬關係代碼 {$code} 不存在";
            }
        }

        if (!empty($missing)) {
            throw ValidationException::withMessages(['dependencies' => $missing]);
        }
    }

    /**
     * 確保輸入的社會關係修復參數所需的主檔存在
     *
     * @throws ValidationException
     */
    protected function assertAssociationDependenciesExist(int $c_personid, int $c_assoc_id, int $c_assoc_code, int $new_c_assoc_code): void
    {
        $missing = [];

        $biogs = DB::table('BIOG_MAIN')->whereIn('c_personid', [$c_personid, $c_assoc_id])->pluck('c_personid')->all();
        foreach ([$c_personid, $c_assoc_id] as $id) {
            if (!in_array($id, $biogs, true)) {
                $missing[] = "人物 {$id} 不存在";
            }
        }

        $assocCodes = DB::table('ASSOC_CODES')->whereIn('c_assoc_code', [$c_assoc_code, $new_c_assoc_code])->pluck('c_assoc_code')->all();
        foreach ([$c_assoc_code, $new_c_assoc_code] as $code) {
            if (!in_array($code, $assocCodes, true)) {
                $missing[] = "社會關係代碼 {$code} 不存在";
            }
        }

        if (!empty($missing)) {
            throw ValidationException::withMessages(['dependencies' => $missing]);
        }
    }
}
