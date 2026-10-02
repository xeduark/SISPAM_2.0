<?php

namespace Database\Factories;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Auditoria>
 */
class AuditoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $usuario = User::factory();

        return [
            'usuario_id' => $usuario,
            'usuario_nombre' => fake()->name(),
            'usuario_documento' => (string) fake()->numberBetween(10000000, 1999999999),
            'sede_id' => null,
            'accion' => Auditoria::ACCION_ACTUALIZO,
            'entidad_tipo' => 'paciente',
            'entidad_id' => 1,
            'descripcion' => 'Actualizó el paciente CC 1000873458',
            'cambios' => ['regimen' => ['SUBSIDIADO', 'CONTRIBUTIVO']],
            'ip' => '127.0.0.1',
            'navegador' => 'PHPUnit',
            'created_at' => now(),
        ];
    }

    public function accion(string $accion): static
    {
        return $this->state(fn (array $attributes) => ['accion' => $accion]);
    }
}
