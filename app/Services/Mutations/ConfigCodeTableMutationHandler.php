<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\PinyinUmlaut;
use Illuminate\Http\JsonResponse;

/**
 * 由 config/code_table_mutations.php 驅動的 code 表更新 handler。
 *
 * 一個 handler 服務多張表：毋須為每張 code 表寫子類（避免 handler／registry 樣板膨脹）。
 * 交易・審計・複合主鍵驗證・變更偵測・direct/proposal 全由 {@see AbstractCodeTableMutationHandler} 提供；
 * 本類只負責「依當前請求的 resource 選出對應定義」，再委派基底。
 *
 * 需要客製驗證等特殊行為的表，仍可另寫 AbstractCodeTableMutationHandler 子類並註冊於 registry。
 */
class ConfigCodeTableMutationHandler extends AbstractCodeTableMutationHandler {
    /** @var array<int,array{resource:string,table:string,aliases:array<int,string>,display_name:string,key_columns:array<int,string>,allowed_fields:array<int,string>}> */
    protected array $definitions;

    /** 當前請求解析出的定義；handle() 進入時設定、供基底的 abstract getter 讀取。 */
    protected ?array $active = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
        $this->definitions = config('code_table_mutations.tables', []);
    }

    /**
     * 覆寫基底：supports() 於 registry 解析時呼叫，此時 $active 尚未設定，
     * 故直接以 resource 查定義（不可用讀 $active 的 abstract getter）。
     */
    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'update'
            && in_array($mode, ['direct', 'proposal'], true)
            && $this->findDefinition($resource) !== null;
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $definition = $this->findDefinition($resource);
        if ($definition === null) {
            return $this->errorResponse('目前尚未支援此 code 表', 501, ['resource' => [$resource]]);
        }

        $this->active = $definition;

        return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
    }

    protected function findDefinition(string $resource): ?array {
        foreach ($this->definitions as $definition) {
            if (in_array($resource, $definition['aliases'] ?? [], true)) {
                return $definition;
            }
        }

        return null;
    }

    protected function tableName(): string {
        return $this->active['table'];
    }

    protected function resourceName(): string {
        return $this->active['resource'];
    }

    protected function resourceAliases(): array {
        return $this->active['aliases'];
    }

    protected function displayName(): string {
        return $this->active['display_name'];
    }

    protected function keyColumns(): array {
        return $this->active['key_columns'];
    }

    protected function allowedFields(): array {
        return $this->active['allowed_fields'];
    }

    protected function integerFields(): array {
        return $this->active['integer_fields'] ?? [];
    }

    /**
     * §D-6 保存止血：對本表的 **Tier 1** 拼音欄靜默套用 v→ü 歸一化。
     * Tier 2（混西文）欄**不**在此轉——由前端 altname 式彈窗讓使用者決定（後端原樣寫入）。
     */
    protected function preprocessUpdateData(array $updateData): array {
        $tier1 = $this->active['tier1_fields'] ?? [];

        return PinyinUmlaut::normalizeFields($updateData, $tier1);
    }
}
