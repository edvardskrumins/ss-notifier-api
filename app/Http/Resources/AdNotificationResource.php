<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdNotificationResource extends JsonResource
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
            'name' => $this->name,
            'active' => $this->active,
            'last_ad_id' => $this->last_ad_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'filters' => AdNotificationFilterResource::collection($this->whenLoaded('filters')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
