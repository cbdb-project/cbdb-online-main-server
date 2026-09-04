<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Support\EntityAggregateRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * resource → EntityAggregateDefinition 分派表（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 由 config/entity_aggregates.php 的 entities[].definition 建立：每個實體聲明其 definition 類，
 * 本 registry 從容器解析並建 resource（含別名）索引。通用 handler（EntityAggregate*Handler）
 * 以此把請求分派到正確的 definition。新實體上線＝config 加一項，不必動 handler。
 */
class EntityAggregateDefinitionRegistry {
    /** @var array<string, EntityAggregateDefinition> resource(lower) => definition */
    protected array $byResource = [];

    protected bool $booted = false;

    public function __construct(protected Container $container) {
    }

    protected function boot(): void {
        if ($this->booted) {
            return;
        }

        // 註冊表讀取一律經 EntityAggregateRegistry::entities()（防 config 被設成非陣列）。
        foreach (EntityAggregateRegistry::entities() as $entity) {
            $class = $entity['definition'] ?? null;
            if (!$class) {
                continue;
            }
            /** @var EntityAggregateDefinition $definition */
            $definition = $this->container->make($class);
            foreach ($definition->resources() as $resource) {
                $this->byResource[strtolower($resource)] = $definition;
            }
        }

        $this->booted = true;
    }

    /** 依 resource 取得 definition；無對應者回 null（由呼叫端回退其他 handler）。 */
    public function forResource(string $resource): ?EntityAggregateDefinition {
        $this->boot();

        return $this->byResource[strtolower($resource)] ?? null;
    }
}
