<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/products` response şekli. Product modelinin dış dünyaya açılan
 * temsili — `data_hash` gibi tamamen dahili kolonlar API tüketicisine
 * sızdırılmaz.
 *
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider_type->value,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'stock' => $this->stock,
            'description' => $this->description,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
