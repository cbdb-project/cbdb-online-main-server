<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class BiogAddressCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return parent::toArray($request);
    }

    public function with($request)
    {
        return [
            'biog' => [
                'c_name' => $this[0]->biog[0]->c_name,
                'c_name_chn' => $this[0]->biog[0]->c_name_chn,
                'c_dy' => $this[0]->biog[0]->dynasty->c_dynasty_chn,
            ],
            'links' => [
                'self' => url('v1/api/address/'),
            ]
        ];
    }
}


