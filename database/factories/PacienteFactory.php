<?php

namespace Database\Factories;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paciente>
 */
class PacienteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_documento' => 'CC',
            'numero_documento' => (string) fake()->unique()->numberBetween(10000000, 1999999999),
            'primer_nombre' => fake()->firstName(),
            'segundo_nombre' => fake()->firstName(),
            'primer_apellido' => fake()->lastName(),
            'segundo_apellido' => fake()->lastName(),
            'fecha_nacimiento' => fake()->date(),
            'sexo' => fake()->randomElement(['M', 'F']),
            'estado_afiliacion' => 'Activo',
            'regimen' => fake()->randomElement(['SUBSIDIADO', 'CONTRIBUTIVO']),
            'tipo_afiliado' => 'Cabeza de familia',
            'municipio_afiliacion' => fake()->city(),
            'departamento_afiliacion' => 'ANTIOQUIA',
            'email' => fake()->unique()->safeEmail(),
            'ips_primaria' => 'IPS '.fake()->lastName(),
            // Contacto: datos de SISPAM, confirmados con el paciente.
            'telefono_movil' => fake()->numerify('3#########'),
            'direccion' => fake()->streetAddress(),
            'barrio' => fake()->streetName(),
            'ciudad_residencia' => fake()->city(),
            'contacto_confirmado_at' => now(),
            'contacto_confirmado_por' => User::factory(),
            'programas' => [],
            'datos_adicionales' => [],
            'codigo_respuesta_savia' => '0',
            'consultado_en_savia_at' => now(),
        ];
    }

    /**
     * Paciente retirado de la EPS.
     */
    public function retirado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado_afiliacion' => 'Retirado',
            'fecha_retiro' => now()->subMonth()->toDateString(),
        ]);
    }

    /**
     * Paciente al que nadie le ha confirmado el contacto todavía.
     */
    public function sinContactoConfirmado(): static
    {
        return $this->state(fn (array $attributes) => [
            'contacto_confirmado_at' => null,
            'contacto_confirmado_por' => null,
        ]);
    }

    /**
     * Paciente con programas especiales y RIAS.
     */
    public function conProgramas(): static
    {
        return $this->state(fn (array $attributes) => [
            'programas' => [
                ['tipo' => 'RIAS', 'descripcion' => 'RIAS VISUAL'],
                ['tipo' => 'Programa Especial', 'descripcion' => 'Riesgo Cardiovascular'],
            ],
        ]);
    }
}
