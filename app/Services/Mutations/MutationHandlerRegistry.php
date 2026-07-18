<?php

namespace App\Services\Mutations;

class MutationHandlerRegistry {
    /**
     * @var array<int,MutationHandlerInterface>
     */
    protected array $handlers;

    public function __construct(
        BiogMainMutationHandler $biogMainHandler,
        BiogMainCreateHandler $biogMainCreateHandler,
        BiogMainDeleteHandler $biogMainDeleteHandler,
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
        ConfigCodeTableMutationHandler $codeTableHandler,
        AltnameCreateHandler $altnameCreateHandler,
        AddressCreateHandler $addressCreateHandler,
        EntryCreateHandler $entryCreateHandler,
        StatusCreateHandler $statusCreateHandler,
        TextCreateHandler $textCreateHandler,
        AssociationCreateHandler $associationCreateHandler,
        KinshipCreateHandler $kinshipCreateHandler,
        EventCreateHandler $eventCreateHandler,
        SocialInstitutionCreateHandler $socialInstitutionCreateHandler,
        PossessionCreateHandler $possessionCreateHandler,
        PostingCreateHandler $postingCreateHandler,
        AltnameDeleteHandler $altnameDeleteHandler,
        AddressDeleteHandler $addressDeleteHandler,
        EntryDeleteHandler $entryDeleteHandler,
        StatusDeleteHandler $statusDeleteHandler,
        TextDeleteHandler $textDeleteHandler,
        AssociationDeleteHandler $associationDeleteHandler,
        KinshipDeleteHandler $kinshipDeleteHandler,
        EventDeleteHandler $eventDeleteHandler,
        SocialInstitutionDeleteHandler $socialInstitutionDeleteHandler,
        SourceDeleteHandler $sourceDeleteHandler,
        PossessionDeleteHandler $possessionDeleteHandler,
        PostingDeleteHandler $postingDeleteHandler,
        EntityAggregateCreateHandler $entityAggregateCreateHandler,
        EntityAggregateUpdateHandler $entityAggregateUpdateHandler,
        EntityAggregateDeleteHandler $entityAggregateDeleteHandler,
        MergedPersonCreateHandler $mergedPersonCreateHandler,
        MergedPersonDeleteHandler $mergedPersonDeleteHandler,
        CodeTableCreateHandler $codeTableCreateHandler,
        CodeTableDeleteHandler $codeTableDeleteHandler
    ) {
        $this->handlers = [
            $biogMainHandler,
            $biogMainCreateHandler,
            $biogMainDeleteHandler,
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
            $codeTableHandler,
            $altnameCreateHandler,
            $addressCreateHandler,
            $entryCreateHandler,
            $statusCreateHandler,
            $textCreateHandler,
            $associationCreateHandler,
            $kinshipCreateHandler,
            $eventCreateHandler,
            $socialInstitutionCreateHandler,
            $possessionCreateHandler,
            $postingCreateHandler,
            $altnameDeleteHandler,
            $addressDeleteHandler,
            $entryDeleteHandler,
            $statusDeleteHandler,
            $textDeleteHandler,
            $associationDeleteHandler,
            $kinshipDeleteHandler,
            $eventDeleteHandler,
            $socialInstitutionDeleteHandler,
            $sourceDeleteHandler,
            $possessionDeleteHandler,
            $postingDeleteHandler,
            // 通用實體聚合 handler（office／social-institution／後續實體皆由此分派，
            // 依 config/entity_aggregates.php 的 definition；§6.5）。
            $entityAggregateCreateHandler,
            $entityAggregateUpdateHandler,
            $entityAggregateDeleteHandler,
            $mergedPersonCreateHandler,
            $mergedPersonDeleteHandler,
            $codeTableCreateHandler,
            $codeTableDeleteHandler,
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
