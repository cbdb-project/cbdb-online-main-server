<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BiogMain extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'BIOG_MAIN';
    protected $primaryKey = 'c_personid';
    /**
     * 该模型是否被自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 不可被批量赋值的属性。
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * 获取该人物所属的朝代
     */
    public function dynasty()
    {
        return $this->belongsTo('App\Dynasty', 'c_dy', 'c_dy');
    }

    public function simpleDynasty()
    {
        return $this->belongsTo('App\Dynasty', 'c_dy','c_dy')->select(['c_dy','c_dynasty','c_dynasty_chn']);
    }

    public function birthYearNH()
    {
        return $this->belongsTo('App\NianHao', 'c_by_nh_code', 'c_nianhao_id');
    }

    public function simpleBirthYearNH()
    {
        return $this->belongsTo('App\NianHao', 'c_by_nh_code', 'c_nianhao_id')->select(['c_nianhao_id','c_dynasty_chn','c_nianhao_chn']);
    }

    public function deathYearNH()
    {
        return $this->belongsTo('App\NianHao', 'c_dy_nh_code', 'c_nianhao_id');
    }

    public function simpleDeathYearNH()
    {
        return $this->belongsTo('App\NianHao', 'c_dy_nh_code', 'c_nianhao_id')->select(['c_nianhao_id','c_dynasty_chn','c_nianhao_chn']);
    }

    public function choronym()
    {
        return $this->belongsTo('App\ChoronymCode', 'c_choronym_code', 'c_choronym_code');
    }

    public function ethnicity()
    {
        return $this->belongsTo('App\Ethnicity', '﻿c_ethnicity_code', '﻿c_ethnicity_code');
    }

    public function simpleEthnicity()
    {
        return $this->belongsTo('App\Ethnicity', 'c_ethnicity_code', 'c_ethnicity_code')->select(['c_ethnicity_code', 'c_name_chn', 'c_name']);
    }

    public function sources()
    {
        return $this->belongsToMany('App\TextCode', 'BIOG_SOURCE_DATA', 'c_personid', 'c_textid')->withPivot('c_pages', 'c_notes', 'c_main_source', 'c_self_bio');
    }

    public function addresses()
    {
        return $this->belongsToMany('App\AddressCode', 'BIOG_ADDR_DATA', 'c_personid', 'c_addr_id')->withPivot('c_addr_type', 'c_firstyear', 'c_lastyear', 'c_sequence', 'tts_sysno');
    }

    public function addresses_type()
    {
        return $this->belongsToMany('App\BiogAddrCode', 'BIOG_ADDR_DATA', 'c_personid', 'c_addr_type');
    }

    public function altnames()
    {
        return $this->belongsToMany('App\AltnameCode', 'ALTNAME_DATA', 'c_personid', 'c_alt_name_type_code')->withPivot('c_alt_name', 'c_alt_name_chn', 'tts_sysno');
    }

    public function texts()
    {
        return $this->belongsToMany('App\TextCode', 'TEXT_DATA', 'c_personid', 'c_textid')->withPivot('tts_sysno');
    }

    public function texts_role()
    {
        return $this->belongsToMany('App\TextRoleCode', 'TEXT_DATA', 'c_personid', 'c_role_id');
    }

    public function offices()
    {
        return $this->belongsToMany('App\OfficeCode', 'POSTED_TO_OFFICE_DATA', 'c_personid', 'c_office_id')->withPivot('c_sequence', 'c_posting_id', 'c_firstyear', 'c_lastyear', 'tts_sysno')->orderBy('c_sequence');
    }

    public function offices_addr()
    {
        return $this->belongsToMany('App\AddressCode', 'POSTED_TO_ADDR_DATA', 'c_personid','c_addr_id')->withPivot('c_posting_id')->where('c_office_id', '!=', -1);
    }

    public function entries()
    {
        return $this->belongsToMany('App\EntryCode', 'ENTRY_DATA', 'c_personid', 'c_entry_code')->withPivot('c_sequence', 'tts_sysno')->orderBy('c_sequence');
    }

    public function statuses()
    {
        return $this->belongsToMany('App\StatusCode', 'STATUS_DATA', 'c_personid', 'c_status_code')->withPivot('c_sequence', 'c_lastyear', 'c_firstyear', 'tts_sysno')->orderBy('c_sequence');
    }

    public function events()
    {
        return $this->belongsToMany('App\EventCode', 'EVENTS_DATA', 'c_personid', 'c_event_code')->withPivot('c_sequence', 'tts_sysno')->orderBy('c_sequence');
    }

    public function kinship()
    {
        return $this->belongsToMany('App\KinshipCode', 'KIN_DATA', 'c_personid', 'c_kin_code')->withPivot('tts_sysno');
    }

    public function kinship_name()
    {
        return $this->belongsToMany('App\BiogMain', 'KIN_DATA', 'c_personid', 'c_kin_id')->select(['c_name', 'c_name_chn', 'c_kin_id']);
    }

    public function assoc()
    {
        return $this->belongsToMany('App\AssocCode', 'ASSOC_DATA', 'c_personid', 'c_assoc_code')->withPivot('tts_sysno', 'c_assoc_id');
    }

    public function assoc_name()
    {
        return $this->belongsToMany('App\BiogMain', 'ASSOC_DATA', 'c_personid', 'c_assoc_id')->select(['c_name', 'c_name_chn']);
    }

    public function possession()
    {
        return $this->belongsToMany('App\PossessionActCode', 'POSSESSION_DATA', 'c_personid', 'c_possession_act_code')->withPivot('c_sequence', 'c_possession_desc', 'c_possession_desc_chn', 'c_possession_record_id')->orderBy('c_sequence');
    }

    public function inst()
    {
        return $this->belongsToMany('App\BiogInstCode', 'BIOG_INST_DATA', 'c_personid', 'c_bi_role_code')->withPivot('c_bi_begin_year', 'c_bi_end_year', 'tts_sysno');
    }

    public function inst_name(){
        return $this->belongsToMany('App\SocialInst', 'BIOG_INST_DATA', 'c_personid', 'c_inst_name_code');
    }

    public function operation()
    {
        return $this->hasMany('App\Operation', 'c_personid', 'c_personid');
    }
}
