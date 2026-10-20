<?php

namespace Tests\Feature\CuentasCompartidas;

use App\Filament\Pages\ConsultarPaciente;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Savia de mentiras para las pruebas de las cuentas de consulta: responde un
 * afiliado con datos básicos y con datos de salud, para comprobar qué llega a
 * la pantalla de cada quien. Nunca toca el servicio real.
 */
trait FingeSavia
{
    /** Lo que responde Savia; `saviaResponde()` lo cambia en cada prueba. */
    protected array $afiliadoDeSavia = [];

    protected function configurarSavia(): void
    {
        Cache::flush();

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);

        $this->afiliadoDeSavia = [
            'tipoDocumentoAfiliado' => 'CC',
            'documentoAfiliado' => '1000873458',
            'primerNombreAfiliado' => 'JUAN',
            'segundoNombreAfiliado' => 'JOSE',
            'primerApellidoAfiliado' => 'RAMIREZ',
            'segundoApellidoAfiliado' => 'PULGARIN',
            'fechaNacimientoAfiliado' => '2003-02-04',
            'estadoAfiliacion' => 'Activo',
            'regimen' => 'CONTRIBUTIVO',
            'categoriaIBC' => 'B',
            'municipioAfiliacion' => 'BELLO',
            'departamentoAfiliacion' => 'ANTIOQUIA',
            'ipsprimaria' => 'E.S.E METROSALUD',
            // El contacto: lo ve quien tenga `ver_contacto`, como CONSULTA.
            'telefonoMovil' => '3001234567',
            'telefono' => '6043005012',
            'email' => 'juan@ejemplo.com',
            // Lo que el rol CONSULTA no debe ver.
            'descripcionCiudadResidencia' => 'MEDELLÍN',
            'direccion' => 'KR 40 70A 23',
            'discapacidad' => 'SI',
            'nivelSisben' => 'A1',
            'programas' => [
                ['tipo' => 'Programa Especial', 'descripcion' => 'PROGRAMA VIH'],
            ],
        ];

        // Se lee al responder, no al fingir: así cada prueba ajusta el afiliado.
        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            '*rest/afiliado/consultar-afiliado' => fn () => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'mensaje' => 'Afiliado encontrado',
                'afiliados' => [$this->afiliadoDeSavia],
            ]),
        ]);
    }

    /**
     * Cambia lo que responde Savia. Un campo en null se quita de la respuesta.
     *
     * @param  array<string, mixed>  $cambios
     */
    protected function saviaResponde(array $cambios): void
    {
        $this->afiliadoDeSavia = array_filter(
            array_merge($this->afiliadoDeSavia, $cambios),
            fn ($valor) => $valor !== null,
        );
    }

    protected function consultar(string $numero = '1000873458', string $tipo = 'CC'): Testable
    {
        return Livewire::test(ConsultarPaciente::class)
            ->fillForm(['tipo_documento' => $tipo, 'numero_documento' => $numero])
            ->call('buscar');
    }
}
