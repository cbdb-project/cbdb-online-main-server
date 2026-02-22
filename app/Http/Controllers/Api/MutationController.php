<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BiogMainRepository;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MutationController extends Controller {
    protected BiogMainRepository $biogMainRepository;
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(BiogMainRepository $biogMainRepository, NameSearchIndexService $nameSearchIndexService) {
        $this->biogMainRepository = $biogMainRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    public function store(Request $request): JsonResponse {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        $resource = strtolower((string) ($payload['resource'] ?? ''));
        $mode = strtolower((string) ($payload['mode'] ?? 'direct'));
        $operation = strtolower((string) ($payload['operation'] ?? 'update'));
        $personId = $payload['person_id'] ?? null;
        $targetPk = $payload['target']['pk'] ?? null;
        $changes = $payload['changes'] ?? null;
        $meta = $payload['meta'] ?? [];

        if (!is_array($targetPk)) {
            return $this->errorResponse('缺少 target.pk', 422, ['target.pk' => ['required']]);
        }

        if (!is_array($changes)) {
            return $this->errorResponse('缺少 changes', 422, ['changes' => ['required']]);
        }

        if ($personId === null || $personId === '') {
            return $this->errorResponse('缺少 person_id', 422, ['person_id' => ['required']]);
        }

        if ($resource === 'altnames' && $operation === 'update' && $mode === 'direct') {
            return $this->handleAltnameDirectUpdate((int) $personId, $targetPk, $changes, is_array($meta) ? $meta : []);
        }

        if (in_array($resource, ['possession', 'possessions'], true) && $operation === 'update' && $mode === 'direct') {
            return $this->handlePossessionDirectUpdate((int) $personId, $targetPk, $changes, is_array($meta) ? $meta : []);
        }

        return $this->errorResponse('目前尚未支援此變更模式', 501, [
            'resource' => $resource,
            'mode' => $mode,
            'operation' => $operation,
        ]);
    }

    protected function handleAltnameDirectUpdate(int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $user = Auth::user();
        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        if (!$user->isActive()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }

        if (!$user->canWriteDirectly()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'ALTNAME_DATA');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 預留未來 proposal/direct 共用格式：此處允許任意 changes，但可先限制空提交
        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        $original = $this->biogMainRepository->altnameById($targetPk);
        if (!$original) {
            return $this->errorResponse('ALTNAME_DATA 記錄不存在', 404);
        }

        // 只保留目前可安全從列表快速更新的欄位；未來要開放任意欄位時可擴充白名單/資源規則
        $allowedQuickFields = ['c_sequence'];
        $updateData = array_intersect_key($changes, array_flip($allowedQuickFields));
        if (empty($updateData)) {
            return $this->errorResponse('目前此接口至少需包含可更新欄位（例如 c_sequence）', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        $proxy = Request::create('/api/v2/mutate', 'PATCH', $updateData);
        if (isset($meta['comment']) && is_string($meta['comment']) && trim($meta['comment']) !== '') {
            $proxy->request->set('__proposal_comment', trim($meta['comment']));
        }

        try {
            $newPk = $this->biogMainRepository->altnameUpdateById($proxy, $personId, $targetPk);
        } catch (\Throwable $e) {
            return $this->errorResponse('更新失敗：'.$e->getMessage(), 500);
        }

        if (!$newPk) {
            return $this->errorResponse('ALTNAME_DATA 記錄不存在', 404);
        }

        // 與 updateQuery 保持一致：若名稱或類型變更，更新索引（雖然本頁目前只改 c_sequence）
        $this->syncAltnameIndexAfterUpdate($original, $updateData, $targetPk, $newPk);

        $updated = $this->biogMainRepository->altnameById($newPk);

        return response()->json([
            'ok' => true,
            'resource' => 'altnames',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => $newPk,
                'updated_fields' => array_keys($updateData),
                'row' => $updated ? (array) $updated : null,
            ],
        ]);
    }

    protected function handlePossessionDirectUpdate(int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $user = Auth::user();
        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        if (!$user->isActive()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }

        if (!$user->canWriteDirectly()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'POSSESSION_DATA');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        $recordId = $targetPk['c_possession_record_id'] ?? null;
        $original = $recordId !== null ? DB::table('POSSESSION_DATA')->where('c_possession_record_id', $recordId)->first() : null;
        if (!$original) {
            return $this->errorResponse('POSSESSION_DATA 記錄不存在', 404);
        }

        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $allowedQuickFields = ['c_sequence'];
        $updateData = array_intersect_key($changes, array_flip($allowedQuickFields));
        if (empty($updateData)) {
            return $this->errorResponse('目前此接口至少需包含可更新欄位（例如 c_sequence）', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        // possessionUpdateById 會讀取 c_addr_id 進行地址關聯維護；快速改次序時沿用原值即可。
        $proxy = Request::create('/api/v2/mutate', 'PATCH', array_merge($updateData, [
            'c_addr_id' => $original->c_addr_id ?? 0,
            'c_source' => $original->c_source ?? 0,
            'c_measure_code' => $original->c_measure_code ?? 0,
            'c_possession_act_code' => $original->c_possession_act_code ?? 0,
        ]));
        if (isset($meta['comment']) && is_string($meta['comment']) && trim($meta['comment']) !== '') {
            $proxy->request->set('__proposal_comment', trim($meta['comment']));
        }

        try {
            $this->biogMainRepository->possessionUpdateById($proxy, $personId, $recordId);
        } catch (\Throwable $e) {
            return $this->errorResponse('更新失敗：'.$e->getMessage(), 500);
        }

        $updated = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $recordId)->first();

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_possession_record_id' => $recordId],
                'updated_fields' => array_keys($updateData),
                'row' => $updated ? (array) $updated : null,
            ],
        ]);
    }

    protected function syncAltnameIndexAfterUpdate($original, array $changes, array $targetPk, array $newPk): void {
        if (!$original || !Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $newName = $changes['c_alt_name_chn'] ?? ($targetPk['c_alt_name_chn'] ?? null);
        $newType = $changes['c_alt_name_type_code'] ?? ($targetPk['c_alt_name_type_code'] ?? null);

        $nameChanged = ($original->c_alt_name_chn ?? null) !== $newName;
        $typeChanged = ($original->c_alt_name_type_code ?? null) !== $newType;

        if (!$nameChanged && !$typeChanged) {
            return;
        }

        if (!empty($original->c_alt_name_chn)) {
            $this->nameSearchIndexService->removeAltname(
                $original->c_personid,
                $original->c_alt_name_type_code,
                $original->c_alt_name_chn
            );
        }

        if (!empty($newName)) {
            $this->nameSearchIndexService->indexAltname(
                $newPk['c_personid'] ?? $targetPk['c_personid'],
                $newType,
                $newName
            );
        }
    }

    protected function errorResponse(string $message, int $status, array $errors = []): JsonResponse {
        $body = ['ok' => false, 'message' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
