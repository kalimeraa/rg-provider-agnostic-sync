<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gerçek zamanlı dashboard doğrudan kök URL'de (`/`) sunulur — case'in
 * istediği "auto-refresh"in ötesinde, Reverb ile sıfır-polling bir UI
 * (bkz. README.md §8). Bu test sadece route'un/view'in gerçekten 200
 * döndüğünü doğrular; JS/WebSocket davranışı tarayıcıda test edilir.
 */
class DashboardTest extends TestCase
{
    /**
     * `routes/web.php`'deki `/` route'u, `resources/views/dashboard.blade.php`
     * view'ini başarıyla render etmeli.
     */
    #[Test]
    public function rootUrlServesTheDashboardView(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('dashboard');
    }
}
