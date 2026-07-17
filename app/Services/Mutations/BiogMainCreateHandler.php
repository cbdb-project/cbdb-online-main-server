<?php

namespace App\Services\Mutations;

use App\Models\BiogMain;
use App\Repositories\BiogMainRepository;
use App\Services\CharVariantMapService;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * BIOG_MAIN（人物主檔）create handler（direct 模式）。
 *
 * 對齊 legacy BasicInformationController::store() 語意：
 * - c_personid 由 client 提供（非自動配發）。
 * - 驗證：非 null、非 0、不得已存在、(int)c_personid - max(c_personid) <= 10000。
 * - 委派 BiogMainRepository::store($request) 寫入（內含事務 + operation + audit）。
 * - 若 CBDB__NAME_FTS 存在，reindexPerson()。
 *
 * proposal 模式：本 handler 回 501。人物層級的提案走 legacy crowdsourcing_status
 * （見 BasicInformationController::store() 對眾包用戶的處理），非本次 v2 範圍。
 */
class BiogMainCreateHandler extends AbstractMutationHandler {
    /**
     * 允許寫入的欄位白名單。
     *
     * 與 update handler（BiogMainMutationHandler::BLOCKED_FIELDS）相反，create 允許
     * 設定 c_personid / c_name*（因為人物主檔在新增時必須帶入主鍵與姓名），
     * 但仍禁止稽核欄位（由 ToolsRepository::timestamp 維護）。
     */
    protected const ALLOWED_FIELDS = [
        'c_personid',
        'c_name_chn',
        'c_name',
        'c_name_proper',
        'c_name_rm',
        'c_surname_chn',
        'c_mingzi_chn',
        'c_surname',
        'c_mingzi',
        'c_surname_proper',
        'c_mingzi_proper',
        'c_surname_rm',
        'c_mingzi_rm',
        'c_female',
        'c_index_year',
        'c_index_year_type_code',
        'c_index_year_source_id',
        'c_index_addr_id',
        'c_index_addr_type_code',
        'c_dy',
        'c_by_intercalary',
        'c_by_nh_code',
        'c_by_nh_year',
        'c_by_range',
        'c_by_yymm',
        'c_by_yymm_day',
        'c_by_day_gz',
        'c_dy_intercalary',
        'c_dy_nh_code',
        'c_dy_nh_year',
        'c_dy_range',
        'c_dy_yymm',
        'c_dy_yymm_day',
        'c_dy_day_gz',
        'c_death_age',
        'c_death_age_range',
        'c_fl_earliest_year',
        'c_fl_ey_nh_code',
        'c_fl_ey_nh_year',
        'c_fl_ey_notes',
        'c_fl_latest_year',
        'c_fl_ly_nh_code',
        'c_fl_ly_nh_year',
        'c_fl_ly_notes',
        'c_ethnicity_code',
        'c_household_status_code',
        'c_tribe',
        'c_choronym_code',
        'c_notes',
        'c_self_bio',
    ];

    protected const BLOCKED_FIELDS = [
        'c_created_by',
        'c_created_date',
        'c_modified_by',
        'c_modified_date',
    ];

    protected BiogMainRepository $biogMainRepository;
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(
        BiogMainRepository $biogMainRepository,
        NameSearchIndexService $nameSearchIndexService
    ) {
        $this->biogMainRepository = $biogMainRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['basicinformation', 'biogmain', 'biog_main'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'create';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // proposal 模式：人物層級提案走 legacy crowdsourcing_status，非本次 v2 範圍。
        if ($mode === 'proposal') {
            return $this->errorResponse('人物主檔提案模式尚未於 v2 實作，請改用 legacy 眾包流程', 501, [
                'mode' => ['proposal_not_supported'],
            ]);
        }

        $authorizationError = $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'BIOG_MAIN');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if ((string) ($targetPk['c_personid'] ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與 target.pk.c_personid 不一致', 422, [
                'person_id' => ['mismatch'],
            ]);
        }

        // 組出完整 row：以 PK 為基底，合併 changes（限白名單，並剔除稽核欄）
        $rowData = array_merge($targetPk, $changes);
        $allowed = array_diff(self::ALLOWED_FIELDS, self::BLOCKED_FIELDS);
        $rowData = array_intersect_key($rowData, array_flip($allowed));
        $rowData['c_personid'] = $personId;

        // 對齊 legacy store() 的 c_personid 驗證
        $validationError = $this->validatePersonId($personId);
        if ($validationError) {
            return $validationError;
        }

        // 構造 Request 委派給 repository（內含事務 + operation + audit）
        $proxy = Request::create('/api/v2/create', 'POST', $rowData);

        try {
            $storeResult = $this->biogMainRepository->store($proxy);
        } catch (\Throwable $e) {
            return $this->errorResponse('新增失敗：'.$e->getMessage(), 500);
        }

        $flight = $storeResult['model'];
        $variantReplaced = $storeResult['replaced'];

        if ($flight && Schema::hasTable('CBDB__NAME_FTS')) {
            $this->nameSearchIndexService->reindexPerson($flight);
        }

        $row = BiogMain::find($personId);

        $response = [
            'ok' => true,
            'resource' => 'basicinformation',
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => ['c_personid' => $personId],
                'row' => $row ? $row->toArray() : ($flight ? $flight->toArray() : null),
            ],
        ];

        $notices = CharVariantMapService::buildNotices($variantReplaced);
        if ($notices !== []) {
            $response['notices'] = $notices;
        }

        return response()->json($response);
    }

    /**
     * 對齊 legacy BasicInformationController::store() 的 c_personid 驗證：
     * - 非 null、非 0
     * - 不得已存在
     * - (int)c_personid - max(c_personid) <= 10000（不得過大）
     */
    protected function validatePersonId(int $personId): ?JsonResponse {
        if ($personId === 0) {
            return $this->errorResponse('person id 未填或為 0', 422, ['c_personid' => ['required']]);
        }

        if (BiogMain::where('c_personid', $personId)->exists()) {
            return $this->errorResponse('person id 已存在', 422, ['c_personid' => ['exists']]);
        }

        $max = (int) BiogMain::max('c_personid');
        if ($personId - $max > 10000) {
            return $this->errorResponse('person id 過大', 422, ['c_personid' => ['too_large']]);
        }

        return null;
    }
}
