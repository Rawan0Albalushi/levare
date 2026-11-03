<?php

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'port_number' => fake()->bothify('P-####'),
            'serial_number' => fake()->unique()->bothify('SN-######'),
            'description' => fake()->sentence(4),
            'assigned_well' => fake()->bothify('Well-##'),
            'recorded_on' => fake()->date(),
            'location' => fake()->bothify('Rack-?#'),
            'condition' => fake()->randomElement(['new', 'repaired']),
            'tested_at' => null,
        ];
    }

    public function repaired(): static
    {
        return $this->state(fn (): array => ['condition' => 'repaired']);
    }

    public function tested(?string $on = null): static
    {
        return $this->state(fn (): array => [
            'tested_at' => $on ?? now()->toDateString(),
        ]);
    }
}
