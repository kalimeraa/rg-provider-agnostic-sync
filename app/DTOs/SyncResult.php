<?php

namespace App\DTOs;

/**
 * DeltaSyncService::sync()'in dönüş değeri. Servis katmanından job/controller
 * katmanına çıplak bir array yerine tip güvenli bir değer geçmek için
 * kullanılan DTO — SyncProviderJob bunu doğrudan SyncLog kolonlarına yazar.
 */
final class SyncResult
{
    /**
     * @param  int  $added  Bu sync'te yeni eklenen ürün sayısı.
     * @param  int  $updated  Hash'i değiştiği için güncellenen ürün sayısı.
     * @param  int  $deleted  Provider'da artık bulunmadığı için soft-delete edilen ürün sayısı.
     */
    public function __construct(
        public readonly int $added,
        public readonly int $updated,
        public readonly int $deleted,
    ) {
    }
}
