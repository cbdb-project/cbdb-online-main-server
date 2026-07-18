<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Services\Import\EntityAggregateService;
use App\Services\Import\OfficeImportService;
use App\Services\Mutations\Concerns\ResolvesOfficeAggregateInput;

/**
 * 「官職實體」的聚合定義（resource=office）：把原 OfficeImportHandler／OfficeUpdateHandler／
 * OfficeDeleteHandler 三者的實體專屬部分（校驗、護欄、回應成形）收斂於此，骨架交給通用 handler。
 *
 * create／update 共用 ResolvesOfficeAggregateInput 校驗；delete 有引用護欄（被人物任官引用回 409）。
 * 回應形狀與原 handler 逐位一致（測試斷言精確 JSON）。
 */
class OfficeAggregateDefinition extends AbstractEntityAggregateDefinition {
    use ResolvesOfficeAggregateInput;

    public function __construct(protected OfficeImportService $officeService) {
    }

    public function resources(): array {
        return ['office', 'offices', 'office-load'];
    }

    public function operations(): array {
        return ['create', 'update', 'delete'];
    }

    public function pkField(): string {
        return 'c_office_id';
    }

    public function resourceName(): string {
        return 'office';
    }

    public function notFoundMessage(): string {
        return '找不到官職';
    }

    public function service(): EntityAggregateService {
        return $this->officeService;
    }

    public function validate(string $operation, array $changes): array {
        return $this->validateOfficeAggregate($changes, $this->officeService);
    }

    public function guardWrite(string $operation, ?int $id, array $input, ?array $existing): ?array {
        if ($operation === 'delete') {
            $refCount = $this->officeService->referenceCount((int) $id);
            if ($refCount > 0) {
                return [
                    "此官職仍被 {$refCount} 筆人物任官引用，無法刪除",
                    409,
                    ['c_office_id' => ['referenced_by_postings'], 'reference_count' => [$refCount]],
                ];
            }
        }

        return null;
    }

    public function result(string $operation, ?int $id, array $input, array $serviceResult): array {
        if ($operation === 'create') {
            return [
                'pk' => ['c_office_id' => $serviceResult['office_id']],
                'status' => 'created',
                'operation_id' => $serviceResult['operation_id_office'],
                'row' => [
                    'c_office_id' => $serviceResult['office_id'],
                    'c_office_chn' => $input['name'],
                    'c_office_pinyin' => $serviceResult['pinyin'],
                    'type_ids' => $serviceResult['type_ids'],
                ],
            ];
        }

        if ($operation === 'update') {
            return [
                'pk' => ['c_office_id' => $id],
                'status' => 'updated',
                'operation_id' => $serviceResult['operation_id_office'],
                'types_added' => $serviceResult['types_added'],
                'types_removed' => $serviceResult['types_removed'],
                'row' => [
                    'c_office_id' => $id,
                    'c_office_chn' => $input['name'],
                    'c_office_pinyin' => $serviceResult['pinyin'],
                    'type_ids' => $serviceResult['type_ids'],
                ],
            ];
        }

        // delete
        return [
            'pk' => ['c_office_id' => $id],
            'status' => 'deleted',
            'operation_id' => $serviceResult['operation_id_office'],
            'rel_deleted' => $serviceResult['rel_deleted'],
        ];
    }
}
