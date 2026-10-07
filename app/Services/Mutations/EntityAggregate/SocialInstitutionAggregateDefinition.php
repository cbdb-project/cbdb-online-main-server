<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Services\Import\EntityAggregateService;
use App\Services\Import\SocialInstituteImportService;
use App\Services\Mutations\Concerns\ResolvesSocialInstituteAggregateInput;
use App\Support\VariantLabelMap;

/**
 * 「社會機構實體」的聚合定義（resource=social-institution）：收斂原 SocialInstituteImportHandler／
 * UpdateHandler／DeleteHandler 的實體專屬部分。
 *
 * 校驗 create／update 刻意不同：create 沿用批量匯入語義（單一 addr_id、最小欄位集，供 admin 批量
 * 表單與 API 共用同一存儲過程）；update 為全欄位＋多地址列（ResolvesSocialInstituteAggregateInput）。
 * 護欄：delete 被人物資料引用回 409；update 在「改名且被引用」時回 409（人物表存
 * (inst_code, name_code) 對，改名會使既存引用失配）。回應與原 handler 逐位一致。
 */
class SocialInstitutionAggregateDefinition extends AbstractEntityAggregateDefinition {
    use ResolvesSocialInstituteAggregateInput;

    public function __construct(protected SocialInstituteImportService $instService) {
    }

    public function resources(): array {
        return ['social-institution', 'social-institutions', 'social-institution-load', 'socialinst-load'];
    }

    public function operations(): array {
        return ['create', 'update', 'delete'];
    }

    public function pkField(): string {
        return 'c_inst_code';
    }

    public function resourceName(): string {
        return 'social-institution';
    }

    public function notFoundMessage(): string {
        return '找不到社會機構';
    }

    public function service(): EntityAggregateService {
        return $this->instService;
    }

    public function validate(string $operation, array $changes): array {
        return $operation === 'create'
            ? $this->validateCreate($changes)
            : $this->validateSocialInstituteAggregate($changes, $this->instService);
    }

    /**
     * create 校驗（批量匯入語義：name／type／dynasty／addr_id／source_id）。與原
     * SocialInstituteImportHandler 逐行對應，含 type_label／dynasty_label 未解析時的即刻回錯。
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function validateCreate(array $changes): array {
        $name = trim((string) ($this->scalarOrNull($changes['name'] ?? $changes['c_inst_name_hz'] ?? null) ?? ''));
        $addrId = $this->scalarOrNull($changes['addr_id'] ?? $changes['c_inst_addr_id'] ?? null);
        $sourceId = $this->scalarOrNull($changes['source_id'] ?? $changes['c_source'] ?? null);

        $typeMap = $this->instService->typeMap();
        $typeCodes = $this->instService->typeCodes();
        $typeCode = $this->scalarOrNull($changes['type_code'] ?? $changes['c_inst_type_code'] ?? null);
        if (($typeCode === null || $typeCode === '') && isset($changes['type_label'])) {
            $label = trim((string) ($this->scalarOrNull($changes['type_label']) ?? ''));
            // map 的鍵已歸一，傳入標籤也要歸一（見 VariantLabelMap）。
            $typeCode = VariantLabelMap::lookup($typeMap, $label, 'SOCIAL_INSTITUTION_TYPES', 'c_inst_type_hz');
            if ($typeCode === null) {
                return [['type_label' => ['not_found']], []];
            }
        }

        $dynastyMap = $this->instService->dynastyMap();
        $dynastyCodes = $this->instService->dynastyCodes();
        $dynastyCode = $this->scalarOrNull($changes['dynasty_code'] ?? $changes['c_inst_begin_dy'] ?? null);
        if (($dynastyCode === null || $dynastyCode === '') && isset($changes['dynasty_label'])) {
            $label = trim((string) ($this->scalarOrNull($changes['dynasty_label']) ?? ''));
            $dynastyCode = VariantLabelMap::lookup($dynastyMap, $label, 'DYNASTIES', 'c_dynasty_chn');
            if ($dynastyCode === null) {
                return [['dynasty_label' => ['not_found']], []];
            }
        }

        $errors = [];
        if ($name === '') {
            $errors['name'] = ['required'];
        }
        if ($typeCode === null || $typeCode === '' || !in_array((int) $typeCode, $typeCodes, true)) {
            $errors['type'] = ['invalid'];
        }
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyCodes, true)) {
            $errors['dynasty'] = ['invalid'];
        }
        if ($addrId === null || $addrId === '' || !ctype_digit((string) $addrId)) {
            $errors['addr_id'] = ['required_integer'];
        } elseif ($this->instService->missingAddrIds([(int) $addrId]) !== []) {
            $errors['addr_id'] = ['not_found_in_addr_codes'];
        }
        if ($sourceId === null || $sourceId === '' || !ctype_digit((string) $sourceId)) {
            $errors['source_id'] = ['required_integer'];
        } elseif ($this->instService->missingSourceIds([(int) $sourceId]) !== []) {
            $errors['source_id'] = ['not_found_in_text_codes'];
        }
        // 選填的別名清單（與 update 同一套解析；鍵不存在＝不建任何別名）。
        $altNames = $this->parseSocialInstituteAltNames($changes, $this->instService, $errors);
        if ($errors !== []) {
            return [$errors, []];
        }

        return [[], [
            'name' => $name,
            'type_code' => (int) $typeCode,
            'dynasty_code' => (int) $dynastyCode,
            'addr_id' => (int) $addrId,
            'source_id' => (int) $sourceId,
            'alt_names' => $altNames,
        ]];
    }

    /**
     * 這次儲存會不會換掉 c_inst_name_code（唯讀探測，不配號也不建列）。
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $existing
     */
    protected function nameCodeWouldChange(array $input, array $existing): bool {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return false;
        }

        $resolved = $this->instService->findExistingNameCode($name);

        // 解析不到既有列 ⇒ 儲存時會新建一個 code ⇒ 就是改名。
        return $resolved === null || $resolved !== (int) ($existing['name_code'] ?? 0);
    }

    public function guardWrite(string $operation, ?int $id, array $input, ?array $existing): ?array {
        // 護欄要問的是「這次儲存會不會真的換掉 c_inst_name_code」，所以直接問 resolver，
        // 不做字串比對：
        //  - 純字串相等會把「只換字形」（resolveNameCode 兩形都探 ⇒ 同一個 code）誤報成
        //    rename_blocked_while_referenced（409），那其實是 no-op；
        //  - 反過來，只比「歸一後字串」又會漏掉反方向——輸入參考形而既有列是另一個變體形時，
        //    歸一後兩邊看起來相同，但 resolveNameCode() 會**新建**一個 code，那正是護欄
        //    要擋的「既存引用失配」。
        if ($operation === 'update' && $existing !== null
            && $this->nameCodeWouldChange($input, $existing)) {
            // 改名護欄：名稱改變且仍被人物資料引用時擋下（其餘欄位可正常修改）。
            $refCount = $this->instService->referenceCount((int) $id);
            if ($refCount > 0) {
                return [
                    "此機構仍被 {$refCount} 筆人物資料引用，暫不支援改名（會使既存引用的名稱碼失配）；其餘欄位可正常修改",
                    409,
                    ['name' => ['rename_blocked_while_referenced'], 'reference_count' => [$refCount]],
                ];
            }
        }

        if (in_array($operation, ['create', 'update'], true) && ($input['alt_names'] ?? null) !== null) {
            $altGuard = $this->guardAltNames($input['alt_names'], $operation === 'update' ? ($existing['alt_names'] ?? []) : []);
            if ($altGuard !== null) {
                return $altGuard;
            }
        }

        if ($operation === 'delete') {
            $refCount = $this->instService->referenceCount((int) $id);
            if ($refCount > 0) {
                return [
                    "此機構仍被 {$refCount} 筆人物資料引用，無法刪除",
                    409,
                    ['c_inst_code' => ['referenced_by_person_data'], 'reference_count' => [$refCount]],
                ];
            }
        }

        return null;
    }

    /**
     * 別名清單的兩道護欄（要看既有列才判得了，所以不在 validate()）：
     *
     * 1. 既有別名裡有**字面完全相同**的重複列（同類型、同原字）→ 409。這張表沒有主鍵，那種列
     *    在 where 上分不開，對賬無法只改或只刪其中一列；要先由管理者清理。
     * 2. 請求裡兩列「異體字歸一後相同、字面不同」→ 422 duplicate，**除非**兩個字面都是既有列
     *    （兩形本來就並存，原樣送回是合法的）。否則落庫後會是同一個別名鍵，或製造出新的兩形並存。
     *
     * @param array<int, array<string, mixed>> $rows     已驗證的別名列
     * @param array<int, array<string, mixed>> $existing load() 讀出的既有別名
     * @return array{0: string, 1: int, 2: array<string, array<int, string>>}|null
     */
    protected function guardAltNames(array $rows, array $existing): ?array {
        $existingLiterals = [];
        foreach ($existing as $row) {
            if ($row['name'] === null) {
                continue;
            }
            $literal = SocialInstituteImportService::altNameLiteralKey($row['type_code'], $row['name']);
            if (isset($existingLiterals[$literal])) {
                return [
                    '此機構的既有別名有完全相同的重複列，無法安全對賬；請先清理重複列',
                    409,
                    ['alt_names' => ['existing_duplicate_rows']],
                ];
            }
            $existingLiterals[$literal] = true;
        }

        $groups = [];
        foreach (array_values($rows) as $i => $row) {
            $groups[$this->instService->altNameKey($row['type_code'], (string) $row['name'])][] = [$i, $row];
        }
        $errors = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            $allExisting = true;
            foreach ($members as [, $row]) {
                if (!isset($existingLiterals[SocialInstituteImportService::altNameLiteralKey($row['type_code'], (string) $row['name'])])) {
                    $allExisting = false;
                }
            }
            if (!$allExisting) {
                foreach (array_slice($members, 1) as [$i]) {
                    $errors["alt_names.$i"] = ['duplicate'];
                }
            }
        }

        return $errors === [] ? null : ['參數校驗失敗', 422, $errors];
    }

    public function result(string $operation, ?int $id, array $input, array $serviceResult): array {
        if ($operation === 'create') {
            return [
                'pk' => ['c_inst_code' => $serviceResult['inst_code'], 'c_inst_name_code' => $serviceResult['name_code']],
                'status' => 'created',
                'operation_id' => $serviceResult['operation_id_code'],
                'name_created' => $serviceResult['name_created'],
                'alt_names_added' => $serviceResult['alt_names_added'] ?? 0,
                // 回應必須回**實際生效**的名稱：新建時是歸一後的參考形，複用既有碼時是既有
                // 列的原字面（刻意不歸一）。回 $input['name'] 會與資料庫不一致。
                '__variant_replaced' => $serviceResult['variant_replaced'] ?? [],
                // 「送來的字形併入既有別名的另一個字形」不是字元替換，走一般通知（見 envelope()）。
                '__notices' => $serviceResult['alt_name_notices'] ?? [],
                'row' => [
                    'c_inst_code' => $serviceResult['inst_code'],
                    'c_inst_name_code' => $serviceResult['name_code'],
                    'c_inst_name_hz' => $serviceResult['name'] ?? $input['name'],
                    'c_inst_name_py' => $serviceResult['name_pinyin'],
                    'c_inst_type_code' => (int) $input['type_code'],
                    'c_inst_addr_id' => (int) $input['addr_id'],
                    // 寫入後實際落庫的別名（這張表沒有 /api/v2/get，回應是呼叫端唯一的讀回）。
                    'alt_names' => $serviceResult['alt_names'] ?? [],
                ],
            ];
        }

        if ($operation === 'update') {
            return [
                'pk' => ['c_inst_code' => $id],
                'status' => 'updated',
                'operation_id' => $serviceResult['operation_id_code'],
                'name_changed' => $serviceResult['name_changed'],
                'addr_added' => $serviceResult['addr_added'],
                'addr_removed' => $serviceResult['addr_removed'],
                // 請求未帶 alt_names 時別名不動，三者皆 0。
                'alt_names_added' => $serviceResult['alt_names_added'] ?? 0,
                'alt_names_removed' => $serviceResult['alt_names_removed'] ?? 0,
                'alt_names_updated' => $serviceResult['alt_names_updated'] ?? 0,
                '__variant_replaced' => $serviceResult['variant_replaced'] ?? [],
                // 「送來的字形併入既有別名的另一個字形」不是字元替換，走一般通知（見 envelope()）。
                '__notices' => $serviceResult['alt_name_notices'] ?? [],
                'row' => [
                    'c_inst_code' => $id,
                    'c_inst_name_code' => $serviceResult['name_code'],
                    'c_inst_name_hz' => $serviceResult['name'] ?? $input['name'],
                    'c_inst_type_code' => $input['type_code'],
                    'alt_names' => $serviceResult['alt_names'] ?? [],
                ],
            ];
        }

        // delete
        return [
            'pk' => ['c_inst_code' => $id],
            'status' => 'deleted',
            'operation_id' => $serviceResult['operation_id_code'],
            'addr_deleted' => $serviceResult['addr_deleted'],
            'alt_names_deleted' => $serviceResult['alt_names_deleted'] ?? 0,
        ];
    }
}
