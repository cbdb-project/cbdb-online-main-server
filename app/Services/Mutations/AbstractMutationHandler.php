<?php

namespace App\Services\Mutations;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

abstract class AbstractMutationHandler implements MutationHandlerInterface {
    /**
     * 非純量輸入（JSON 陣列/物件）折成 null，讓後續 required/invalid 校驗以 422 擋下，
     * 避免巢狀結構流入 whereIn/insert 造成 500，或經 (int) 轉型矇混成合法代碼。
     */
    protected function scalarOrNull(mixed $value): mixed {
        return is_scalar($value) ? $value : null;
    }

    protected function errorResponse(string $message, int $status, array $errors = []): JsonResponse {
        $body = ['ok' => false, 'message' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    protected function authorizeProposal(): ?JsonResponse {
        $user = Auth::user();
        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        if (!$user->isActive()) {
            return $this->errorResponse('該使用者沒有權限，請聯繫管理員', 403);
        }

        return null;
    }

    protected function authorizeDirect(): ?JsonResponse {
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

        return null;
    }
}
