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
        return $this->belongsTo('App\Ethnicity', 'c_ethnicity_code', 'c_ethnicity_code');
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
        return $this->belongsToMany('App\AddressCode', 'BIOG_ADDR_DATA', 'c_personid', 'c_addr_id')->withPivot('c_firstyear', 'c_lastyear');
    }

    public function addresses_type()
    {
        return $this->belongsToMany('App\BiogAddrCode', 'BIOG_ADDR_DATA', 'c_personid', 'c_addr_type');
    }

    public function altnames()
    {
        return $this->belongsToMany('App\AltnameCode', 'ALTNAME_DATA', 'c_personid', 'c_alt_name_type_code')->withPivot('c_alt_name', 'c_alt_name_chn');
    }

    public function texts()
    {
        return $this->belongsToMany('App\TextCode', 'TEXT_DATA', 'c_personid', 'c_textid');
    }

    public function texts_role()
    {
        return $this->belongsToMany('App\TextRoleCode', 'TEXT_DATA', 'c_personid', 'c_role_id');
    }

    public function offices()
    {
        return $this->belongsToMany('App\OfficeCode', 'POSTED_TO_OFFICE_DATA', 'c_personid', 'c_office_id')->withPivot('c_sequence', 'c_posting_id', 'c_firstyear', 'c_lastyear')->orderBy('c_sequence');
    }

    public function offices_addr()
    {
        return $this->belongsToMany('App\AddressCode', 'POSTED_TO_ADDR_DATA', 'c_personid','c_addr_id')->withPivot('c_posting_id')->where('c_office_id', '!=', -1);
    }

    public function te()
    {
        return null;
    }
}
