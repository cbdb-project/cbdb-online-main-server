<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mutations\MutationHandlerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MutationController extends Controller {
    protected MutationHandlerRegistry $handlerRegistry;

    public function __construct(MutationHandlerRegistry $handlerRegistry) {
        $this->handlerRegistry = $handlerRegistry;
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

    protected function errorResponse(string $message, int $status, array $errors = []): JsonResponse {
        $body = ['ok' => false, 'message' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
