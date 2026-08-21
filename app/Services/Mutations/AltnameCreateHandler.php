<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\BracketNormalizer;
use App\Services\NameSearchIndexService;
use App\Support\PinyinUmlaut;
use Illuminate\Http\JsonResponse;
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

        // 保存時拼音 v→ü 歸一化（Tier 1；僅 c_alt_name_pinyin/2/3。c_alt_name 走前端 Tier 2、不在此轉）
        $data = PinyinUmlaut::normalizeFields($data, PinyinUmlaut::ALTNAME_PINYIN_V_FIELDS);

        $data = $this->normalizeSentinelValues($data, ['c_alt_name_type_code', 'c_source']);

        // #71：非 PK 碼欄 c_source 完全幂等（null/''/-999→0），對齊已修的 AltnameMutationHandler。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        // 異體字落地替換（c_alt_name_chn 為 strict 模式）已由基底類別的通用掛鉤在
        // 本方法之前完成，替換紀錄收在 $this->variantReplaced、通知由 handle() 掛上。
        // 這裡**不要**再自己呼叫 CharVariantMapService：通用掛鉤先跑過，值已是參考形，
        // 再呼叫一次的 replaced 恆為 []，assign 回去會讓別名替換通知靜默消失。

        return $data;
    }

    protected function handleDirect(int $personId, array $actualPk, array $rowData, string $comment): JsonResponse {
        $response = parent::handleDirect($personId, $actualPk, $rowData, $comment);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $this->syncAltnameIndexAfterCreate($rowData);

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
