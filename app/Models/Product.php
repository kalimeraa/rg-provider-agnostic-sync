<?php

namespace App\Models;

use App\Enums\ProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bir tedarikçiden senkronize edilmiş ürün. Kimliği (provider_type,
 * external_id) composite unique constraint'idir — aynı gerçek dünya ürünü
 * iki farklı provider'dan geliyorsa iki ayrı satır olarak saklanır.
 *
 * Provider'ın son sync'inde artık bulunmuyorsa hard-delete değil
 * soft-delete edilir; böylece geçmiş korunur ve ürün provider'da tekrar
 * ortaya çıkarsa restore edilebilir (bkz. DeltaSyncService).
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'provider_type',
        'external_id',
        'name',
        'price',
        'stock',
        'description',
        'data_hash',
        'last_synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'provider_type' => ProviderType::class,
        'price' => 'decimal:2',
        'stock' => 'integer',
        'last_synced_at' => 'datetime',
    ];
}
