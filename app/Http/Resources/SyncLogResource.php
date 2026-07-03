<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/sync/status` ve `GET /api/sync/history` response şekli —
 * bir sync denemesinin (SyncLog satırının) dışa açılan temsili.
 *
 * @mixin \App\Models\SyncLog
 */
class SyncLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider_type->value,
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'products_added' => $this->products_added,
            'products_updated' => $this->products_updated,
            'products_deleted' => $this->products_deleted,
            'error_message' => $this->error_message,
        ];
    }
}
