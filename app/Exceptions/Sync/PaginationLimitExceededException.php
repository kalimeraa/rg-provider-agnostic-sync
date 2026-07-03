<?php

namespace App\Exceptions\Sync;

/**
 * Bir provider'ın raporladığı `totalPages`, `SyncRunCoordinator::MAX_PAGES`
 * güvenlik sınırını aşınca fırlatılır. Bu, provider'ın `total` alanının
 * bozuk/anormal büyük döndüğü durumlarda binlerce `FetchProviderPageJob`
 * kuyruklanmasını (ve muhtemelen çok uzun sürüp Horizon'un job timeout'unu
 * aşmasını) engeller — bunun yerine anlaşılır bir hata ile hemen durur.
 */
class PaginationLimitExceededException extends ProviderRequestException
{
}
