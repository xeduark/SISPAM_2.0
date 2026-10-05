<?php

namespace Database\Factories;

use App\Models\Cola;
use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cola>
 */
class ColaFactory extends Factory
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
            'nombre' => 'Cola '.fake()->unique()->word(),
            'prefijo' => strtoupper(fake()->unique()->lexify('??')),
            'descripcion' => null,
            'activa' => true,
            'orden' => 0,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (array $attributes) => ['activa' => false]);
    }
}
