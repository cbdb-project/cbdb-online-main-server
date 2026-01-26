<?php

namespace App\ViewTables;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ViewTableQueries {
    /**
     * Build query for the Altname Data view.
     */
    public static function altnameData(): Builder {
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
     * Build query for the Association Data view.
     */
    public static function associationData(): Builder {
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
    public static function biographicalAddressData(): Builder {
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
    public static function biographicalInstitutionAddressData(): Builder {
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
            ->orderBy('View_BiogInstData.c_inst_name_code')
            ->orderBy('View_BiogInstData.c_inst_code');
    }

    /**
     * Build query for the Biographical Institution Data view.
     */
    public static function biographicalInstitutionData(): Builder {
        return DB::table('View_BiogInstData')
            ->select([
                'c_personid',
                'c_name',
                'c_name_chn',
                'c_inst_name_code',
                'c_inst_code',
                'c_inst_name_hz',
                'c_inst_name_py',
                'c_bi_role_code',
                'c_bi_role_desc',
                'c_bi_role_chn',
                'c_bi_begin_year',
                'c_bi_by_nh_code',
                'c_bi_by_nh_chn',
                'c_bi_by_nh_py',
                'c_bi_by_nh_year',
                'c_bi_by_range',
                'c_bi_by_range_desc',
                'c_bi_by_range_chn',
                'c_bi_end_year',
                'c_bi_ey_nh_code',
                'c_bi_ey_nh_chn',
                'c_bi_ey_nh_py',
                'c_bi_ey_nh_year',
                'c_bi_ey_range',
                'c_bi_ey_range_desc',
                'c_bi_ey_range_chn',
                'c_source',
                'c_source_chn',
                'c_source_py',
                'c_pages',
                'c_notes',
                'c_inst_addr_id',
                'c_inst_addr_type_code',
                'inst_xcoord',
                'inst_ycoord',
            ])
            ->orderBy('c_personid')
            ->orderBy('c_inst_name_code')
            ->orderBy('c_inst_code');
    }

    /**
     * Build query for the Entry Data view.
     */
    public static function entryData(): Builder {
        return DB::table('ENTRY_DATA as entry')
            ->select([
                'entry.c_personid',
                'person.c_name',
                'person.c_name_chn',
                'person.c_index_year',
                'person.c_index_year_type_code',
                'indexyear_codes.c_index_year_type_desc',
                'indexyear_codes.c_index_year_type_hz',
                'person.c_dy',
                'dynasties.c_dynasty',
                'dynasties.c_dynasty_chn',
                'entry.c_entry_code',
                'entry_codes.c_entry_desc',
                'entry_codes.c_entry_desc_chn',
                'entry.c_year',
                'entry.c_sequence',
                'person.c_index_addr_id',
                'index_addr.c_name as c_addr_name',
                'index_addr.c_name_chn as c_addr_chn',
                'index_addr.x_coord',
                'index_addr.y_coord',
                'person.c_index_addr_type_code',
                'index_addr_type.c_addr_desc',
                'index_addr_type.c_addr_desc_chn',
                'entry.c_exam_rank',
                'entry.c_kin_code',
                'kin_codes.c_kinrel_chn',
                'kin_codes.c_kinrel',
                'entry.c_kin_id',
                'kin_person.c_name as c_kin_name',
                'kin_person.c_name_chn as c_kin_name_chn',
                'entry.c_assoc_code',
                'assoc_codes.c_assoc_desc',
                'assoc_codes.c_assoc_desc_chn',
                'entry.c_assoc_id',
                'assoc_person.c_name as c_assoc_name',
                'assoc_person.c_name_chn as c_assoc_name_chn',
                'entry.c_age',
                'entry.c_entry_nh_id',
                'nh.c_nianhao_chn',
                'nh.c_nianhao_pin',
                'entry.c_entry_nh_year',
                'entry.c_entry_range',
                'range_codes.c_range',
                'range_codes.c_range_chn',
                'entry.c_inst_code',
                'entry.c_inst_name_code',
                'inst_names.c_inst_name_hz',
                'inst_names.c_inst_name_py',
                'entry.c_exam_field',
                'entry.c_entry_addr_id',
                'entry_addr.c_name as c_entry_addr_name',
                'entry_addr.c_name_chn as c_entry_addr_chn',
                'entry_addr.x_coord as c_entry_xcoord',
                'entry_addr.y_coord as c_entry_ycoord',
                'entry.c_parental_status_code',
                'parental_codes.c_parental_status_desc',
                'parental_codes.c_parental_status_desc_chn',
                'entry.c_attempt_count',
                'entry.c_source',
                'text_codes.c_title',
                'text_codes.c_title_chn',
                'entry.c_pages',
                'entry.c_notes',
                'entry.c_posting_notes',
            ])
            ->join('ENTRY_CODES as entry_codes', 'entry_codes.c_entry_code', '=', 'entry.c_entry_code')
            ->join('BIOG_MAIN as person', 'person.c_personid', '=', 'entry.c_personid')
            ->leftJoin('BIOG_MAIN as kin_person', 'kin_person.c_personid', '=', 'entry.c_kin_id')
            ->leftJoin('BIOG_MAIN as assoc_person', 'assoc_person.c_personid', '=', 'entry.c_assoc_id')
            ->leftJoin('KINSHIP_CODES as kin_codes', 'kin_codes.c_kincode', '=', 'entry.c_kin_code')
            ->leftJoin('ASSOC_CODES as assoc_codes', 'assoc_codes.c_assoc_code', '=', 'entry.c_assoc_code')
            ->leftJoin('PARENTAL_STATUS_CODES as parental_codes', 'parental_codes.c_parental_status_code', '=', 'entry.c_parental_status_code')
            ->leftJoin('NIAN_HAO as nh', 'nh.c_nianhao_id', '=', 'entry.c_entry_nh_id')
            ->leftJoin('YEAR_RANGE_CODES as range_codes', 'range_codes.c_range_code', '=', 'entry.c_entry_range')
            ->leftJoin('ADDR_CODES as entry_addr', 'entry_addr.c_addr_id', '=', 'entry.c_entry_addr_id')
            ->leftJoin('TEXT_CODES as text_codes', 'text_codes.c_textid', '=', 'entry.c_source')
            ->leftJoin('SOCIAL_INSTITUTION_NAME_CODES as inst_names', 'inst_names.c_inst_name_code', '=', 'entry.c_inst_name_code')
            ->leftJoin('ADDR_CODES as index_addr', 'index_addr.c_addr_id', '=', 'person.c_index_addr_id')
            ->leftJoin('BIOG_ADDR_CODES as index_addr_type', 'index_addr_type.c_addr_type', '=', 'person.c_index_addr_type_code')
            ->leftJoin('INDEXYEAR_TYPE_CODES as indexyear_codes', 'indexyear_codes.c_index_year_type_code', '=', 'person.c_index_year_type_code')
            ->leftJoin('DYNASTIES as dynasties', 'dynasties.c_dy', '=', 'person.c_dy')
            ->orderBy('entry.c_personid')
            ->orderBy('entry.c_entry_code')
            ->orderBy('entry.c_sequence');
    }

    /**
     * Build query for the Events Data view.
     */
    public static function eventsData(): Builder {
        return DB::table('EVENTS_DATA as ed')
            ->select([
                'ed.c_personid',
                'person.c_name',
                'person.c_name_chn',
                'ed.c_sequence',
                'ed.c_event_code',
                'event_codes.c_event_name_chn',
                'event_codes.c_event_name',
                'ed.c_role',
                'ed.c_year',
                'ed.c_nh_code',
                'nh.c_nianhao_chn',
                'nh.c_nianhao_pin',
                'ed.c_nh_year',
                'ed.c_yr_range',
                'range_codes.c_range',
                'range_codes.c_range_chn',
                'ed.c_intercalary',
                'ed.c_month',
                'ed.c_day',
                'ed.c_day_ganzhi',
                'gz.c_ganzhi_chn as c_event_day_gz_chn',
                'gz.c_ganzhi_py as c_event_day_gz_py',
                'ed.c_source',
                'texts.c_title as c_source_title',
                'texts.c_title_chn as c_source_chn',
                'ed.c_pages',
                'ed.c_notes',
                DB::raw('NULL as c_person_text_title'),
                DB::raw('NULL as c_person_text_pages'),
                DB::raw('NULL as c_person_text_notes'),
            ])
            ->join('EVENT_CODES as event_codes', 'event_codes.c_event_code', '=', 'ed.c_event_code')
            ->join('BIOG_MAIN as person', 'person.c_personid', '=', 'ed.c_personid')
            ->leftJoin('NIAN_HAO as nh', 'nh.c_nianhao_id', '=', 'ed.c_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as range_codes', 'range_codes.c_range_code', '=', 'ed.c_yr_range')
            ->leftJoin('GANZHI_CODES as gz', 'gz.c_ganzhi_code', '=', 'ed.c_day_ganzhi')
            ->leftJoin('TEXT_CODES as texts', 'texts.c_textid', '=', 'ed.c_source')
            ->orderBy('ed.c_personid')
            ->orderBy('ed.c_sequence');
    }

    /**
     * Build query for the Kin Address Data view.
     */
    public static function kinAddressData(): Builder {
        return DB::table('KIN_DATA as kd')
            ->select([
                'kd.c_personid',
                'person.c_name',
                'person.c_name_chn',
                'kd.c_kin_id',
                'kin_person.c_name as c_kin_name',
                'kin_person.c_name_chn as c_kin_chn',
                'kd.c_kin_code',
                'kin_codes.c_kinrel',
                'kin_codes.c_kinrel_chn',
                DB::raw('NULL as c_addr_name'),
                DB::raw('NULL as c_addr_chn'),
                'kd.c_source',
                'texts.c_title',
                'texts.c_title_chn',
                'kd.c_pages',
                'kd.c_notes',
                DB::raw('NULL as c_sequence'),
            ])
            ->join('BIOG_MAIN as person', 'person.c_personid', '=', 'kd.c_personid')
            ->leftJoin('BIOG_MAIN as kin_person', 'kin_person.c_personid', '=', 'kd.c_kin_id')
            ->leftJoin('KINSHIP_CODES as kin_codes', 'kin_codes.c_kincode', '=', 'kd.c_kin_code')
            ->leftJoin('TEXT_CODES as texts', 'texts.c_textid', '=', 'kd.c_source')
            ->orderBy('kd.c_personid')
            ->orderBy('kd.c_kin_id');
    }

    /**
     * Build query for the People Data view.
     */
    public static function peopleData(): Builder {
        return DB::table('BIOG_MAIN as bm')
            ->select([
                'bm.c_personid',
                'bm.c_name',
                'bm.c_name_chn',
                'bm.c_index_year',
                'bm.c_index_year_type_code',
                'indexyear_codes.c_index_year_type_desc',
                'indexyear_codes.c_index_year_type_hz',
                'bm.c_index_year_source_id',
                'index_source.c_title as c_source_title',
                'index_source.c_title_chn as c_source_chn',
                'bm.c_female',
                'bm.c_index_addr_id',
                'index_addr.c_name as c_index_addr_name',
                'index_addr.c_name_chn as c_index_addr_chn',
                'index_addr.x_coord as c_index_addr_x_coord',
                'index_addr.y_coord as c_index_addr_y_coord',
                'bm.c_index_addr_type_code',
                'index_addr_type.c_addr_desc',
                'index_addr_type.c_addr_desc_chn',
                'bm.c_ethnicity_code',
                'ethnicity.c_name as c_ethnicity_desc',
                'ethnicity.c_name_chn as c_ethnicity_desc_chn',
                'bm.c_household_status_code',
                'household.c_household_status_desc',
                'household.c_household_status_desc_chn',
                'bm.c_tribe',
                'bm.c_birthyear',
                'bm.c_by_nh_code',
                'by_nh.c_nianhao_chn as c_by_nh_chn',
                'by_nh.c_nianhao_pin as c_by_nh_py',
                'bm.c_by_nh_year',
                'bm.c_by_range',
                'by_range.c_range as c_by_range_desc',
                'by_range.c_range_chn as c_by_range_chn',
                'bm.c_deathyear',
                'bm.c_dy_nh_code',
                'dy_nh.c_nianhao_chn as c_dy_nh_chn',
                'dy_nh.c_nianhao_pin as c_dy_nh_py',
                'bm.c_dy_nh_year',
                'bm.c_dy_range',
                'dy_range.c_range as c_dy_range_desc',
                'dy_range.c_range_chn as c_dy_range_chn',
                'bm.c_death_age',
                'bm.c_death_age_range',
                'death_age_range.c_range as c_death_age_range_desc',
                'death_age_range.c_range_chn as c_death_age_range_chn',
                'bm.c_fl_earliest_year',
                'bm.c_fl_ey_nh_code',
                'fl_ey_nh.c_nianhao_chn as c_fl_ey_nh_chn',
                'fl_ey_nh.c_nianhao_pin as c_fl_ey_nh_py',
                'bm.c_fl_ey_nh_year',
                DB::raw('NULL as c_fl_ey_range'),
                'bm.c_fl_latest_year',
                'bm.c_fl_ly_nh_code',
                'fl_ly_nh.c_nianhao_chn as c_fl_ly_nh_chn',
                'fl_ly_nh.c_nianhao_pin as c_fl_ly_nh_py',
                'bm.c_fl_ly_nh_year',
                DB::raw('NULL as c_fl_ly_range'),
                'bm.c_surname',
                'bm.c_surname_chn',
                'bm.c_mingzi',
                'bm.c_mingzi_chn',
                'bm.c_dy',
                'dynasties.c_dynasty',
                'dynasties.c_dynasty_chn',
                'bm.c_choronym_code',
                'choronym.c_choronym_desc',
                'choronym.c_choronym_chn as c_choronym_desc_chn',
                'bm.c_notes',
                'bm.c_by_intercalary',
                'bm.c_dy_intercalary',
                'bm.c_by_month',
                'bm.c_dy_month',
                'bm.c_by_day',
                'bm.c_dy_day',
                'bm.c_by_day_gz',
                'by_gz.c_ganzhi_chn as c_by_day_gz_chn',
                'by_gz.c_ganzhi_py as c_by_day_gz_py',
                'bm.c_dy_day_gz',
                'dy_gz.c_ganzhi_chn as c_dy_day_gz_chn',
                'dy_gz.c_ganzhi_py as c_dy_day_gz_py',
                'bm.c_surname_proper',
                'bm.c_mingzi_proper',
                'bm.c_name_proper',
                'bm.c_surname_rm',
                'bm.c_mingzi_rm',
                'bm.c_name_rm',
                'bm.c_created_by',
                'bm.c_created_date',
                'bm.c_modified_by',
                'bm.c_modified_date',
                'bm.c_self_bio',
            ])
            ->leftJoin('INDEXYEAR_TYPE_CODES as indexyear_codes', 'indexyear_codes.c_index_year_type_code', '=', 'bm.c_index_year_type_code')
            ->leftJoin('TEXT_CODES as index_source', 'index_source.c_textid', '=', 'bm.c_index_year_source_id')
            ->leftJoin('ADDR_CODES as index_addr', 'index_addr.c_addr_id', '=', 'bm.c_index_addr_id')
            ->leftJoin('BIOG_ADDR_CODES as index_addr_type', 'index_addr_type.c_addr_type', '=', 'bm.c_index_addr_type_code')
            ->leftJoin('ETHNICITY_TRIBE_CODES as ethnicity', 'ethnicity.c_ethnicity_code', '=', 'bm.c_ethnicity_code')
            ->leftJoin('HOUSEHOLD_STATUS_CODES as household', 'household.c_household_status_code', '=', 'bm.c_household_status_code')
            ->leftJoin('NIAN_HAO as by_nh', 'by_nh.c_nianhao_id', '=', 'bm.c_by_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as by_range', 'by_range.c_range_code', '=', 'bm.c_by_range')
            ->leftJoin('NIAN_HAO as dy_nh', 'dy_nh.c_nianhao_id', '=', 'bm.c_dy_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as dy_range', 'dy_range.c_range_code', '=', 'bm.c_dy_range')
            ->leftJoin('YEAR_RANGE_CODES as death_age_range', 'death_age_range.c_range_code', '=', 'bm.c_death_age_range')
            ->leftJoin('NIAN_HAO as fl_ey_nh', 'fl_ey_nh.c_nianhao_id', '=', 'bm.c_fl_ey_nh_code')
            ->leftJoin('NIAN_HAO as fl_ly_nh', 'fl_ly_nh.c_nianhao_id', '=', 'bm.c_fl_ly_nh_code')
            ->leftJoin('DYNASTIES as dynasties', 'dynasties.c_dy', '=', 'bm.c_dy')
            ->leftJoin('CHORONYM_CODES as choronym', 'choronym.c_choronym_code', '=', 'bm.c_choronym_code')
            ->leftJoin('GANZHI_CODES as by_gz', 'by_gz.c_ganzhi_code', '=', 'bm.c_by_day_gz')
            ->leftJoin('GANZHI_CODES as dy_gz', 'dy_gz.c_ganzhi_code', '=', 'bm.c_dy_day_gz')
            ->orderBy('bm.c_personid');
    }

    /**
     * Build query for the Possessions Data view.
     */
    public static function possessionsData(): Builder {
        return DB::table('View_PossessionsData')
            ->select([
                'View_PossessionsData.c_personid',
                'View_PossessionsData.c_possession_record_id',
                'View_PossessionsData.c_sequence',
                'View_PossessionsData.c_possession_act_code',
                'View_PossessionsData.c_possession_act_desc',
                'View_PossessionsData.c_possession_act_desc_chn',
                'View_PossessionsData.c_possession_desc',
                'View_PossessionsData.c_possession_desc_chn',
                'View_PossessionsData.c_quantity',
                'View_PossessionsData.c_measure_code',
                'View_PossessionsData.c_measure_desc',
                'View_PossessionsData.c_measure_desc_chn',
                'View_PossessionsData.c_possession_yr',
                'View_PossessionsData.c_possession_nh_code',
                'View_PossessionsData.c_nianhao_chn',
                'View_PossessionsData.c_nianhao_pin',
                'View_PossessionsData.c_possession_nh_yr',
                'View_PossessionsData.c_possession_yr_range',
                'View_PossessionsData.c_range',
                'View_PossessionsData.c_range_chn',
                'View_PossessionsData.c_source',
                'View_PossessionsData.c_title_chn',
                'View_PossessionsData.c_title',
                'View_PossessionsData.c_pages',
                'View_PossessionsData.c_notes',
                'View_PossessionsData.c_addr_id',
            ])
            ->orderBy('View_PossessionsData.c_personid')
            ->orderBy('View_PossessionsData.c_sequence');
    }

    /**
     * Build query for the Possessions Address Data view.
     */
    public static function possessionsAddressData(): Builder {
        return DB::table('View_PossessionsData as possessions')
            ->select([
                'possessions.c_personid',
                'possessions.c_possession_record_id',
                'possessions.c_sequence',
                'possessions.c_possession_act_code',
                'possessions.c_possession_act_desc',
                'possessions.c_possession_act_desc_chn',
                'possessions.c_possession_desc',
                'possessions.c_possession_desc_chn',
                'possessions.c_quantity',
                'possessions.c_measure_code',
                'possessions.c_measure_desc',
                'possessions.c_measure_desc_chn',
                'possessions.c_possession_yr',
                'possessions.c_possession_nh_code',
                'possessions.c_nianhao_chn',
                'possessions.c_nianhao_pin',
                'possessions.c_possession_nh_yr',
                'possessions.c_possession_yr_range',
                'possessions.c_range',
                'possessions.c_range_chn',
                'possessions.c_source',
                'possessions.c_title_chn',
                'possessions.c_title',
                'possessions.c_pages',
                'possessions.c_notes',
                'possessions.c_addr_id',
                DB::raw('addr.c_name as c_possession_addr_name'),
                DB::raw('addr.c_name_chn as c_possession_addr_chn'),
            ])
            ->leftJoin('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'possessions.c_addr_id')
            ->orderBy('possessions.c_personid')
            ->orderBy('possessions.c_sequence');
    }

    /**
     * Build query for the Event Address Data view.
     */
    public static function eventAddressData(): Builder {
        return DB::table('EVENTS_ADDR as ea')
            ->select([
                'ea.c_personid',
                DB::raw('person.c_name as c_person_name'),
                DB::raw('person.c_name_chn as c_person_chn'),
                'event_data.c_event_code',
                DB::raw('event_codes.c_event_name_chn as c_event_name_chn'),
                DB::raw('event_codes.c_event_name as c_event_name'),
                'event_data.c_sequence',
                'ea.c_addr_id',
                DB::raw('addr.c_name as c_possession_addr_name'),
                DB::raw('addr.c_name_chn as c_possession_addr_chn'),
                DB::raw('addr.x_coord as c_event_xcoord'),
                DB::raw('addr.y_coord as c_event_ycoord'),
                'ea.c_year',
                'ea.c_nh_code',
                DB::raw('nh.c_nianhao_chn as c_nianhao_chn'),
                DB::raw('nh.c_nianhao_pin as c_nianhao_pin'),
                'ea.c_nh_year',
                'ea.c_yr_range',
                DB::raw('range_codes.c_range as c_range'),
                DB::raw('range_codes.c_range_chn as c_range_chn'),
                'ea.c_intercalary',
                'ea.c_month',
                'ea.c_day',
                'ea.c_day_ganzhi',
                DB::raw('gz.c_ganzhi_chn as c_event_day_gz_chn'),
                DB::raw('gz.c_ganzhi_py as c_event_day_gz_py'),
            ])
            ->leftJoin('EVENTS_DATA as event_data', function ($join) {
                $join->on('event_data.c_personid', '=', 'ea.c_personid')
                    ->on('event_data.c_sequence', '=', 'ea.c_sequence')
                    ->on('event_data.c_event_code', '=', 'ea.c_event_code');
            })
            ->leftJoin('EVENT_CODES as event_codes', 'event_codes.c_event_code', '=', 'event_data.c_event_code')
            ->join('BIOG_MAIN as person', 'person.c_personid', '=', 'ea.c_personid')
            ->leftJoin('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'ea.c_addr_id')
            ->leftJoin('GANZHI_CODES as gz', 'gz.c_ganzhi_code', '=', 'ea.c_day_ganzhi')
            ->leftJoin('YEAR_RANGE_CODES as range_codes', 'range_codes.c_range_code', '=', 'ea.c_yr_range')
            ->leftJoin('NIAN_HAO as nh', 'nh.c_nianhao_id', '=', 'ea.c_nh_code')
            ->orderBy('ea.c_personid')
            ->orderBy('event_data.c_sequence');
    }

    /**
     * Build query for the People Address Data view.
     */
    public static function peopleAddressData(): Builder {
        return DB::table('BIOG_MAIN as bm')
            ->select([
                'bm.c_personid',
                'bm.c_name',
                'bm.c_name_chn',
                'bm.c_index_year',
                'bm.c_female',
                'bm.c_index_addr_id',
                'bm.c_index_addr_type_code',
                DB::raw('addr.c_name as c_index_addr_name'),
                DB::raw('addr.c_name_chn as c_index_addr_chn'),
                DB::raw('addr_codes.c_addr_desc as c_index_addr_type_desc'),
                DB::raw('addr_codes.c_addr_desc_chn as c_index_addr_type_chn'),
                DB::raw('addr.x_coord'),
                DB::raw('addr.y_coord'),
            ])
            ->leftJoin('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'bm.c_index_addr_id')
            ->leftJoin('BIOG_ADDR_CODES as addr_codes', 'addr_codes.c_addr_type', '=', 'bm.c_index_addr_type_code')
            ->orderBy('bm.c_personid');
    }

    /**
     * Build query for the Posting Address Data view.
     */
    public static function postingAddressData(): Builder {
        return DB::table('POSTED_TO_ADDR_DATA as posting')
            ->select([
                'posting.c_personid',
                'posting.c_posting_id',
                'posting.c_office_id',
                'posting.c_addr_id',
                DB::raw('addr.c_name as c_office_addr_name'),
                DB::raw('addr.c_name_chn as c_office_addr_chn'),
            ])
            ->join('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'posting.c_addr_id')
            ->orderBy('posting.c_personid')
            ->orderBy('posting.c_posting_id');
    }

    /**
     * Build query for the Posting Office Data view.
     */
    public static function postingOfficeData(): Builder {
        return DB::table('POSTED_TO_OFFICE_DATA as po')
            ->select([
                'po.c_personid',
                'po.c_office_id',
                DB::raw('office.c_office_pinyin as c_office_pinyin'),
                DB::raw('office.c_office_chn as c_office_chn'),
                DB::raw('office.c_office_trans as c_office_trans'),
                'po.c_posting_id',
                'po.c_sequence',
                'po.c_firstyear',
                'po.c_fy_nh_code',
                DB::raw('fy_nh.c_nianhao_chn as c_fy_nh_chn'),
                DB::raw('fy_nh.c_nianhao_pin as c_fy_nh_py'),
                'po.c_fy_nh_year',
                'po.c_fy_range',
                DB::raw('fy_range.c_range as c_fy_range_desc'),
                DB::raw('fy_range.c_range_chn as c_fy_range_chn'),
                'po.c_lastyear',
                'po.c_ly_nh_code',
                DB::raw('ly_nh.c_nianhao_chn as c_ly_nh_chn'),
                DB::raw('ly_nh.c_nianhao_pin as c_ly_nh_py'),
                'po.c_ly_nh_year',
                'po.c_ly_range',
                DB::raw('ly_range.c_range as c_ly_range_desc'),
                DB::raw('ly_range.c_range_chn as c_ly_range_chn'),
                'po.c_appt_code',
                DB::raw('appt_codes.c_appt_desc_chn as c_appt_desc_chn'),
                DB::raw('appt_codes.c_appt_desc as c_appt_desc'),
                'po.c_assume_office_code',
                DB::raw('assume_codes.c_assume_office_desc_chn as c_assume_office_desc_chn'),
                DB::raw('assume_codes.c_assume_office_desc as c_assume_office_desc'),
                'po.c_inst_code',
                'po.c_inst_name_code',
                DB::raw('inst_names.c_inst_name_hz as c_inst_name_hz'),
                DB::raw('inst_names.c_inst_name_py as c_inst_name_py'),
                'po.c_source',
                DB::raw('texts.c_title_chn as c_title_chn'),
                DB::raw('texts.c_title as c_title'),
                'po.c_pages',
                'po.c_notes',
                'po.c_office_category_id',
                DB::raw('categories.c_category_desc as c_category_desc'),
                DB::raw('categories.c_category_desc_chn as c_category_desc_chn'),
                'po.c_fy_intercalary',
                'po.c_fy_month',
                'po.c_ly_intercalary',
                'po.c_ly_month',
                'po.c_fy_day',
                'po.c_ly_day',
                'po.c_fy_day_gz',
                DB::raw('fy_gz.c_ganzhi_chn as c_fy_day_gz_chn'),
                DB::raw('fy_gz.c_ganzhi_py as c_fy_day_gz_py'),
                'po.c_ly_day_gz',
                DB::raw('ly_gz.c_ganzhi_chn as c_ly_day_gz_chn'),
                DB::raw('ly_gz.c_ganzhi_py as c_ly_day_gz_py'),
                'po.c_dy',
                DB::raw('dynasties.c_dynasty as c_dynasty'),
                DB::raw('dynasties.c_dynasty_chn as c_dynasty_chn'),
            ])
            ->join('OFFICE_CODES as office', 'office.c_office_id', '=', 'po.c_office_id')
            ->join('BIOG_MAIN as person', 'person.c_personid', '=', 'po.c_personid')
            ->leftJoin('SOCIAL_INSTITUTION_NAME_CODES as inst_names', 'inst_names.c_inst_name_code', '=', 'po.c_inst_name_code')
            ->leftJoin('APPOINTMENT_CODES as appt_codes', 'appt_codes.c_appt_code', '=', 'po.c_appt_code')
            ->leftJoin('ASSUME_OFFICE_CODES as assume_codes', 'assume_codes.c_assume_office_code', '=', 'po.c_assume_office_code')
            ->leftJoin('TEXT_CODES as texts', 'texts.c_textid', '=', 'po.c_source')
            ->leftJoin('GANZHI_CODES as fy_gz', 'fy_gz.c_ganzhi_code', '=', 'po.c_fy_day_gz')
            ->leftJoin('GANZHI_CODES as ly_gz', 'ly_gz.c_ganzhi_code', '=', 'po.c_ly_day_gz')
            ->leftJoin('YEAR_RANGE_CODES as fy_range', 'fy_range.c_range_code', '=', 'po.c_fy_range')
            ->leftJoin('YEAR_RANGE_CODES as ly_range', 'ly_range.c_range_code', '=', 'po.c_ly_range')
            ->leftJoin('NIAN_HAO as fy_nh', 'fy_nh.c_nianhao_id', '=', 'po.c_fy_nh_code')
            ->leftJoin('NIAN_HAO as ly_nh', 'ly_nh.c_nianhao_id', '=', 'po.c_ly_nh_code')
            ->leftJoin('OFFICE_CATEGORIES as categories', 'categories.c_office_category_id', '=', 'po.c_office_category_id')
            ->leftJoin('DYNASTIES as dynasties', 'dynasties.c_dy', '=', 'po.c_dy')
            ->orderBy('po.c_personid')
            ->orderBy('po.c_posting_id')
            ->orderBy('po.c_sequence');

    }

    /**
     * Build query for the Status Data view.
     */
    public static function statusData(): Builder {
        return DB::table('STATUS_DATA as sd')
            ->select([
                'sd.c_personid',
                'sd.c_sequence',
                'sd.c_status_code',
                'status_codes.c_status_desc',
                'status_codes.c_status_desc_chn',
                'sd.c_firstyear',
                'sd.c_fy_nh_code',
                DB::raw('fy_nh.c_nianhao_chn as c_fy_nh_chn'),
                DB::raw('fy_nh.c_nianhao_pin as c_fy_nh_py'),
                'sd.c_fy_nh_year',
                'sd.c_fy_range',
                DB::raw('fy_range.c_range as c_fy_range_desc'),
                DB::raw('fy_range.c_range_chn as c_fy_range_chn'),
                'sd.c_lastyear',
                'sd.c_ly_nh_code',
                DB::raw('ly_nh.c_nianhao_chn as c_ly_nh_chn'),
                DB::raw('ly_nh.c_nianhao_pin as c_ly_nh_py'),
                'sd.c_ly_nh_year',
                'sd.c_ly_range',
                DB::raw('ly_range.c_range as c_ly_range_desc'),
                DB::raw('ly_range.c_range_chn as c_ly_range_chn'),
                'sd.c_supplement',
                'sd.c_source',
                DB::raw('texts.c_title_chn as c_title_chn'),
                DB::raw('texts.c_title as c_title'),
                'sd.c_pages',
                'sd.c_notes',
            ])
            ->join('STATUS_CODES as status_codes', 'status_codes.c_status_code', '=', 'sd.c_status_code')
            ->leftJoin('NIAN_HAO as fy_nh', 'fy_nh.c_nianhao_id', '=', 'sd.c_fy_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as fy_range', 'fy_range.c_range_code', '=', 'sd.c_fy_range')
            ->leftJoin('NIAN_HAO as ly_nh', 'ly_nh.c_nianhao_id', '=', 'sd.c_ly_nh_code')
            ->leftJoin('YEAR_RANGE_CODES as ly_range', 'ly_range.c_range_code', '=', 'sd.c_ly_range')
            ->leftJoin('TEXT_CODES as texts', 'texts.c_textid', '=', 'sd.c_source')
            ->orderBy('sd.c_personid')
            ->orderBy('sd.c_sequence');
    }

    /**
     * Build query for the Biographical Source Data view.
     */
    public static function biographicalSourceData(): Builder {
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
    public static function biographicalTextData(): Builder {
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
