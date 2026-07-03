<?php

namespace Database\Factories;

use App\Enums\ProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * SyncLog için test/seed factory'si. Varsayılan state başarıyla tamamlanmış
 * bir çalıştırmadır; hata senaryolarını test etmek için (dashboard'daki
 * failed-run gösterimi, alert threshold testleri vb.) `failed()` state'ini
 * kullan.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SyncLog>
 */
class SyncLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', 'now');

        return [
            'provider_type' => fake()->randomElement(ProviderType::cases()),
            'started_at' => $startedAt,
            'completed_at' => (clone $startedAt)->modify('+'.fake()->numberBetween(1, 30).' seconds'),
            'status' => 'completed',
            'products_added' => fake()->numberBetween(0, 20),
            'products_updated' => fake()->numberBetween(0, 20),
            'products_deleted' => fake()->numberBetween(0, 5),
            'error_message' => null,
        ];
    }

    /**
     * Hatayla sonuçlanmış bir çalıştırma state'i (gerçek bir başarısız
     * provider çağrısına ihtiyaç duymadan failed-jobs/alerting/history
     * arayüzlerini test etmek için kullanılır).
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
        ]);
    }
}
