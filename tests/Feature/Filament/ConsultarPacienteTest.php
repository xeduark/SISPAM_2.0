<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ConsultarPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La pantalla de consulta se prueba sin tocar el servicio real:
 * Http::fake() responde en lugar de Savia.
 */
class ConsultarPacienteTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);

        $this->actingAs(User::factory()->create());
    }

    private function respuestaSavia(array $extra = []): array
    {
        return [
            'registros' => 1,
            'codigo' => 0,
            'mensaje' => 'Afiliado encontrado',
            'afiliados' => [array_merge([
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
                'descripcionCiudadResidencia' => 'MEDELLÍN',
                'zonaAfiliacion' => 'Urbana - Cabecera Municipal',
                'programas' => [
                    ['tipo' => 'RIAS', 'descripcion' => 'RIAS VISUAL'],
                    ['tipo' => 'Programa Especial', 'descripcion' => "Atenci\xC3\x83\xC2\xB3n domiciliaria"],
                ],
            ], $extra)],
        ];
    }

    private function fakeExitoso(array $extra = []): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response($this->respuestaSavia($extra)),
        ]);
    }

    private function buscar(string $numero = '1000873458', string $tipo = 'CC'): Testable
    {
        return Livewire::test(ConsultarPaciente::class)
            ->fillForm(['tipo_documento' => $tipo, 'numero_documento' => $numero])
            ->call('buscar');
    }

    public function test_la_pagina_carga_con_el_buscador_vacio(): void
    {
        Livewire::test(ConsultarPaciente::class)
            ->assertSuccessful()
            ->assertFormSet(['tipo_documento' => 'CC'])
            ->assertSet('consultado', false)
            ->assertSee('Buscar afiliado');
    }

    public function test_al_buscar_una_cedula_muestra_todos_los_datos_del_afiliado(): void
    {
        $this->fakeExitoso();

        $this->buscar()
            ->assertSuccessful()
            // Quién es
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN')
            ->assertSee('1000873458')
            // Si está afiliado
            ->assertSee('Afiliación: Activo')
            ->assertSee('CONTRIBUTIVO')
            // Los grupos de datos del servicio
            ->assertSee('Identificación del afiliado')
            ->assertSee('Estado de la afiliación')
            ->assertSee('Residencia y contacto')
            ->assertSee('IPS y portabilidad')
            ->assertSee('E.S.E METROSALUD')
            ->assertSee('MEDELLÍN')
            // Los programas, con el acento ya reparado
            ->assertSee('Programas especiales y RIAS')
            ->assertSee('RIAS VISUAL')
            ->assertSee('Atención domiciliaria')
            ->assertDontSee('AtenciÃ³n');
    }

    public function test_avisa_si_el_afiliado_no_esta_registrado_en_sispam(): void
    {
        $this->fakeExitoso();

        $this->buscar()
            ->assertSee('No registrado en SISPAM')
            ->assertSee('Registrar en SISPAM')
            ->assertSet('pacienteId', null);
    }

    public function test_avisa_si_el_afiliado_ya_esta_registrado_en_sispam(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
        ]);

        $this->fakeExitoso();

        $this->buscar()
            ->assertSee('Registrado en SISPAM')
            ->assertSee('Ver ficha en SISPAM')
            ->assertSet('pacienteId', $paciente->id);
    }

    public function test_registra_el_paciente_en_sispam_desde_la_consulta(): void
    {
        $this->fakeExitoso();

        $this->buscar()
            ->call('guardar')
            ->assertNotified('Paciente registrado en SISPAM');

        $paciente = Paciente::where('numero_documento', '1000873458')->firstOrFail();

        $this->assertSame('JUAN JOSE RAMIREZ PULGARIN', $paciente->nombre_completo);
        $this->assertSame('Activo', $paciente->estado_afiliacion);
        $this->assertSame('2003-02-04', $paciente->fecha_nacimiento->toDateString());
        $this->assertSame('Atención domiciliaria', $paciente->programas[1]['descripcion']);
        $this->assertSame('Urbana - Cabecera Municipal', $paciente->datos_adicionales['Zona de afiliación']);
        $this->assertNotNull($paciente->consultado_en_savia_at);
    }

    public function test_volver_a_guardar_actualiza_en_vez_de_duplicar(): void
    {
        Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
            'primer_nombre' => 'DESACTUALIZADO',
        ]);

        $this->fakeExitoso();

        $this->buscar()
            ->call('guardar')
            ->assertNotified('Paciente actualizado en SISPAM');

        $this->assertSame(1, Paciente::where('numero_documento', '1000873458')->count());
        $this->assertSame('JUAN', Paciente::where('numero_documento', '1000873458')->value('primer_nombre'));
    }

    public function test_avisa_claramente_cuando_el_afiliado_no_existe(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
                // El servicio real responde 200 con el código -1000, no 404.
            ], 200),
        ]);

        $this->buscar('111111111')
            ->assertSuccessful()
            ->assertSee('Afiliado no encontrado')
            ->assertSet('afiliado', null)
            ->assertDontSee('Registrar en SISPAM');
    }

    public function test_muestra_una_advertencia_cuando_el_afiliado_no_esta_activo(): void
    {
        $this->fakeExitoso([
            'estadoAfiliacion' => 'Retirado',
            'causaEstado' => 'RETIRO POR TRASLADO',
        ]);

        $this->buscar()
            ->assertSee('Afiliación: Retirado')
            ->assertSee('Este afiliado no está activo en Savia')
            ->assertSee('RETIRO POR TRASLADO')
            // Aun así se ven todos sus datos.
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN');
    }

    public function test_no_rompe_la_pagina_cuando_el_servicio_falla(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['error' => 'credenciales'], 401),
        ]);

        $this->buscar()
            ->assertSuccessful()
            ->assertSee('No se pudo obtener el token')
            ->assertSet('afiliado', null);
    }

    public function test_exige_el_numero_de_documento(): void
    {
        Http::fake();

        Livewire::test(ConsultarPaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => ''])
            ->call('buscar')
            ->assertHasFormErrors(['numero_documento' => 'required']);

        Http::assertNothingSent();
    }

    public function test_rechaza_un_documento_con_caracteres_no_permitidos(): void
    {
        Http::fake();

        Livewire::test(ConsultarPaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1000 873/458'])
            ->call('buscar')
            ->assertHasFormErrors(['numero_documento' => 'regex']);

        Http::assertNothingSent();
    }

    public function test_limpiar_borra_el_resultado_anterior(): void
    {
        $this->fakeExitoso();

        $this->buscar()
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN')
            ->call('limpiar')
            ->assertSet('afiliado', null)
            ->assertSet('consultado', false)
            ->assertFormSet(['tipo_documento' => 'CC'])
            ->assertDontSee('JUAN JOSE RAMIREZ PULGARIN');
    }
}
