<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\NameSearchIndexService;
use Illuminate\Support\Facades\Schema;

class AltnameDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService,
        NameSearchIndexService $nameSearchIndexService
    ) {
        parent::__construct($operationRepository, $auditLogService);
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    protected function resourceName(): string {
        return 'altnames';
    }

    protected function tableName(): string {
        return 'ALTNAME_DATA';
    }

    protected function displayName(): string {
        return '別名';
    }

    protected function resourceAliases(): array {
        return ['altnames', 'altname', 'altname_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'];
    }

    protected function handleDirect(int $personId, array $targetPk, array $originalArray, string $comment): \Illuminate\Http\JsonResponse {
        $response = parent::handleDirect($personId, $targetPk, $originalArray, $comment);

        if ($response->getStatusCode() === 200) {
            $this->syncAltnameIndexAfterDelete($originalArray);
        }

        return $response;
    }

    protected function syncAltnameIndexAfterDelete(array $originalArray): void {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $name = $originalArray['c_alt_name_chn'] ?? null;
        $type = $originalArray['c_alt_name_type_code'] ?? null;
        $personId = $originalArray['c_personid'] ?? null;

        if (!empty($name) && $personId !== null) {
            $this->nameSearchIndexService->removeAltname($personId, $type, $name);
        }
    }
}
