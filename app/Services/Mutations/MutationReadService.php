<?php

namespace App\Services\Mutations;

use App\Support\CompositePrimaryKey;

class MutationReadService {
    /**
     * @var array<int,array{resource:string,table:string,key_columns:array<int,string>,person_id_column:?string,aliases:array<int,string>}>
     */
    private array $definitions = [
        [
            'resource' => 'basicinformation',
            'table' => 'BIOG_MAIN',
            'key_columns' => ['c_personid'],
            'person_id_column' => 'c_personid',
            'aliases' => ['basicinformation', 'biogmain', 'biog_main'],
        ],
        [
            'resource' => 'altnames',
            'table' => 'ALTNAME_DATA',
            'key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            'person_id_column' => 'c_personid',
            'aliases' => ['altnames', 'altname', 'altname_data'],
        ],
        [
            'resource' => 'addresses',
            'table' => 'BIOG_ADDR_DATA',
            'key_columns' => ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'],
            'person_id_column' => 'c_personid',
            'aliases' => ['addresses', 'address', 'biog_addr_data'],
        ],
        [
            'resource' => 'entries',
            'table' => 'ENTRY_DATA',
            'key_columns' => ['c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'],
            'person_id_column' => 'c_personid',
            'aliases' => ['entries', 'entry', 'entry_data'],
        ],
        [
            'resource' => 'statuses',
            'table' => 'STATUS_DATA',
            'key_columns' => ['c_personid', 'c_sequence', 'c_status_code'],
            'person_id_column' => 'c_personid',
            'aliases' => ['statuses', 'status', 'status_data'],
        ],
        [
            'resource' => 'events',
            'table' => 'EVENTS_DATA',
            'key_columns' => ['c_personid', 'c_sequence', 'c_event_code'],
            'person_id_column' => 'c_personid',
            'aliases' => ['events', 'event', 'events_data'],
        ],
        [
            'resource' => 'associations',
            'table' => 'ASSOC_DATA',
            'key_columns' => ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'],
            'person_id_column' => 'c_personid',
            'aliases' => ['associations', 'association', 'assoc_data'],
        ],
        [
            'resource' => 'kinship',
            'table' => 'KIN_DATA',
            'key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            'person_id_column' => 'c_personid',
            'aliases' => ['kinship', 'kin', 'kin_data'],
        ],
        [
            'resource' => 'possessions',
            'table' => 'POSSESSION_DATA',
            'key_columns' => ['c_possession_record_id'],
            'person_id_column' => 'c_personid',
            'aliases' => ['possessions', 'possession', 'possession_data'],
        ],
        [
            'resource' => 'texts',
            'table' => 'BIOG_TEXT_DATA',
            'key_columns' => ['c_personid', 'c_textid', 'c_role_id'],
            'person_id_column' => 'c_personid',
            'aliases' => ['texts', 'text', 'text_data', 'biog_text_data'],
        ],
        [
            'resource' => 'postings',
            'table' => 'POSTED_TO_OFFICE_DATA',
            'key_columns' => ['c_office_id', 'c_posting_id'],
            'person_id_column' => 'c_personid',
            'aliases' => ['postings', 'posting', 'offices', 'posted_to_office_data'],
        ],
        [
            'resource' => 'social_institutions',
            'table' => 'BIOG_INST_DATA',
            'key_columns' => ['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code'],
            'person_id_column' => 'c_personid',
            'aliases' => ['social_institutions', 'socialinstitution', 'social_institution', 'biog_inst_data'],
        ],
        [
            'resource' => 'sources',
            'table' => 'BIOG_SOURCE_DATA',
            'key_columns' => ['c_personid', 'c_textid', 'c_pages'],
            'person_id_column' => 'c_personid',
            'aliases' => ['sources', 'source', 'biog_source_data'],
        ],
        [
            'resource' => 'nianhao',
            'table' => 'NIAN_HAO',
            'key_columns' => ['c_nianhao_id'],
            'person_id_column' => null,
            'aliases' => ['nianhao', 'nian_hao'],
        ],
    ];

    /**
     * @return array{resource:string,table:string,key_columns:array<int,string>,person_id_column:?string,aliases:array<int,string>}|null
     */
    public function resolve(string $resource): ?array {
        foreach ($this->definitions as $definition) {
            if (in_array($resource, $definition['aliases'], true)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function validatePk(array $pk, string $table): void {
        CompositePrimaryKey::validateOrFail($pk, $table);
    }
}
