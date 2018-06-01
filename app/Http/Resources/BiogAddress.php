<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\Resource;

class BiogAddress extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'c_name_chn' => $this->c_name_chn,
            'tts_sysno' => $this->pivot->tts_sysno
        ];
    }

}
