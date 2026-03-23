<?php

namespace App\Services\Mutations;

use Illuminate\Http\JsonResponse;

interface MutationHandlerInterface {
    public function supports(string $resource, string $mode, string $operation): bool;

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse;
}
