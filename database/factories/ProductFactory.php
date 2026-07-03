<?php

namespace Database\Factories;

use App\Enums\ProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Product için test/seed factory'si. `data_hash`, diğer üretilen alanlarla
 * gerçekten tutarlı olacak şekilde (HashService'in hesaplayacağı gibi)
 * üretilir; böylece hash karşılaştırma testlerinin hash'i ayrıca sahte
 * üretmesi gerekmez.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $data = [
            'name' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 1, 500),
            'stock' => fake()->numberBetween(0, 200),
            'description' => fake()->sentence(),
        ];

        return [
            'provider_type' => fake()->randomElement(ProviderType::cases()),
            'external_id' => (string) fake()->unique()->numberBetween(1, 100000),
            'name' => $data['name'],
            'price' => $data['price'],
            'stock' => $data['stock'],
            'description' => $data['description'],
            'data_hash' => hash('sha256', json_encode([
                'name' => $data['name'],
                'price' => $data['price'],
                'stock' => $data['stock'],
                'description' => $data['description'],
            ])),
            'last_synced_at' => now(),
        ];
    }
}
