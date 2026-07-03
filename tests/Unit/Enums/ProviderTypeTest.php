<?php

namespace Tests\Unit\Enums;

use App\Enums\ProviderType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @covers \App\Enums\ProviderType
 */
class ProviderTypeTest extends TestCase
{
    #[Test]
    public function dummyJsonLabelIsHumanReadable(): void
    {
        $this->assertSame('DummyJSON', ProviderType::DummyJson->label());
    }

    #[Test]
    public function fakeStoreLabelIsHumanReadable(): void
    {
        $this->assertSame('FakeStore API', ProviderType::FakeStore->label());
    }

    #[Test]
    public function backedValueMatchesPersistedProviderKey(): void
    {
        // provider_type kolonlarında ve config/sync.php key'lerinde kullanılan
        // kalıcı kimlikler — bunlar değişirse mevcut DB verisi/config kırılır.
        $this->assertSame('dummyjson', ProviderType::DummyJson->value);
        $this->assertSame('fakestore', ProviderType::FakeStore->value);
    }

    #[Test]
    public function exactlyTwoProvidersAreSupported(): void
    {
        $this->assertCount(2, ProviderType::cases());
    }
}
