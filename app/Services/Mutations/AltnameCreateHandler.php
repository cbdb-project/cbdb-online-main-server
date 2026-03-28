<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\BracketNormalizer;
use App\Services\NameSearchIndexService;
use Illuminate\Support\Facades\Schema;

class AltnameCreateHandler extends AbstractPersonSubresourceCreateHandler {
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

    protected function allowedFields(): array {
        return [
            'c_personid',
            'c_alt_name_chn',
            'c_alt_name_type_code',
            'c_alt_name',
            'c_source',
            'c_pages',
            'c_notes',
            'c_sequence',
            'c_alt_name_pinyin',
            'c_alt_name_pinyin2',
            'c_alt_name_pinyin3',
            'c_alt_name_role',
        ];
    }

    protected function preprocessCreateData(array $data): array {
        $data = BracketNormalizer::normalizeAltname($data);

        return $this->normalizeSentinelValues($data, ['c_alt_name_type_code', 'c_source']);
    }

    protected function handleDirect(int $personId, array $actualPk, array $rowData, string $comment): \Illuminate\Http\JsonResponse {
        $response = parent::handleDirect($personId, $actualPk, $rowData, $comment);

        if ($response->getStatusCode() === 200) {
            $this->syncAltnameIndexAfterCreate($rowData);
        }

        return $response;
    }

    protected function syncAltnameIndexAfterCreate(array $rowData): void {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $name = $rowData['c_alt_name_chn'] ?? null;
        $type = $rowData['c_alt_name_type_code'] ?? null;
        $personId = $rowData['c_personid'] ?? null;

        if (!empty($name) && $personId !== null) {
            $this->nameSearchIndexService->indexAltname($personId, $type, $name);
        }
    }
}
