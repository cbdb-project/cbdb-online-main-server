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
            'c_mingzi_chn.required' => '名不能為空',
            'c_mingzi.required' => 'Ming不能為空',
            'c_index_year.min|max' => '指數年取值範圍-3000到3000',
            'c_death_age.min|max' => '享年範圍0到200'
        ];
    }
}
