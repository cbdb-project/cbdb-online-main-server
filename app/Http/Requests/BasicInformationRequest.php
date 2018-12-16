<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BasicInformationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'c_mingzi_chn' => 'required',
            'c_mingzi' => 'required',
            'c_index_year' => 'min:-3000|max:3000',
            'c_death_age' => 'min:0|max:200'
        ];
    }

    public function messages()
    {
        return [
            'c_mingzi_chn.required' => '姓不能为空',
            'c_mingzi.required' => 'Ming不能为空',
            'c_index_year.min|max' => '指数年取值范围-3000到3000',
            'c_death_age.min|max' => '享年范围0到200'
        ];
    }
}
