<?php

namespace App\Services\Mutations;

class MutationHandlerRegistry {
    /**
     * @var array<int,MutationHandlerInterface>
     */
    protected array $handlers;

    public function __construct(
        BiogMainMutationHandler $biogMainHandler,
        AltnameMutationHandler $altnameHandler,
        AddressMutationHandler $addressHandler,
        EntryMutationHandler $entryHandler,
        StatusMutationHandler $statusHandler,
        PossessionMutationHandler $possessionHandler,
        SourceMutationHandler $sourceHandler,
        NianHaoMutationHandler $nianHaoHandler
    ) {
        $this->handlers = [
            $biogMainHandler,
            $altnameHandler,
            $addressHandler,
            $entryHandler,
            $statusHandler,
            $possessionHandler,
            $sourceHandler,
            $nianHaoHandler,
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
