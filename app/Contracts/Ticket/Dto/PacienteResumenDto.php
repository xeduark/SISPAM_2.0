<?php

namespace App\Contracts\Ticket\Dto;

/**
 * Datos mínimos del paciente que el ticket expone a entrega.
 */
readonly class PacienteResumenDto
{
    public function __construct(
        public ?int $id,
        public string $tipoDocumento,
        public string $numeroDocumento,
        public string $nombreCompleto,
        public ?string $telefonoMovil = null,
        public ?string $direccion = null,
        public ?string $barrio = null,
        public ?string $ciudad = null,
        public ?string $indicacionesEntrega = null,
        public bool $contactoConfirmado = false,
    ) {}
}
