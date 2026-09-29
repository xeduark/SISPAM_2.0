<?php

namespace Database\Factories;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'documento' => (string) fake()->unique()->numberBetween(10000000, 1999999999),
            'email' => fake()->unique()->safeEmail(),
            'sede_id' => Sede::factory(),
            'activo' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indica que el usuario está inactivo (no puede ingresar al panel).
     */
    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }
}
