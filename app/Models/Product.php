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
        'last_synced_log_id',
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
        'last_synced_log_id' => 'integer',
    ];

    /**
     * Eloquent varsayılan olarak tarihleri DB'ye saniye hassasiyetinde
     * (`Y-m-d H:i:s`) yazar. `last_synced_at` sadece insan-okunur bilgi
     * amaçlı olsa da mikrosaniye hassasiyeti tutarlılık için korunur;
     * SİLME KARARI ARTIK BUNA DAYANMIYOR (bkz. `last_synced_log_id` ve
     * SyncRunCoordinator'ın PHPDoc'u — saat yerine monoton bir ID kullanmak,
     * Http::fake ile gerçek gecikme olmadan art arda çalışan run'ların
     * container'ın saat çözünürlüğünde çakışma riskini ortadan kaldırır).
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
