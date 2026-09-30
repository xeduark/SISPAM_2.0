<?php

namespace App\Filament\Resources\PacienteResource;

use App\Models\Paciente;

/**
 * Lo que hace falta saber después de consultar un documento en Savia.
 *
 * Separa a propósito los datos de afiliación (que Savia manda) del contacto
 * (que manda SISPAM), y dice si el paciente ya estaba registrado, porque de
 * eso depende si el formulario crea o actualiza.
 */
final class ResultadoConsulta
{
    /**
     * @param  array<string, mixed>  $atributos  Columnas que llena Savia
     * @param  array<string, string|null>  $contactoSavia  Contacto reportado por Savia, solo de referencia
     * @param  Paciente|null  $existente  El paciente, si ya estaba en SISPAM
     */
    public function __construct(
        public readonly array $atributos,
        public readonly array $contactoSavia,
        public readonly ?Paciente $existente,
    ) {}

    public function yaEstabaRegistrado(): bool
    {
        return $this->existente !== null;
    }

    /**
     * El contacto con el que se precarga el formulario: el de SISPAM si el
     * paciente ya existe, el de Savia si es nuevo.
     *
     * @return array<string, string|null>
     */
    public function contactoParaElFormulario(): array
    {
        if ($this->existente === null) {
            return $this->contactoSavia;
        }

        return $this->existente->only(Paciente::CAMPOS_CONTACTO);
    }

    /**
     * Qué cambiaría al guardar, frente a lo que ya hay registrado.
     *
     * @return array<string, string>
     */
    public function cambios(): array
    {
        return $this->existente?->cambiosFrenteA($this->atributos) ?? [];
    }
}
