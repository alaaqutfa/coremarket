<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Resources\Json\ResourceCollection;

class SliderCollection extends ResourceCollection
{
    public function toArray($request)
    {

        return [
            'data' => $this->collection->map(function ($data) {
                //dd($data);
                return [
                    'photo' => uploaded_asset($data['image']),
                    'mobile_photo' => !empty($data['mobile_image']) ? uploaded_asset($data['mobile_image']) : null,
                    'url' => ($data['link']),
                ];
            })
        ];
    }

    public function with($request)
    {
        return [
            'success' => true,
            'status' => 200
        ];
    }
}
