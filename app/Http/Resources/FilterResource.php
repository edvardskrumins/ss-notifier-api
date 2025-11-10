<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FilterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'max_length' => $this->max_length,
            'form_param' => $this->form_param,
            'values' => FilterValueResource::collection($this->whenLoaded('values')),
        ];
    }
}
