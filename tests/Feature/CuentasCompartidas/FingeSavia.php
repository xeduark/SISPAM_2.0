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
    protected function configurarSavia(): void
    {
        Cache::flush();

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);

        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            '*rest/afiliado/consultar-afiliado' => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'mensaje' => 'Afiliado encontrado',
                'afiliados' => [[
                    'tipoDocumentoAfiliado' => 'CC',
                    'documentoAfiliado' => '1000873458',
                    'primerNombreAfiliado' => 'JUAN',
                    'segundoNombreAfiliado' => 'JOSE',
                    'primerApellidoAfiliado' => 'RAMIREZ',
                    'segundoApellidoAfiliado' => 'PULGARIN',
                    'fechaNacimientoAfiliado' => '2003-02-04',
                    'estadoAfiliacion' => 'Activo',
                    'regimen' => 'CONTRIBUTIVO',
                    'ipsprimaria' => 'E.S.E METROSALUD',
                    // Lo que el rol CONSULTA no debe ver.
                    'descripcionCiudadResidencia' => 'MEDELLÍN',
                    'telefonoMovil' => '3001234567',
                    'discapacidad' => 'SI',
                    'nivelSisben' => 'A1',
                    'programas' => [
                        ['tipo' => 'Programa Especial', 'descripcion' => 'PROGRAMA VIH'],
                    ],
                ]],
            ]),
        ]);
    }

    protected function consultar(string $numero = '1000873458'): Testable
    {
        return Livewire::test(ConsultarPaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => $numero])
            ->call('buscar');
    }
}
