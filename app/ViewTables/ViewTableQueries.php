<?php

namespace App\ViewTables;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ViewTableQueries
{
    /**
     * Build query for the Altname Data view.
     */
    public static function altnameData(): Builder
    {
        return DB::table('ALTNAME_DATA as a')
            ->select([
                'a.c_personid',
                'a.c_alt_name',
                'a.c_alt_name_chn',
                'a.c_alt_name_type_code',
                'codes.c_name_type_desc',
                'codes.c_name_type_desc_chn',
                'a.c_sequence',
                'a.c_source',
                'texts.c_title',
                'texts.c_title_chn',
                'a.c_pages',
                'a.c_notes',
            ])
            ->join('ALTNAME_CODES as codes', 'codes.c_name_type_code', '=', 'a.c_alt_name_type_code')
            ->leftJoin('TEXT_CODES as texts', 'texts.c_textid', '=', 'a.c_source')
            ->orderBy('a.c_personid')
            ->orderBy('a.c_sequence');
    }

    /**
     * Build query for the address hierarchy view (View_Address).
     */
    public static function addressHierarchy(): Builder
    {
        return DB::table('View_Address')
            ->select([
                'c_addr_id',
                'c_name',
                'c_name_chn',
                'c_admin_type',
                'x_coord',
                'y_coord',
                'c_firstyear',
                'c_lastyear',
                'belongs1_ID',
                'belongs1_Name',
                'belongs1_Type',
                'belongs1_FirstYear',
                'belongs1_LastYear',
                'belongs2_ID',
                'belongs2_Name',
                'belongs2_Type',
                'belongs2_FirstYear',
                'belongs2_LastYear',
                'belongs3_ID',
                'belongs3_Name',
                'belongs3_Type',
                'belongs3_FirstYear',
                'belongs3_LastYear',
                'belongs4_ID',
                'belongs4_Name',
                'belongs4_Type',
                'belongs4_FirstYear',
                'belongs4_LastYear',
                'belongs5_ID',
                'belongs5_Name',
                'belongs5_Type',
                'belongs5_FirstYear',
                'belongs5_LastYear',
            ])
            ->orderBy('c_addr_id');
    }

    /**
     * Build query for the Association Data view.
     */
    public static function associationData(): Builder
    {
        return DB::table('ASSOC_DATA as a')
            ->select([
                'a.c_personid',
                'a.c_assoc_id as c_node_id',
                'assoc_person.c_name as c_node_name',
                'assoc_person.c_name_chn as c_node_chn',
                'a.c_assoc_code as c_link_code',
                'assoc_codes.c_assoc_desc as c_link_desc',
                'assoc_codes.c_assoc_desc_chn as c_link_chn',
                'a.c_kin_code',
                'kin_codes.c_kinrel_chn',
                'kin_codes.c_kinrel',
                'kin_person.c_name as c_kin_name',
                'kin_person.c_name_chn as c_kin_chn',
                'a.c_assoc_kin_id',
                'assoc_kin_codes.c_kinrel_chn as c_assoc_kinrel_chn',
                'assoc_kin_codes.c_kinrel as c_assoc_kinrel',
                'assoc_kin_person.c_name as c_assoc_kin_name',
                'assoc_kin_person.c_name_chn as c_assoc_kin_chn',
                'lit_codes.c_lit_genre_desc',
                'lit_codes.c_lit_genre_desc_chn',
                'occasion_codes.c_occasion_desc',
                'occasion_codes.c_occasion_desc_chn',
                'topic_codes.c_topic_desc',
                'topic_codes.c_topic_desc_chn',
                'inst_names.c_inst_name_py',
                'inst_names.c_inst_name_hz',
                'a.c_text_title',
                'a.c_assoc_claimer_id',
                'assoc_claimer.c_name as C_assoc_claimer_name',
                'assoc_claimer.c_name_chn as c_assoc_claimer_chn',
                'text_codes.c_title as c_source_title',
                'text_codes.c_title_chn as c_source_chn',
                'a.c_notes',
                'a.c_pages',
                'a.c_sequence',
                'a.c_assoc_count as c_link_count',
                'addr_codes.c_name as c_assoc_addr_name',
                'addr_codes.c_name_chn as c_assoc_addr_chn',
                'a.c_assoc_first_year',
                'range_codes.c_range',
                'range_codes.c_range_chn',
                'a.c_assoc_fy_intercalary',
                'a.c_assoc_fy_month',
                'a.c_assoc_fy_day',
                'nh.c_nianhao_chn as c_assoc_fy_nh_chn',
                'nh.c_nianhao_pin as c_assoc_fy_nh_py',
                'a.c_assoc_fy_nh_year',
                'gz.c_ganzhi_chn',
                'gz.c_ganzhi_py',
            ])
            ->join('ASSOC_CODES as assoc_codes', 'assoc_codes.c_assoc_code', '=', 'a.c_assoc_code')
            ->join('BIOG_MAIN as assoc_person', 'assoc_person.c_personid', '=', 'a.c_assoc_id')
            ->leftJoin('KINSHIP_CODES as kin_codes', 'kin_codes.c_kincode', '=', 'a.c_kin_code')
            ->leftJoin('BIOG_MAIN as kin_person', 'kin_person.c_personid', '=', 'a.c_kin_id')
            ->leftJoin('KINSHIP_CODES as assoc_kin_codes', 'assoc_kin_codes.c_kincode', '=', 'a.c_assoc_kin_code')
            ->leftJoin('BIOG_MAIN as assoc_kin_person', 'assoc_kin_person.c_personid', '=', 'a.c_assoc_kin_id')
            ->leftJoin('BIOG_MAIN as assoc_claimer', 'assoc_claimer.c_personid', '=', 'a.c_assoc_claimer_id')
            ->leftJoin('LITERARYGENRE_CODES as lit_codes', 'lit_codes.c_lit_genre_code', '=', 'a.c_litgenre_code')
            ->leftJoin('OCCASION_CODES as occasion_codes', 'occasion_codes.c_occasion_code', '=', 'a.c_occasion_code')
            ->leftJoin('SCHOLARLYTOPIC_CODES as topic_codes', 'topic_codes.c_topic_code', '=', 'a.c_topic_code')
            ->leftJoin('ADDR_CODES as addr_codes', 'addr_codes.c_addr_id', '=', 'a.c_addr_id')
            ->leftJoin('YEAR_RANGE_CODES as range_codes', 'range_codes.c_range_code', '=', 'a.c_assoc_fy_range')
            ->leftJoin('NIAN_HAO as nh', 'nh.c_nianhao_id', '=', 'a.c_assoc_fy_nh_code')
            ->leftJoin('GANZHI_CODES as gz', 'gz.c_ganzhi_code', '=', 'a.c_assoc_fy_day_gz')
            ->leftJoin('TEXT_CODES as text_codes', 'text_codes.c_textid', '=', 'a.c_source')
            ->leftJoin('SOCIAL_INSTITUTION_NAME_CODES as inst_names', 'inst_names.c_inst_name_code', '=', 'a.c_inst_name_code')
            ->orderBy('a.c_personid')
            ->orderBy('a.c_sequence');
    }

    /**
     * Build query for the Biographical Address Data view.
     */
    public static function biographicalAddressData(): Builder
    {
        return DB::table('BIOG_ADDR_DATA as b')
            ->select([
                'b.c_personid',
                'b.c_addr_id',
                'addr.c_name as c_addr_name',
                'addr.c_name_chn as c_addr_chn',
                'addr_codes.c_addr_desc',
                'addr_codes.c_addr_desc_chn',
                'b.c_firstyear',
                'b.c_lastyear',
                'b.c_source',
                'texts.c_title_chn as c_source_chn',
                'texts.c_title as c_source_title',
                'b.c_pages',
                'b.c_notes',
                'fy_nh.c_nianhao_chn as c_fy_nh_chn',
                'fy_nh.c_nianhao_pin as c_fy_nh_py',
                'b.c_fy_nh_year',
                'b.c_fy_month',
                'b.c_fy_day',
                'fy_gz.c_ganzhi_chn as c_fy_day_gz_chn',
                'fy_gz.c_ganzhi_py as c_fy_day_gz_py',
                'b.c_fy_intercalary',
                'fy_range.c_range as c_fy_range_desc',
                'fy_range.c_range_chn as c_fy_range_chn',
                'ly_nh.c_nianhao_chn as c_ly_nh_chn',
                'ly_nh.c_nianhao_pin as c_ly_nh_py',
                'b.c_ly_nh_year',
                'b.c_ly_intercalary',
                'b.c_ly_month',
                'b.c_ly_day',
                'ly_gz.c_ganzhi_chn as c_ly_day_gz_chn',
                'ly_gz.c_ganzhi_py as c_ly_day_gz_py',
                'ly_range.c_range as c_ly_range_desc',
                'ly_range.c_range_chn as c_ly_range_chn',
                'b.c_natal',
                'b.c_fy_nh_code',
                'b.c_ly_nh_code',
                'b.c_sequence',
            ])
            ->join('BIOG_ADDR_CODES as addr_codes', 'addr_codes.c_addr_type', '=', 'b.c_addr_type')
            ->join('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'b.c_addr_id')
            ->leftJoin('TEXT_CODES as texts', 'texts.c_textid', '=', 'b.c_source')
            ->leftJoin('NIAN_HAO as fy_nh', 'fy_nh.c_nianhao_id', '=', 'b.c_fy_nh_code')
            ->leftJoin('NIAN_HAO as ly_nh', 'ly_nh.c_nianhao_id', '=', 'b.c_ly_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as fy_range', 'fy_range.c_range_code', '=', 'b.c_fy_range')
            ->leftJoin('YEAR_RANGE_CODES as ly_range', 'ly_range.c_range_code', '=', 'b.c_ly_range')
            ->leftJoin('GANZHI_CODES as fy_gz', 'fy_gz.c_ganzhi_code', '=', 'b.c_fy_day_gz')
            ->leftJoin('GANZHI_CODES as ly_gz', 'ly_gz.c_ganzhi_code', '=', 'b.c_ly_day_gz')
            ->orderBy('b.c_personid')
            ->orderBy('b.c_sequence');
    }

    /**
     * Build query for the Biographical Institution Address Data view.
     */
    public static function biographicalInstitutionAddressData(): Builder
    {
        return DB::table('View_BiogInstData')
            ->select([
                'View_BiogInstData.*',
                'SOCIAL_INSTITUTION_ADDR_TYPES.c_inst_addr_type_desc',
                'SOCIAL_INSTITUTION_ADDR_TYPES.c_inst_addr_type_chn',
                'ADDR_CODES.c_name as c_inst_addr_pinyin',
                'ADDR_CODES.c_name_chn as c_inst_addr_chn',
            ])
            ->leftJoin('SOCIAL_INSTITUTION_ADDR_TYPES', 'View_BiogInstData.c_inst_addr_type_code', '=', 'SOCIAL_INSTITUTION_ADDR_TYPES.c_inst_addr_type_code')
            ->leftJoin('ADDR_CODES', 'View_BiogInstData.c_inst_addr_id', '=', 'ADDR_CODES.c_addr_id')
            ->orderBy('View_BiogInstData.c_personid')
            ->orderBy('View_BiogInstData.c_sequence');
    }

    /**
     * Build query for the Biographical Institution Data view.
     */
    public static function biographicalInstitutionData(): Builder
    {
        return DB::table('BIOG_INST_DATA as bi')
            ->select([
                'bi.c_personid',
                'person.c_name',
                'person.c_name_chn',
                'bi.c_inst_name_code',
                'bi.c_inst_code',
                'inst_names.c_inst_name_hz',
                'inst_names.c_inst_name_py',
                'bi.c_bi_role_code',
                'inst_codes.c_bi_role_desc',
                'inst_codes.c_bi_role_chn',
                'bi.c_bi_begin_year',
                'bi.c_bi_by_nh_code',
                'by_nh.c_nianhao_chn as c_bi_by_nh_chn',
                'by_nh.c_nianhao_pin as c_bi_by_nh_py',
                'bi.c_bi_by_nh_year',
                'bi.c_bi_by_range',
                'by_range.c_range as c_bi_by_range_desc',
                'by_range.c_range_chn as c_bi_by_range_chn',
                'bi.c_bi_end_year',
                'bi.c_bi_ey_nh_code',
                'ey_nh.c_nianhao_chn as c_bi_ey_nh_chn',
                'ey_nh.c_nianhao_pin as c_bi_ey_nh_py',
                'bi.c_bi_ey_nh_year',
                'bi.c_bi_ey_range',
                'ey_range.c_range as c_bi_ey_range_desc',
                'ey_range.c_range_chn as c_bi_ey_range_chn',
                'bi.c_source',
                'text_codes.c_title_chn as c_source_chn',
                'text_codes.c_title as c_source_py',
                'bi.c_pages',
                'bi.c_notes',
                'inst_addr.c_inst_addr_id',
                'inst_addr.c_inst_addr_type_code',
                'inst_addr.inst_xcoord',
                'inst_addr.inst_ycoord',
            ])
            ->join('BIOG_MAIN as person', 'person.c_personid', '=', 'bi.c_personid')
            ->join('SOCIAL_INSTITUTION_NAME_CODES as inst_names', 'inst_names.c_inst_name_code', '=', 'bi.c_inst_name_code')
            ->join('BIOG_INST_CODES as inst_codes', 'inst_codes.c_bi_role_code', '=', 'bi.c_bi_role_code')
            ->leftJoin('NIAN_HAO as by_nh', 'by_nh.c_nianhao_id', '=', 'bi.c_bi_by_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as by_range', 'by_range.c_range_code', '=', 'bi.c_bi_by_range')
            ->leftJoin('NIAN_HAO as ey_nh', 'ey_nh.c_nianhao_id', '=', 'bi.c_bi_ey_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as ey_range', 'ey_range.c_range_code', '=', 'bi.c_bi_ey_range')
            ->leftJoin('TEXT_CODES as text_codes', 'text_codes.c_textid', '=', 'bi.c_source')
            ->leftJoin('SOCIAL_INSTITUTION_ADDR as inst_addr', function ($join) {
                $join->on('inst_addr.c_inst_name_code', '=', 'bi.c_inst_name_code')
                    ->on('inst_addr.c_inst_code', '=', 'bi.c_inst_code');
            })
            ->orderBy('bi.c_personid')
            ->orderBy('bi.c_inst_name_code')
            ->orderBy('bi.c_inst_code');
    }

    /**
     * Build query for the Biographical Source Data view.
     */
    public static function biographicalSourceData(): Builder
    {
        return DB::table('TEXT_CODES')
            ->select([
                'BIOG_SOURCE_DATA.c_personid as c_personid',
                'BIOG_MAIN.c_name',
                'BIOG_MAIN.c_name_chn',
                'BIOG_SOURCE_DATA.c_textid',
                'TEXT_CODES.c_title_chn',
                'TEXT_CODES.c_title',
                'BIOG_SOURCE_DATA.c_pages',
                'TEXT_CODES.c_url_api',
                'TEXT_CODES.c_url_api_coda',
                'TEXT_CODES.c_url_homepage',
                'BIOG_SOURCE_DATA.c_notes as c_notes',
                'BIOG_SOURCE_DATA.c_main_source',
                'BIOG_SOURCE_DATA.c_self_bio as c_self_bio',
                DB::raw("COALESCE(TEXT_CODES.c_url_api, '') || COALESCE(BIOG_SOURCE_DATA.c_pages, '') || COALESCE(TEXT_CODES.c_url_api_coda, '') AS c_hyperlink"),
            ])
            ->join('BIOG_SOURCE_DATA', 'TEXT_CODES.c_textid', '=', 'BIOG_SOURCE_DATA.c_textid')
            ->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'BIOG_SOURCE_DATA.c_personid')
            ->orderBy('BIOG_SOURCE_DATA.c_personid')
            ->orderBy('BIOG_SOURCE_DATA.c_textid');
    }

    /**
     * Build query for the Biographical Text Data view.
     */
    public static function biographicalTextData(): Builder
    {
        return DB::table('BIOG_TEXT_DATA as bi')
            ->select([
                'bi.c_personid',
                'bi.c_textid as c_textid',
                'text.c_title',
                'text.c_title_chn',
                'bi.c_role_id',
                'role.c_role_desc',
                'role.c_role_desc_chn',
                'bi.c_year',
                'bi.c_source as c_source',
                DB::raw('source.c_title AS c_source_title'),
                DB::raw('source.c_title_chn AS c_source_chn'),
                DB::raw('bi.c_pages AS c_pages'),
                DB::raw('bi.c_notes AS c_notes'),
            ])
            ->join('TEXT_ROLE_CODES as role', 'role.c_role_id', '=', 'bi.c_role_id')
            ->join('TEXT_CODES as text', 'text.c_textid', '=', 'bi.c_textid')
            ->leftJoin('TEXT_CODES as source', 'bi.c_source', '=', 'source.c_textid')
            ->orderBy('bi.c_personid')
            ->orderBy('bi.c_textid');
    }
}
