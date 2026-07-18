<?php

namespace App\Services\Mutations\EntityAggregate;

/**
 * EntityAggregateDefinition 的共用基底：提供 scalarOrNull()（供 Resolves*AggregateInput
 * concern 使用）與預設放行的 guardWrite()。具體實體 definition 覆寫需要的部分。
 */
abstract class AbstractEntityAggregateDefinition implements EntityAggregateDefinition {
    /**
     * 非純量輸入折成 null，讓後續 required/invalid 校驗以 422 擋下（對齊
     * AbstractMutationHandler::scalarOrNull 語意）。
     */
    protected function scalarOrNull(mixed $value): mixed {
        return is_scalar($value) ? $value : null;
    }

    /** 預設無護欄；有引用限制的實體（如刪除／改名）覆寫之。 */
    public function guardWrite(string $operation, ?int $id, array $input, ?array $existing): ?array {
        return null;
    }
}
