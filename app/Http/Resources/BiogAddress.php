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
            'biog' => [
                'c_personid' => $this->c_personid,
                'c_name_chn' => $this->c_name_chn
            ],
            'address' => $this->addresses
        ];
    }

}
