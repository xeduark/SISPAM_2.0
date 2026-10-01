<?php

namespace App\Services\Ticket;

use App\Contracts\Ticket\Dto\PacienteResumenDto;
use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\Dto\TicketItemDto;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Models\Paciente;

/**
 * Datos de prueba mientras el módulo de ticket no esté listo.
 * Si hay pacientes en SISPAM, usa el primero; si no, inventa un resumen.
 */
class TicketConsultaMock implements TicketConsultaInterface
{
    public function buscarPorNumero(string $numero): ?TicketDto
    {
        $numero = trim($numero);

        if ($numero === '') {
            return null;
        }

        // Números de demo conocidos; el resto también responde para facilitar pruebas locales.
        $paciente = Paciente::query()->orderBy('id')->first();

        $resumen = $paciente
            ? new PacienteResumenDto(
                id: $paciente->id,
                tipoDocumento: $paciente->tipo_documento,
                numeroDocumento: $paciente->numero_documento,
                nombreCompleto: $paciente->nombre_completo,
                telefonoMovil: $paciente->telefono_movil ?? $paciente->telefono,
                direccion: $paciente->direccion,
                barrio: $paciente->barrio,
                ciudad: $paciente->ciudad_residencia,
                indicacionesEntrega: $paciente->indicaciones_entrega,
                contactoConfirmado: $paciente->tieneContactoConfirmado(),
            )
            : new PacienteResumenDto(
                id: null,
                tipoDocumento: 'CC',
                numeroDocumento: '1000873458',
                nombreCompleto: 'PACIENTE DEMO MOCK',
                telefonoMovil: '3001234567',
                direccion: 'Calle 10 # 20-30',
                barrio: 'Centro',
                ciudad: 'Medellín',
                indicacionesEntrega: 'Portería torre 2',
                contactoConfirmado: true,
            );

        $sedeId = (int) (auth()->user()?->sede_id ?? 1);
        $altoCosto = str_ends_with(strtoupper($numero), 'AC');

        return new TicketDto(
            numero: $numero,
            estado: 'listo',
            sedeId: $sedeId,
            paciente: $resumen,
            items: [
                new TicketItemDto('1', 'MED-001', 'ACETAMINOFEN 500 MG TABLETA', 20, 'TAB'),
                new TicketItemDto('2', 'MED-002', 'LOSARTAN 50 MG TABLETA', 30, 'TAB'),
                new TicketItemDto('3', 'MED-003', 'METFORMINA 850 MG TABLETA', 60, 'TAB'),
            ],
            altoCosto: $altoCosto,
            turno: $numero,
        );
    }
}
