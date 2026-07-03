<?php

namespace App\Models;

use App\Enums\ProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Her sync denemesi için bir satır (retry'lar dahil her SyncProviderJob
 * çalıştırması kendi satırını yazar, ortak bir satırı mutasyona uğratmaz).
 * GET /api/sync/status ve GET /api/sync/history endpoint'lerini besler.
 */
class SyncLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'provider_type',
        'started_at',
        'completed_at',
        'status',
        'products_added',
        'products_updated',
        'products_deleted',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'provider_type' => ProviderType::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'products_added' => 'integer',
        'products_updated' => 'integer',
        'products_deleted' => 'integer',
    ];
}
