<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class UnidirectionalRelationshipRepairController extends Controller
{
    public function __construct()
    {
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
            DB::beginTransaction();

            // 檢索當前單向關係
            $existingRelations = DB::table('KIN_DATA')
                ->where('c_personid', $c_personid)
                ->where('c_kin_id', $c_kin_id)
                ->where('c_kin_code', $c_kin_code)
                ->get();

            // 如果找到多條記錄，提示用戶並暫停操作
            if ($existingRelations->count() > 1) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => '檢索到多條記錄（' . $existingRelations->count() . ' 條），請檢查輸入參數是否正確。',
                    'records' => $existingRelations->toArray()
                ], 400);
            }

            // 如果找不到記錄
            if ($existingRelations->count() === 0) {
                DB::rollBack();
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
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => '反向關係已存在，無需創建。',
                ], 400);
            }

            // 創建反向關係記錄
            $newRelation = [
                'c_personid' => $c_kin_id,
                'c_kin_id' => $c_personid,
                'c_kin_code' => $new_c_kin_code,
                'c_source' => $relation->c_source ?? null,
                'c_pages' => $relation->c_pages ?? null,
                'c_notes' => $relation->c_notes ?? null,
                'c_autogen_notes' => '由單向關係修復工具自動創建',
                'c_created_by' => Auth::user()->name ?? 'system',
                'c_created_date' => now()->format('Y-m-d H:i:s'),
            ];

            DB::table('KIN_DATA')->insert($newRelation);

            DB::commit();

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

        } catch (Exception $e) {
            DB::rollBack();
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
            DB::beginTransaction();

            // 檢索當前單向關係
            $existingRelations = DB::table('ASSOC_DATA')
                ->where('c_personid', $c_personid)
                ->where('c_assoc_id', $c_assoc_id)
                ->where('c_assoc_code', $c_assoc_code)
                ->get();

            // 如果找到多條記錄，提示用戶並暫停操作
            if ($existingRelations->count() > 1) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => '檢索到多條記錄（' . $existingRelations->count() . ' 條），請檢查輸入參數是否正確。',
                    'records' => $existingRelations->toArray()
                ], 400);
            }

            // 如果找不到記錄
            if ($existingRelations->count() === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => '未找到符合條件的社會關係記錄。',
                ], 404);
            }

            $relation = $existingRelations->first();

            // 檢查反向關係是否已存在
            $reverseExists = DB::table('ASSOC_DATA')
                ->where('c_personid', $c_assoc_id)
                ->where('c_assoc_id', $c_personid)
                ->where('c_assoc_code', $new_c_assoc_code)
                ->where('c_kin_code', $relation->c_kin_code)
                ->where('c_kin_id', $relation->c_kin_id)
                ->where('c_assoc_kin_code', $relation->c_assoc_kin_code)
                ->where('c_assoc_kin_id', $relation->c_assoc_kin_id)
                ->where('c_text_title', $relation->c_text_title)
                ->exists();

            if ($reverseExists) {
                DB::rollBack();
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
                'c_assoc_first_year' => $relation->c_assoc_first_year ?? -9999,
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
                'c_created_by' => Auth::user()->name ?? 'system',
                'c_created_date' => now()->format('Y-m-d H:i:s'),
            ];

            DB::table('ASSOC_DATA')->insert($newRelation);

            DB::commit();

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

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Association repair error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '修復過程中發生錯誤：' . $e->getMessage(),
            ], 500);
        }
    }
}
