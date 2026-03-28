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
        EventMutationHandler $eventHandler,
        AssociationMutationHandler $associationHandler,
        KinshipMutationHandler $kinshipHandler,
        PossessionMutationHandler $possessionHandler,
        TextMutationHandler $textHandler,
        PostingMutationHandler $postingHandler,
        SocialInstitutionMutationHandler $socialInstitutionHandler,
        SourceMutationHandler $sourceHandler,
        NianHaoMutationHandler $nianHaoHandler
    ) {
        $this->handlers = [
            $biogMainHandler,
            $altnameHandler,
            $addressHandler,
            $entryHandler,
            $statusHandler,
            $eventHandler,
            $associationHandler,
            $kinshipHandler,
            $possessionHandler,
            $textHandler,
            $postingHandler,
            $socialInstitutionHandler,
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
