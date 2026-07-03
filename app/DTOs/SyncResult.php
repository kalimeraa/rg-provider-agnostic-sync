<?php

namespace App\DTOs;

/**
 * Bir sync run'ının (tüm sayfa job'ları + sweep-delete adımı bitince)
 * nihai özeti. `SyncRunCoordinator::finishSuccessfully()` bunu, sayfa
 * job'larının Redis'te biriktirdiği added/updated sayaçlarıyla ve
 * sweep-delete'in bulduğu deleted sayısıyla oluşturup doğrudan `SyncLog`
 * kolonlarına yazar.
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
