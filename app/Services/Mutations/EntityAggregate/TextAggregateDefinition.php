<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Services\Import\EntityAggregateService;
use App\Services\Import\TextImportService;
use App\Services\Mutations\Concerns\ResolvesTextAggregateInput;

/**
 * 「文獻實體」的聚合定義（resource=text-entity）：TEXT_CODES ＋ TEXT_INSTANCE_DATA 版本列。
 *
 * resource 刻意不叫 text／texts——那兩個是人物子資源 BIOG_TEXT_DATA（著述）的既有別名
 * （TextMutationHandler），不可重載。create／update 共用 ResolvesTextAggregateInput 校驗
 * （必填僅 title，create／update 一致）。
 *
 * 護欄（文獻的 c_source 自引用層級，見 TextImportService 類註）：
 *  - update：改 c_source 為自己或自己的後代（成環）回 422；
 *  - delete：被人物出處／著述、其他表 c_source、子文獻或其他文獻版本引用時回 409。
 */
class TextAggregateDefinition extends AbstractEntityAggregateDefinition {
    use ResolvesTextAggregateInput;

    public function __construct(protected TextImportService $textService) {
    }

    public function resources(): array {
        return ['text-entity', 'text-entities', 'book', 'books'];
    }

    public function operations(): array {
        return ['create', 'update', 'delete'];
    }

    public function pkField(): string {
        return 'c_textid';
    }

    public function resourceName(): string {
        return 'text-entity';
    }

    public function notFoundMessage(): string {
        return '找不到文獻';
    }

    public function service(): EntityAggregateService {
        return $this->textService;
    }

    public function validate(string $operation, array $changes): array {
        return $this->validateTextAggregate($changes, $this->textService, $operation);
    }

    public function guardWrite(string $operation, ?int $id, array $input, ?array $existing): ?array {
        if ($operation === 'update') {
            // 0 值版本鍵：語義上版本鍵是正整數，但生產庫有一列歷史資料
            // (c_textid=40354, edition=0, instance=0)。無條件拒絕會讓那筆文獻無法編輯
            // （編輯頁把既有版本列原樣回送），所以只擋「新出現的」0 值鍵、放行既有列。
            $existingKeys = [];
            foreach (($existing['instances'] ?? []) as $row) {
                $existingKeys[((int) $row['edition_id']).'|'.((int) $row['instance_id'])] = true;
            }
            foreach (array_values($input['instances'] ?? []) as $n => $row) {
                if ((int) $row['edition_id'] !== 0 && (int) $row['instance_id'] !== 0) {
                    continue;
                }
                $key = ((int) $row['edition_id']).'|'.((int) $row['instance_id']);
                if (!isset($existingKeys[$key])) {
                    return [
                        '版本組號與版本序號必須是正整數（0 僅保留給資料庫中既有的歷史列）',
                        422,
                        ["instances.{$n}.key" => ['positive_integer_required']],
                    ];
                }
            }
        }

        if ($operation === 'update' && ($input['source_id'] ?? null) !== null) {
            // 成環護欄：c_source 是 TEXT_CODES 自引用（著錄來源樹），指向自己或自己的
            // 後代會讓樹成環（上溯查詢死循環、層級語義損毀）。
            if ($this->textService->sourceCreatesCycle((int) $id, (int) $input['source_id'])) {
                return [
                    '來源文獻不可為此文獻自身或其後代（會使著錄來源樹成環）',
                    422,
                    ['source_id' => ['source_cycle']],
                ];
            }
        }

        if ($operation === 'delete') {
            $refCount = $this->textService->referenceCount((int) $id);
            if ($refCount > 0) {
                return [
                    "此文獻仍被 {$refCount} 筆資料引用（人物出處／著述、其他記錄的來源、或子文獻），無法刪除",
                    409,
                    ['c_textid' => ['referenced_by_other_records'], 'reference_count' => [$refCount]],
                ];
            }
        }

        return null;
    }

    public function result(string $operation, ?int $id, array $input, array $serviceResult): array {
        if ($operation === 'create') {
            return [
                'pk' => ['c_textid' => $serviceResult['textid']],
                'status' => 'created',
                'operation_id' => $serviceResult['operation_id_text'],
                'instances_added' => $serviceResult['instances_added'],
                'variant_replacements' => $serviceResult['variant_replacements'],
                // 通用 handler 會剝掉這個內部鍵並掛成回應層的 notices（AGENTS.md §1.3：
                // 替換發生了就要讓使用者知道）。
                '__variant_replaced' => $serviceResult['variant_replaced'] ?? [],
                'row' => [
                    'c_textid' => $serviceResult['textid'],
                    'c_title_chn' => $serviceResult['title'],
                    'c_title' => $serviceResult['title_pinyin'],
                ],
            ];
        }

        if ($operation === 'update') {
            return [
                'pk' => ['c_textid' => $id],
                'status' => 'updated',
                'operation_id' => $serviceResult['operation_id_text'],
                'instances_added' => $serviceResult['instances_added'],
                'instances_removed' => $serviceResult['instances_removed'],
                'instances_updated' => $serviceResult['instances_updated'],
                '__variant_replaced' => $serviceResult['variant_replaced'] ?? [],
                'row' => [
                    'c_textid' => $id,
                    'c_title_chn' => $serviceResult['title'],
                    'c_title' => $serviceResult['title_pinyin'],
                ],
            ];
        }

        // delete
        return [
            'pk' => ['c_textid' => $id],
            'status' => 'deleted',
            'operation_id' => $serviceResult['operation_id_text'],
            'instances_deleted' => $serviceResult['instances_deleted'],
        ];
    }
}
