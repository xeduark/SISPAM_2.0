<?php

namespace Database\Factories;

use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consecutivo = fake()->unique()->numberBetween(1, 9999);
        $sufijo = str_pad((string) $consecutivo, 3, '0', STR_PAD_LEFT);

        return [
            'numero' => 'SP-TEST-'.now()->format('Ymd').'-A'.$sufijo,
            'turno' => 'A-'.$sufijo,
            'fecha' => now()->toDateString(),
            'paciente_id' => Paciente::factory(),
            'sede_id' => Sede::factory(),
            // La resuelve `configure()`, para que quede en la misma sede del ticket.
            'cola_id' => null,
            'estado' => Ticket::ESTADO_GENERADO,
            'estado_sala' => Ticket::SALA_EN_ESPERA,
            'prioridad' => Ticket::PRIORIDAD_NORMAL,
            'alto_costo' => false,
            'creado_por' => User::factory(),
        ];
    }

    /**
     * La cola siempre tiene que ser de la sede del ticket: si no se pasa una,
     * se crea en esa misma sede.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Ticket $ticket): void {
            if ($ticket->cola_id === null) {
                $ticket->cola_id = Cola::factory()->create(['sede_id' => $ticket->sede_id])->getKey();
            }
        });
    }

    public function estado(string $estado): static
    {
        return $this->state(fn (array $attributes) => ['estado' => $estado]);
    }

    public function listo(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => Ticket::ESTADO_LISTO,
            'alistado_por' => User::factory(),
            'alistado_en' => now(),
        ]);
    }

    /** Ya se llamó a la ventanilla y se está esperando que llegue. */
    public function llamado(?int $ventanillaId = null): static
    {
        return $this->listo()->state(fn (array $attributes) => [
            'estado_sala' => Ticket::SALA_LLAMADO,
            'ventanilla_id' => $ventanillaId,
            'llamado_en' => now(),
        ]);
    }

    /** Se llamó y no apareció. */
    public function ausente(): static
    {
        return $this->listo()->state(fn (array $attributes) => [
            'estado_sala' => Ticket::SALA_AUSENTE,
            'llamado_en' => now(),
        ]);
    }

    public function preferencial(string $motivo = 'adulto_mayor'): static
    {
        return $this->state(fn (array $attributes) => [
            'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
            'motivo_prioridad' => $motivo,
        ]);
    }

    public function altoCosto(): static
    {
        return $this->state(fn (array $attributes) => ['alto_costo' => true]);
    }
}
