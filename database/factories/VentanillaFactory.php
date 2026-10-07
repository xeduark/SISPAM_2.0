<?php

namespace Database\Factories;

use App\Models\Sede;
use App\Models\Ventanilla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ventanilla>
 */
class VentanillaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sede_id' => Sede::factory(),
            'nombre' => 'Ventanilla '.fake()->unique()->numberBetween(1, 999),
            'activa' => true,
            'orden' => 0,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (array $attributes) => ['activa' => false]);
    }
}
