<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mutations\MutationHandlerRegistry;
use App\Services\Mutations\MutationReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MutationController extends Controller {
    protected MutationHandlerRegistry $handlerRegistry;
    protected MutationReadService $readService;

    public function __construct(MutationHandlerRegistry $handlerRegistry, MutationReadService $readService) {
        $this->handlerRegistry = $handlerRegistry;
        $this->readService = $readService;
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

        // proposal delete 尚未實作
        if ($mode === 'proposal') {
            return $this->errorResponse('proposal delete 尚未實作', 501, [
                'mode' => 'proposal',
                'operation' => 'delete',
            ]);
        }

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
