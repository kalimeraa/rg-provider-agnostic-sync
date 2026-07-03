<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `viewHorizon` gate'i, non-local ortamlarda Horizon dashboard'una kimlerin
 * erişebileceğini belirler — varsayılan olarak boş bir e-posta listesi
 * (kimse) olduğu için gate her zaman `false` dönmeli.
 *
 * @covers \App\Providers\HorizonServiceProvider::gate
 */
class HorizonServiceProviderTest extends TestCase
{
    #[Test]
    public function viewHorizonGateDeniesAccessByDefault(): void
    {
        $this->assertFalse(Gate::check('viewHorizon'));
    }

    #[Test]
    public function viewHorizonGateDeniesAccessForAnyAuthenticatedUser(): void
    {
        $user = new class
        {
            public string $email = 'someone@example.com';
        };

        $this->assertFalse(Gate::forUser($user)->check('viewHorizon'));
    }
}
