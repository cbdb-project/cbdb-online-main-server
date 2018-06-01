<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\Resource;

class BiogMain extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
//        return parent::toArray($request);
        $json = array_except(parent::toArray($request), ['tts_sysno']);
        return $json;
    }

    public function with($request)
    {
        return [
            'links' => [
                'self' => url('v1/api/biog/'.$this->c_personid),
            ]
        ];
    }
}
