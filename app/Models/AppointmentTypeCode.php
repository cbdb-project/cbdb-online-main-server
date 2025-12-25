<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentTypeCode extends Model {
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    #20250321依據Appointment表重構，修改為存取APPOINTMENT_CODES表
    #protected $table = 'APPOINTMENT_TYPE_CODES';
    #protected $primaryKey = 'c_appt_type_code';
    protected $table = 'APPOINTMENT_CODES';
    protected $primaryKey = 'c_appt_code';

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
}
