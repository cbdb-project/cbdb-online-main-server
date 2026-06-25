<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mutations\MutationHandlerRegistry;
use App\Services\Mutations\MutationReadService;
use App\Services\RelationshipMirrorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MutationController extends Controller {
    protected MutationHandlerRegistry $handlerRegistry;
    protected MutationReadService $readService;
    protected RelationshipMirrorService $mirrorService;

    public function __construct(MutationHandlerRegistry $handlerRegistry, MutationReadService $readService, RelationshipMirrorService $mirrorService) {
        $this->handlerRegistry = $handlerRegistry;
        $this->readService = $readService;
        $this->mirrorService = $mirrorService;
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

        $handler = $this->handlerRegistry->resolve($resource, $mode, $operation);
        if (!$handler) {
            return $this->errorResponse('目前尚未支援此變更模式', 501, [
                'resource' => $resource,
                'mode' => $mode,
                'operation' => $operation,
            ]);
        }

        return $handler->handle($resource, $mode, $operation, (int) $personId, $targetPk, $changes, is_array($meta) ? $meta : []);
    }

    public function get(Request $request): JsonResponse {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        $resource = strtolower((string) ($payload['resource'] ?? ''));
        $personId = $payload['person_id'] ?? null;
        $targetPk = $payload['target']['pk'] ?? null;

        if (!Auth::check()) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $user = Auth::user();
        if (!$user || !$user->isActive()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }

        if (!is_array($targetPk)) {
            return $this->errorResponse('缺少 target.pk', 422, ['target.pk' => ['required']]);
        }

        if ($personId === null || $personId === '') {
            return $this->errorResponse('缺少 person_id', 422, ['person_id' => ['required']]);
        }

        $definition = $this->readService->resolve($resource);
        if (!$definition) {
            return $this->errorResponse('目前尚未支援此取得模式', 501, [
                'resource' => $resource,
                'operation' => 'get',
            ]);
        }

        try {
            $this->readService->validatePk($targetPk, $definition['table']);
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        $query = DB::table($definition['table']);
        foreach ($definition['key_columns'] as $column) {
            $query->where($column, $targetPk[$column]);
        }

        $row = $query->first();
        if (!$row) {
            return $this->errorResponse($definition['table'] . ' 記錄不存在', 404);
        }

        if ($definition['person_id_column'] !== null) {
            $rowPersonId = $row->{$definition['person_id_column']} ?? null;
            if ((string) $rowPersonId !== (string) $personId) {
                return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
            }
        }

        return response()->json([
            'ok' => true,
            'resource' => $definition['resource'],
            'mode' => 'direct',
            'operation' => 'get',
            'result' => [
                'pk' => $targetPk,
                'row' => (array) $row,
            ],
        ]);
    }

    /**
     * #79（§4-A / §5-B）：偵測「對面互逆鏡像」現況。編輯器載入某社會關係／親屬列時呼叫，依命中數讓前端決定：
     * count==0 ⇒ 缺邊（提示行內補建）；==1 ⇒ 正常；>1 ⇒ 一對多/多對多（提示人工裁決）。
     *
     * 僅 canWriteDirectly() 為真者觸發偵測（與補建/裁決權限一致，§2.4/§3）；其餘回 detection=false（前端不提示）。
     * 純讀取、不寫入。請求：{resource, person_id(本人), forward:{opposite_id(對方), forward_code(正向碼),
     *   kin: autogen_notes / assoc: text_title, first_year}}。
     */
    public function oppositeEdges(Request $request): JsonResponse {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        if (!Auth::check()) {
            return $this->errorResponse('Unauthenticated.', 401);
        }
        $user = Auth::user();
        if (!$user || !$user->isActive()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }
        // §3：僅可直接寫入者觸發偵測（無編輯權限者不提示、亦不經此偵測）。
        if (!$user->canWriteDirectly()) {
            return response()->json(['ok' => true, 'detection' => false]);
        }

        $resource = strtolower((string) ($payload['resource'] ?? ''));
        $type = in_array($resource, ['kinship', 'kin', 'kin_data'], true) ? 'kinship'
            : (in_array($resource, ['associations', 'association', 'assoc', 'assoc_data'], true) ? 'association' : null);
        if ($type === null) {
            return $this->errorResponse('不支援的 resource', 422, ['resource' => [$resource]]);
        }

        $personId = $payload['person_id'] ?? null;
        $fwd = $payload['forward'] ?? null;
        if (!is_array($fwd) || !is_numeric($personId)) {
            return $this->errorResponse('缺少或無效的 person_id / forward', 422, ['forward' => ['required']]);
        }
        // 數值性檢核：opposite_id / forward_code 須為數字。非數字（如壞碼字串）會被靜默轉 0、誤判為「缺邊」，故擋成 422，
        // 避免「無效碼」與「真缺邊」混淆（codex/review MINOR）。
        if (!is_numeric($fwd['opposite_id'] ?? null) || !is_numeric($fwd['forward_code'] ?? null)) {
            return $this->errorResponse('forward.opposite_id / forward.forward_code 須為數字', 422, ['forward' => ['numeric']]);
        }

        $locator = $type === 'kinship'
            ? [
                'person_id' => (int) $personId,
                'opposite_id' => (int) $fwd['opposite_id'],
                'autogen_notes' => $fwd['autogen_notes'] ?? null,
                'forward_code' => $fwd['forward_code'],
            ]
            : [
                'person_id' => (int) $personId,
                'opposite_id' => (int) $fwd['opposite_id'],
                'text_title' => $fwd['text_title'] ?? '',
                'first_year' => $fwd['first_year'] ?? RelationshipMirrorService::DEFAULT_ASSOC_FIRST_YEAR,
                'forward_code' => $fwd['forward_code'],
            ];

        $edges = $this->mirrorService->locateOppositeEdges($type, $locator);
        $count = $edges->count();

        return response()->json([
            'ok' => true,
            'detection' => true,
            'resource' => $type,
            'count' => $count,
            'status' => $count === 0 ? 'missing' : ($count === 1 ? 'single' : 'multiple'),
            'edges' => $this->mirrorService->formatRecords($type, $edges),
        ]);
    }

    public function create(Request $request): JsonResponse {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        $resource = strtolower((string) ($payload['resource'] ?? ''));
        $mode = strtolower((string) ($payload['mode'] ?? 'direct'));
        $personId = $payload['person_id'] ?? null;
        $targetPk = $payload['target']['pk'] ?? null;
        $changes = $payload['changes'] ?? [];
        $meta = $payload['meta'] ?? [];

        if (!is_array($targetPk)) {
            return $this->errorResponse('缺少 target.pk', 422, ['target.pk' => ['required']]);
        }

        if ($personId === null || $personId === '') {
            return $this->errorResponse('缺少 person_id', 422, ['person_id' => ['required']]);
        }

        $handler = $this->handlerRegistry->resolve($resource, $mode, 'create');
        if (!$handler) {
            return $this->errorResponse('目前尚未支援此變更模式', 501, [
                'resource' => $resource,
                'mode' => $mode,
                'operation' => 'create',
            ]);
        }

        return $handler->handle($resource, $mode, 'create', (int) $personId, $targetPk, is_array($changes) ? $changes : [], is_array($meta) ? $meta : []);
    }

    public function delete(Request $request): JsonResponse {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        $resource = strtolower((string) ($payload['resource'] ?? ''));
        $mode = strtolower((string) ($payload['mode'] ?? 'direct'));
        $personId = $payload['person_id'] ?? null;
        $targetPk = $payload['target']['pk'] ?? null;
        $meta = $payload['meta'] ?? [];

        if (!is_array($targetPk)) {
            return $this->errorResponse('缺少 target.pk', 422, ['target.pk' => ['required']]);
        }

        if ($personId === null || $personId === '') {
            return $this->errorResponse('缺少 person_id', 422, ['person_id' => ['required']]);
        }

        $handler = $this->handlerRegistry->resolve($resource, $mode, 'delete');
        if (!$handler) {
            return $this->errorResponse('目前尚未支援此變更模式', 501, [
                'resource' => $resource,
                'mode' => $mode,
                'operation' => 'delete',
            ]);
        }

        return $handler->handle($resource, $mode, 'delete', (int) $personId, $targetPk, [], is_array($meta) ? $meta : []);
    }

    protected function errorResponse(string $message, int $status, array $errors = []): JsonResponse {
        $body = ['ok' => false, 'message' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
