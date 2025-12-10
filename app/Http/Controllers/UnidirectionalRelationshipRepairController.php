<?php

namespace App\Http\Controllers;

use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UnidirectionalRelationshipRepairController extends Controller {
    /**
     * 默認的 c_assoc_first_year 值，用於 ASSOC_DATA 記錄
     */
    public const DEFAULT_ASSOC_FIRST_YEAR = -9999;

    protected OperationRepository $operationRepository;
    protected ToolsRepository $toolsRepository;

    public function __construct(OperationRepository $operationRepository, ToolsRepository $toolsRepository) {
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

    public function index(Request $request) {
        return view('admin.unidirectional-relationship-repair', [
            'page_title' => '單向關係修復',
            'page_description' => '修復 CBDB 資料庫中的單向親屬關係和社會關係',
            'page_url' => route('admin.unidirectional-relationship-repair'),
        ]);
    }

    /**
     * 修復親屬關係
     */
    public function repairKinship(Request $request) {
        $params = $this->validateAndExtractParams($request, 'kinship');

        try {
            $this->assertKinshipDependenciesExist(
                $params['person_id'],
                $params['related_id'],
                $params['relation_code'],
                $params['new_relation_code']
            );

            return $this->executeRepair('kinship', $params);

        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->handleRepairError('Kinship', $e, $params);
        }
    }

    /**
     * 修復社會關係
     */
    public function repairAssoc(Request $request) {
        $params = $this->validateAndExtractParams($request, 'assoc');

        try {
            $this->assertAssociationDependenciesExist(
                $params['person_id'],
                $params['related_id'],
                $params['relation_code'],
                $params['new_relation_code']
            );

            return $this->executeRepair('assoc', $params);

        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->handleRepairError('Association', $e, $params);
        }
    }

    /**
     * 驗證並提取請求參數
     *
     * @param Request $request
     * @param string $type 'kinship' 或 'assoc'
     * @return array 標準化的參數數組
     */
    protected function validateAndExtractParams(Request $request, string $type): array {
        if ($type === 'kinship') {
            $request->validate([
                'c_personid' => 'required|integer',
                'c_kin_id' => 'required|integer',
                'c_kin_code' => 'required|integer',
                'new_c_kin_code' => 'required|integer',
            ]);

            return [
                'person_id' => $request->input('c_personid'),
                'related_id' => $request->input('c_kin_id'),
                'relation_code' => $request->input('c_kin_code'),
                'new_relation_code' => $request->input('new_c_kin_code'),
            ];
        } else {
            $request->validate([
                'c_personid' => 'required|integer',
                'c_assoc_id' => 'required|integer',
                'c_assoc_code' => 'required|integer',
                'new_c_assoc_code' => 'required|integer',
            ]);

            return [
                'person_id' => $request->input('c_personid'),
                'related_id' => $request->input('c_assoc_id'),
                'relation_code' => $request->input('c_assoc_code'),
                'new_relation_code' => $request->input('new_c_assoc_code'),
            ];
        }
    }

    /**
     * 執行關係修復的核心邏輯
     *
     * @param string $type 'kinship' 或 'assoc'
     * @param array $params 參數數組
     * @return \Illuminate\Http\JsonResponse
     */
    protected function executeRepair(string $type, array $params) {
        return DB::transaction(function () use ($type, $params) {
            $config = $this->getRepairConfig($type);

            // 檢索當前單向關係
            $existingRelations = DB::table($config['table'])
                ->where('c_personid', $params['person_id'])
                ->where($config['related_id_field'], $params['related_id'])
                ->where($config['relation_code_field'], $params['relation_code'])
                ->lockForUpdate()
                ->get();

            // 驗證記錄唯一性
            if ($count = $existingRelations->count()) {
                if ($count > 1) {
                    return $this->multipleRecordsError($type, $existingRelations, $count);
                }
            } else {
                return $this->recordNotFoundError($type);
            }

            $relation = $existingRelations->first();

            // 檢查反向關係是否已存在
            if ($this->reverseRelationExists($type, $relation, $params)) {
                return response()->json([
                    'success' => false,
                    'message' => '反向關係已存在，無需創建。',
                ], 400);
            }

            // 創建反向關係記錄
            $newRelation = $this->buildReverseRelation($type, $relation, $params);
            $newRelation = $this->toolsRepository->timestamp($newRelation, true);

            DB::table($config['table'])->insert($newRelation);

            // 記錄操作日誌
            $this->operationRepository->store(
                Auth::id(),
                $newRelation['c_personid'],
                1,
                $config['table'],
                $this->buildResourceId($type, $newRelation),
                $newRelation,
                $relation
            );

            return $this->successResponse($type, $params);
        });
    }

    /**
     * 獲取關係類型的配置
     */
    protected function getRepairConfig(string $type): array {
        return $type === 'kinship' ? [
            'table' => 'KIN_DATA',
            'related_id_field' => 'c_kin_id',
            'relation_code_field' => 'c_kin_code',
            'relation_name' => '親屬關係',
        ] : [
            'table' => 'ASSOC_DATA',
            'related_id_field' => 'c_assoc_id',
            'relation_code_field' => 'c_assoc_code',
            'relation_name' => '社會關係',
        ];
    }

    /**
     * 處理多條記錄錯誤
     */
    protected function multipleRecordsError(string $type, $records, int $count) {
        $config = $this->getRepairConfig($type);
        $mapper = $type === 'kinship'
            ? fn ($r) => [
                'c_personid' => $r->c_personid,
                'c_kin_id' => $r->c_kin_id,
                'c_kin_code' => $r->c_kin_code,
                'c_source' => $r->c_source,
                'c_created_by' => $r->c_created_by ?? null,
                'c_created_date' => $r->c_created_date ?? null,
            ]
            : fn ($r) => [
                'c_personid' => $r->c_personid,
                'c_assoc_id' => $r->c_assoc_id,
                'c_assoc_code' => $r->c_assoc_code,
                'c_text_title' => $r->c_text_title,
                'c_source' => $r->c_source,
                'c_created_by' => $r->c_created_by ?? null,
                'c_created_date' => $r->c_created_date ?? null,
            ];

        return response()->json([
            'success' => false,
            'message' => "檢索到多條記錄（{$count} 條），請檢查輸入參數是否正確。",
            'records' => $records->map($mapper)->toArray(),
        ], 400);
    }

    /**
     * 處理記錄未找到錯誤
     */
    protected function recordNotFoundError(string $type) {
        $config = $this->getRepairConfig($type);

        return response()->json([
            'success' => false,
            'message' => "未找到符合條件的{$config['relation_name']}記錄。",
        ], 404);
    }

    /**
     * 檢查反向關係是否已存在
     */
    protected function reverseRelationExists(string $type, $relation, array $params): bool {
        $config = $this->getRepairConfig($type);

        if ($type === 'kinship') {
            return DB::table('KIN_DATA')
                ->where('c_personid', $params['related_id'])
                ->where('c_kin_id', $params['person_id'])
                ->where('c_kin_code', $params['new_relation_code'])
                ->exists();
        } else {
            $relationFirstYear = $relation->c_assoc_first_year ?? self::DEFAULT_ASSOC_FIRST_YEAR;

            return DB::table('ASSOC_DATA')
                ->where('c_personid', $params['related_id'])
                ->where('c_assoc_id', $params['person_id'])
                ->where('c_assoc_code', $params['new_relation_code'])
                ->where('c_kin_code', $relation->c_kin_code)
                ->where('c_kin_id', $relation->c_kin_id)
                ->where('c_assoc_kin_code', $relation->c_assoc_kin_code)
                ->where('c_assoc_kin_id', $relation->c_assoc_kin_id)
                ->where('c_text_title', $relation->c_text_title)
                ->where('c_assoc_first_year', $relationFirstYear)
                ->where('c_assoc_count', $relation->c_assoc_count ?? 1)
                ->where('c_sequence', $relation->c_sequence ?? 0)
                ->exists();
        }
    }

    /**
     * 構建反向關係記錄
     */
    protected function buildReverseRelation(string $type, $relation, array $params): array {
        if ($type === 'kinship') {
            return [
                'c_personid' => $params['related_id'],
                'c_kin_id' => $params['person_id'],
                'c_kin_code' => $params['new_relation_code'],
                'c_source' => $relation->c_source ?? null,
                'c_pages' => $relation->c_pages ?? null,
                'c_notes' => $relation->c_notes ?? null,
                'c_autogen_notes' => $relation->c_autogen_notes ?? null,
            ];
        } else {
            return [
                'c_personid' => $params['related_id'],
                'c_assoc_id' => $params['person_id'],
                'c_assoc_code' => $params['new_relation_code'],
                'c_kin_code' => $relation->c_kin_code,
                'c_kin_id' => $relation->c_kin_id,
                'c_assoc_kin_code' => $relation->c_assoc_kin_code,
                'c_assoc_kin_id' => $relation->c_assoc_kin_id,
                'c_text_title' => $relation->c_text_title,
                'c_tertiary_personid' => $relation->c_tertiary_personid ?? null,
                'c_tertiary_type_notes' => $relation->c_tertiary_type_notes ?? null,
                'c_assoc_count' => $relation->c_assoc_count ?? 1,
                'c_sequence' => $relation->c_sequence ?? 0,
                'c_assoc_first_year' => $relation->c_assoc_first_year ?? self::DEFAULT_ASSOC_FIRST_YEAR,
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
        }
    }

    /**
     * 構建資源 ID
     */
    protected function buildResourceId(string $type, array $relation): string {
        if ($type === 'kinship') {
            return "{$relation['c_personid']}-{$relation['c_kin_id']}-{$relation['c_kin_code']}";
        } else {
            return "{$relation['c_personid']}-{$relation['c_assoc_code']}-{$relation['c_assoc_id']}-{$relation['c_kin_code']}-{$relation['c_kin_id']}-{$relation['c_assoc_kin_code']}-{$relation['c_assoc_kin_id']}-{$relation['c_text_title']}";
        }
    }

    /**
     * 返回成功響應
     */
    protected function successResponse(string $type, array $params) {
        $config = $this->getRepairConfig($type);
        $originalKey = $type === 'kinship' ? 'c_kin_id' : 'c_assoc_id';
        $codeKey = $config['relation_code_field'];

        return response()->json([
            'success' => true,
            'message' => "{$config['relation_name']}修復成功！已創建反向關係記錄。",
            'original' => [
                'c_personid' => $params['person_id'],
                $originalKey => $params['related_id'],
                $codeKey => $params['relation_code'],
            ],
            'created' => [
                'c_personid' => $params['related_id'],
                $originalKey => $params['person_id'],
                $codeKey => $params['new_relation_code'],
            ],
        ]);
    }

    /**
     * 處理修復錯誤
     */
    protected function handleRepairError(string $typeName, Exception $e, array $params) {
        $inputKeys = $typeName === 'Kinship'
            ? ['c_personid', 'c_kin_id', 'c_kin_code', 'new_c_kin_code']
            : ['c_personid', 'c_assoc_id', 'c_assoc_code', 'new_c_assoc_code'];

        $input = [];
        $fieldMap = [
            'person_id' => $inputKeys[0],
            'related_id' => $inputKeys[1],
            'relation_code' => $inputKeys[2],
            'new_relation_code' => $inputKeys[3],
        ];

        foreach ($fieldMap as $key => $originalKey) {
            $input[$originalKey] = $params[$key];
        }

        Log::error("{$typeName} repair error", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'input' => $input,
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => false,
            'message' => '修復過程中發生錯誤：' . $e->getMessage(),
        ], 500);
    }

    /**
     * 確保輸入的親屬修復參數所需的主檔存在
     *
     * @throws ValidationException
     */
    protected function assertKinshipDependenciesExist(int $c_personid, int $c_kin_id, int $c_kin_code, int $new_c_kin_code): void {
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
    protected function assertAssociationDependenciesExist(int $c_personid, int $c_assoc_id, int $c_assoc_code, int $new_c_assoc_code): void {
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
