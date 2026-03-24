<?php

namespace App\Services\Mutations;

class MutationHandlerRegistry {
    /**
     * @var array<int,MutationHandlerInterface>
     */
    protected array $handlers;

    public function __construct(
        AltnameMutationHandler $altnameHandler,
        PossessionMutationHandler $possessionHandler,
        SourceMutationHandler $sourceHandler
    ) {
        $this->handlers = [
            $altnameHandler,
            $possessionHandler,
            $sourceHandler,
        ];
    }

    public function resolve(string $resource, string $mode, string $operation): ?MutationHandlerInterface {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($resource, $mode, $operation)) {
                return $handler;
            }
        }

        return null;
    }
}
