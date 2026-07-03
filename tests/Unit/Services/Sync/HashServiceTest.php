<?php

namespace Tests\Unit\Services\Sync;

use App\Services\Sync\HashService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case gereksinimi: "Hash calculation doğruluğu" mutlaka test edilmeli.
 * `HashService` bağımlılıksız (DB/HTTP yok), bu yüzden `TestCase`
 * (Laravel bootstrap'ı) üzerinden ama `RefreshDatabase` OLMADAN çalışır.
 *
 * @covers \App\Services\Sync\HashService::hash
 */
class HashServiceTest extends TestCase
{
    private HashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hashService = new HashService();
    }

    /**
     * Aynı input her zaman aynı hash'i üretmeli (deterministik).
     */
    #[Test]
    public function sameInputProducesSameHash(): void
    {
        $product = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertSame(
            $this->hashService->hash($product),
            $this->hashService->hash($product)
        );
    }

    /**
     * Üretilen hash sha256 formatında (64 karakter hex) olmalı.
     */
    #[Test]
    public function producesA64CharacterHexSha256Hash(): void
    {
        $hash = $this->hashService->hash(['name' => 'X', 'price' => 1, 'stock' => 1, 'description' => 'Y']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    /**
     * İsim değişince hash de değişmeli.
     */
    #[Test]
    public function hashChangesWhenNameChanges(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Silgi', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    /**
     * Fiyat değişince hash de değişmeli.
     */
    #[Test]
    public function hashChangesWhenPriceChanges(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Kalem', 'price' => 12.50, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    /**
     * Stok değişince hash de değişmeli.
     */
    #[Test]
    public function hashChangesWhenStockChanges(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 5, 'description' => 'Mavi kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    /**
     * Açıklama değişince hash de değişmeli.
     */
    #[Test]
    public function hashChangesWhenDescriptionChanges(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Kırmızı kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    /**
     * Float temsil hassasiyeti farklılıkları (ör. 9.99 vs 9.9900000001),
     * `number_format` ile 2 ondalığa sabitlendiği için hash'i DEĞİŞTİRMEMELİ.
     */
    #[Test]
    public function floatingPointRepresentationDifferencesDoNotChangeTheHash(): void
    {
        $a = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $b = ['name' => 'Kalem', 'price' => 9.9900000001, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertSame($this->hashService->hash($a), $this->hashService->hash($b));
    }

    /**
     * DB'den decimal cast'li okunan price'lar string olarak da gelebilir —
     * numeric ve string temsili AYNI hash'i üretmeli.
     */
    #[Test]
    public function stringAndNumericPriceProduceTheSameHash(): void
    {
        $numeric = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $string = ['name' => 'Kalem', 'price' => '9.99', 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertSame($this->hashService->hash($numeric), $this->hashService->hash($string));
    }

    /**
     * Eksik (`null`) açıklama, boş string ile aynı şekilde işlenmeli.
     */
    #[Test]
    public function missingDescriptionIsTreatedTheSameAsEmptyString(): void
    {
        $withNull = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => null];
        $withEmpty = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => ''];

        $this->assertSame($this->hashService->hash($withNull), $this->hashService->hash($withEmpty));
    }
}
