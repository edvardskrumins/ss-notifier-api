<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdNotificationFilterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filter_id' => $this->filter_id,
            'value' => $this->value,
            'filter_value_id' => $this->filter_value_id,
            'is_min' => $this->is_min,
        ];
    }
}
