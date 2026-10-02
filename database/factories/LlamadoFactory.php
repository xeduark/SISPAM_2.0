<?php

namespace Database\Factories;

use App\Models\Llamado;
use App\Models\Ticket;
use App\Models\Ventanilla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Llamado>
 */
class LlamadoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ticket = Ticket::factory();

        return [
            'ticket_id' => $ticket,
            // Las resuelve `configure()` con las del ticket, para no cruzar sedes.
            'sede_id' => null,
            'cola_id' => null,
            'ventanilla_id' => null,
            'intento' => 1,
        ];
    }

    /**
     * El llamado siempre pertenece a la sede y la cola de su ticket.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Llamado $llamado): void {
            $ticket = $llamado->ticket ?? Ticket::find($llamado->ticket_id);

            $llamado->sede_id ??= $ticket?->sede_id;
            $llamado->cola_id ??= $ticket?->cola_id;
            $llamado->ventanilla_id ??= Ventanilla::factory()->create(['sede_id' => $llamado->sede_id])->getKey();
        });
    }
}
