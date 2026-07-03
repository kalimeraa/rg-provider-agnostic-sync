<?php

namespace Tests\Unit\Services\Sync;

use App\Services\Sync\HashService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case gereksinimi: "Hash calculation doğruluğu" mutlaka test edilmeli.
 * `HashService` bağımlılıksız (DB/HTTP yok), bu yüzden `TestCase`
 * (Laravel bootstrap'ı) üzerinden ama `RefreshDatabase` OLMADAN çalışır.
 */
class HashServiceTest extends TestCase
{
    private HashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hashService = new HashService();
    }

    #[Test]
    public function ayni_input_ayni_hashi_uretir(): void
    {
        $product = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertSame(
            $this->hashService->hash($product),
            $this->hashService->hash($product)
        );
    }

    #[Test]
    public function sha256_formatinda_64_karakter_hex_doner(): void
    {
        $hash = $this->hashService->hash(['name' => 'X', 'price' => 1, 'stock' => 1, 'description' => 'Y']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    #[Test]
    public function isim_degisince_hash_degisir(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Silgi', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    #[Test]
    public function fiyat_degisince_hash_degisir(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Kalem', 'price' => 12.50, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    #[Test]
    public function stok_degisince_hash_degisir(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 5, 'description' => 'Mavi kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    #[Test]
    public function aciklama_degisince_hash_degisir(): void
    {
        $base = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $changed = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Kırmızı kalem'];

        $this->assertNotSame($this->hashService->hash($base), $this->hashService->hash($changed));
    }

    #[Test]
    public function float_temsil_farkliliklari_hashi_degistirmez(): void
    {
        // 9.99 ile 9.990000000000001 gibi kayan nokta artefaktları HashService'in
        // number_format ile 2 ondalığa sabitlemesi sayesinde AYNI hash'i üretmeli.
        $a = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $b = ['name' => 'Kalem', 'price' => 9.9900000001, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertSame($this->hashService->hash($a), $this->hashService->hash($b));
    }

    #[Test]
    public function string_price_de_dogru_islenir(): void
    {
        // DB'den decimal cast'li okunan price'lar string olarak da gelebilir.
        $numeric = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $string = ['name' => 'Kalem', 'price' => '9.99', 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->assertSame($this->hashService->hash($numeric), $this->hashService->hash($string));
    }

    #[Test]
    public function eksik_aciklama_bos_string_gibi_islenir(): void
    {
        $withNull = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => null];
        $withEmpty = ['name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => ''];

        $this->assertSame($this->hashService->hash($withNull), $this->hashService->hash($withEmpty));
    }
}
