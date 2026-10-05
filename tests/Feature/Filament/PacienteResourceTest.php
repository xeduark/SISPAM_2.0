<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PacienteResource\Pages\CreatePaciente;
use App\Filament\Resources\PacienteResource\Pages\EditPaciente;
use App\Filament\Resources\PacienteResource\Pages\ListPacientes;
use App\Filament\Resources\PacienteResource\Pages\ViewPaciente;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El formulario y la tabla se prueban sin tocar el servicio real:
 * Http::fake() responde en lugar de Savia.
 */
class PacienteResourceTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

    private User $admin;

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

        // Personal de farmacia: todo el módulo Pacientes, sin la toma de datos del orientador.
        Rol::create(['nombre' => 'PERSONAL', 'permisos' => ['pacientes' => ['ver', 'crear', 'editar', 'eliminar']]]);
        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => ['orientacion' => ['usar']]]);

        $this->admin = User::factory()->create(['roles' => ['PERSONAL']]);
        $this->actingAs($this->admin);
    }

    private function respuestaSavia(array $extra = [], int $registros = 1): array
    {
        return [
            'registros' => $registros,
            'codigo' => 0,
            'mensaje' => 'Afiliado encontrado',
            'fechaTransaccion' => '29/09/2026 10:00:00',
            'afiliados' => [array_merge([
                'tipoDocumentoAfiliado' => 'CC',
                'documentoAfiliado' => '1017234567',
                'primerNombreAfiliado' => 'JUAN',
                'segundoNombreAfiliado' => 'CARLOS',
                'primerApellidoAfiliado' => 'PEREZ',
                'segundoApellidoAfiliado' => 'GOMEZ',
                'fechaNacimientoAfiliado' => '1990-05-14',
                'sexoAfiliado' => 'M',
                'estadoAfiliacion' => 'Activo',
                'regimen' => 'SUBSIDIADO',
                'tipoAfiliado' => 'Cabeza de familia',
                'municipioAfiliacion' => 'MEDELLIN',
                'departamentoAfiliacion' => 'ANTIOQUIA',
                'telefonoMovil' => '3001234567',
                'telefono' => '6043005012',
                'direccion' => 'KR 40 70A 23',
                'barrio' => 'MANRIQUE ORIENTAL',
                'descripcionCiudadResidencia' => 'MEDELLÍN',
                'email' => 'juan@ejemplo.com',
                'ipsprimaria' => 'IPS MENTE PLENA',
                'zonaAfiliacion' => 'URBANA',
            ], $extra)],
        ];
    }

    private function fakeConsultaExitosa(array $extra = []): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response($this->respuestaSavia($extra)),
        ]);
    }

    /** Abre el formulario de creación con el documento ya escrito. */
    private function crearPagina(string $numero = '1017234567', string $tipo = 'CC'): Testable
    {
        return Livewire::test(CreatePaciente::class)
            ->fillForm([
                'tipo_documento' => $tipo,
                'numero_documento' => $numero,
            ]);
    }

    /**
     * Pulsa «Consultar en Savia».
     *
     * Se llama el método de Livewire directamente porque el helper
     * `callFormComponentAction()` da por hecho que la acción no abre ningún
     * modal, y el aviso de «no encontrado» sí abre uno.
     */
    private function pulsarConsultar(Testable $pagina): Testable
    {
        return $pagina->call(
            'mountFormComponentAction',
            'data.consultarEnSaviaAction',
            'consultarEnSavia',
        );
    }

    /**
     * Marca la casilla de confirmación del contacto, que es obligatoria.
     * Los campos ya vienen precargados por la consulta.
     */
    private function confirmarContacto(Testable $pagina, array $contacto = []): Testable
    {
        return $pagina->fillForm(array_merge(['contacto_confirmado' => true], $contacto));
    }

    /** Monta el formulario de creación y pulsa «Consultar en Savia». */
    private function consultar(string $numero = '1017234567', string $tipo = 'CC'): Testable
    {
        return $this->pulsarConsultar($this->crearPagina($numero, $tipo));
    }

    /* ------------------------------------------------------------------ *
     *  Asistente por pasos
     * ------------------------------------------------------------------ */

    public function test_el_paso_de_contacto_no_deja_avanzar_sin_los_obligatorios(): void
    {
        $this->fakeConsultaExitosa();

        $pagina = $this->consultar()->fillForm(['telefono_movil' => null]);

        // Paso 1 (Identificación) viene completo desde Savia: avanza sin errores.
        $pagina->call('dispatchFormEvent', 'wizard::nextStep', 'data', 0)
            ->assertHasNoFormErrors();

        // Paso 2 (Ubicación y contacto) exige teléfono y la confirmación.
        $pagina->call('dispatchFormEvent', 'wizard::nextStep', 'data', 1)
            ->assertHasFormErrors(['telefono_movil' => 'required', 'contacto_confirmado' => 'accepted']);
    }

    /* ------------------------------------------------------------------ *
     *  Orientación: orden médica y alto costo (rol ORIENTADOR)
     * ------------------------------------------------------------------ */

    public function test_sin_rol_orientador_no_aparece_el_paso_de_orientacion(): void
    {
        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar())
            ->assertDontSee('Orden médica')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Paciente::first()->soportes()->count());
    }

    public function test_el_orientador_debe_cargar_la_orden_medica(): void
    {
        $this->admin->update(['roles' => ['PERSONAL', 'ORIENTADOR']]);
        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar())
            ->assertSee('Orden médica')
            ->call('create')
            ->assertHasFormErrors(['orden_medica' => 'required']);

        $this->assertDatabaseCount('pacientes', 0);
    }

    /**
     * Al orientador no se le pregunta por alto costo: no conoce los
     * medicamentos. Lo marca farmacia al alistar.
     */
    public function test_al_orientador_no_se_le_pregunta_por_alto_costo(): void
    {
        $this->admin->update(['roles' => ['PERSONAL', 'ORIENTADOR']]);
        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar())
            ->assertDontSee('alto costo')
            ->assertDontSee('oncológico');
    }

    public function test_la_orden_medica_queda_como_soporte_del_paciente(): void
    {
        Storage::fake('local');
        $this->admin->update(['roles' => ['PERSONAL', 'ORIENTADOR']]);
        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar(), [
            'orden_medica' => UploadedFile::fake()->image('orden.jpg'),
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $soporte = Paciente::first()->soportes()->sole();

        // `null` quiere decir «no se preguntó», que es la verdad: la marca la
        // pone farmacia sobre el ticket, no el orientador sobre el soporte.
        $this->assertNull($soporte->alto_costo_oncologico);
        $this->assertSame($this->admin->id, $soporte->cargado_por);
        Storage::disk('local')->assertExists($soporte->orden_medica);
    }

    /* ------------------------------------------------------------------ *
     *  El formulario solo aparece después de una consulta exitosa
     * ------------------------------------------------------------------ */

    public function test_antes_de_consultar_solo_se_ve_la_seccion_de_busqueda(): void
    {
        Livewire::test(CreatePaciente::class)
            ->assertSuccessful()
            // La búsqueda sí está.
            ->assertFormFieldIsVisible('tipo_documento')
            ->assertFormFieldIsVisible('numero_documento')
            // Los datos de Savia no.
            ->assertFormFieldIsHidden('primer_nombre')
            ->assertFormFieldIsHidden('primer_apellido')
            ->assertFormFieldIsHidden('estado_afiliacion')
            ->assertFormFieldIsHidden('regimen')
            ->assertFormFieldIsHidden('ips_primaria')
            // Ni los botones de guardar; «Cancelar» sí se queda.
            ->assertDontSee('Crear y crear otro')
            ->assertSee('Cancelar');
    }

    public function test_tras_una_consulta_exitosa_aparecen_las_secciones_y_los_botones(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar()
            ->assertFormFieldIsVisible('primer_nombre')
            ->assertFormFieldIsVisible('primer_apellido')
            ->assertFormFieldIsVisible('estado_afiliacion')
            ->assertFormFieldIsVisible('regimen')
            ->assertFormFieldIsVisible('ips_primaria')
            ->assertSee('Crear y crear otro');
    }

    /**
     * La consulta anterior no puede quedar en pantalla junto a un documento
     * que Savia no encontró.
     */
    public function test_una_consulta_fallida_no_deja_los_datos_de_la_anterior(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => fn ($peticion) => $peticion['numeroDocumento'] === '1017234567'
                ? Http::response($this->respuestaSavia())
                : Http::response([
                    'registros' => 0,
                    'codigo' => -1000,
                    'mensaje' => 'Afiliado no encontrado',
                    'afiliados' => [],
                ], 200),
        ]);

        $pagina = $this->consultar('1017234567')
            ->assertFormSet(['primer_nombre' => 'JUAN'])
            ->assertFormFieldIsVisible('primer_nombre');

        // Ahora uno que Savia no tiene.
        $pagina->set('data.numero_documento', '111111111');

        $this->pulsarConsultar($pagina)
            ->assertSet('mountedActions', ['afiliadoNoEncontrado'])
            ->assertFormSet([
                'primer_nombre' => null,
                'primer_apellido' => null,
                'estado_afiliacion' => null,
                'regimen' => null,
                'documento_consultado' => null,
            ])
            ->assertFormFieldIsHidden('primer_nombre')
            ->assertDontSee('Crear y crear otro');
    }

    public function test_al_cambiar_el_documento_se_limpian_los_datos(): void
    {
        $this->fakeConsultaExitosa();

        $pagina = $this->consultar('1017234567')
            ->assertFormSet(['primer_nombre' => 'JUAN']);

        // Escribir otro número obliga a consultar de nuevo.
        $pagina->set('data.numero_documento', '999888777')
            ->assertFormSet([
                'primer_nombre' => null,
                'estado_afiliacion' => null,
                'documento_consultado' => null,
            ])
            ->assertFormFieldIsHidden('primer_nombre')
            ->assertDontSee('Crear y crear otro');
    }

    public function test_al_cambiar_el_tipo_de_documento_tambien_se_limpian_los_datos(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar('1017234567')
            ->assertFormSet(['primer_nombre' => 'JUAN'])
            ->set('data.tipo_documento', 'TI')
            ->assertFormSet(['primer_nombre' => null, 'documento_consultado' => null])
            ->assertFormFieldIsHidden('primer_nombre');
    }

    /**
     * Ocultar los botones no basta: el servidor vuelve a comprobarlo, porque
     * la petición se puede armar a mano.
     */
    public function test_no_crea_el_paciente_sin_una_consulta_vigente(): void
    {
        Http::fake();

        Livewire::test(CreatePaciente::class)
            ->fillForm([
                'tipo_documento' => 'CC',
                'numero_documento' => '1017234567',
                'primer_nombre' => 'JUAN',
                'primer_apellido' => 'PEREZ',
            ])
            ->call('create')
            ->assertNotified('Primero consulta el documento en Savia');

        $this->assertDatabaseCount('pacientes', 0);
        Http::assertNothingSent();
    }

    public function test_no_crea_el_paciente_si_el_documento_cambio_despues_de_consultar(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar('1017234567')
            // Se cambia el número después de una consulta exitosa.
            ->fillForm(['numero_documento' => '999888777'])
            ->call('create')
            ->assertNotified('Primero consulta el documento en Savia');

        $this->assertDatabaseCount('pacientes', 0);
    }

    /**
     * Ocultar las secciones es solo del formulario de creación: al editar un
     * paciente ya registrado se sigue viendo todo, sin consultar nada.
     */
    public function test_al_editar_las_secciones_se_ven_sin_consultar(): void
    {
        $paciente = Paciente::factory()->conProgramas()->create();

        Livewire::test(EditPaciente::class, ['record' => $paciente->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldIsVisible('primer_nombre')
            ->assertFormFieldIsVisible('primer_apellido')
            ->assertFormFieldIsVisible('estado_afiliacion')
            ->assertFormFieldIsVisible('regimen')
            ->assertFormSet(['primer_nombre' => $paciente->primer_nombre]);
    }

    public function test_al_editar_se_siguen_guardando_los_cambios(): void
    {
        $paciente = Paciente::factory()->create();

        Livewire::test(EditPaciente::class, ['record' => $paciente->getRouteKey()])
            ->fillForm(['primer_nombre' => 'CAMBIADO', 'contacto_confirmado' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('CAMBIADO', $paciente->refresh()->primer_nombre);
    }

    public function test_ver_paciente_sigue_mostrando_la_ficha_completa(): void
    {
        $paciente = Paciente::factory()->conProgramas()->create([
            'primer_nombre' => 'MARIA',
            'primer_apellido' => 'RESTREPO',
        ]);

        Livewire::test(ViewPaciente::class, ['record' => $paciente->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('MARIA')
            ->assertSee('RESTREPO')
            ->assertSee('Programas especiales y RIAS')
            ->assertSee('RIAS VISUAL');
    }

    /* ------------------------------------------------------------------ *
     *  Caso 1: Savia no lo encuentra
     * ------------------------------------------------------------------ */

    public function test_caso_1_si_el_paciente_ya_esta_en_sispam_el_modal_lo_dice(): void
    {
        $existente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'estado_afiliacion' => 'Activo',
            'regimen' => 'SUBSIDIADO',
        ]);

        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
            ], 200),
        ]);

        $this->consultar('1017234567')
            ->assertSet('mountedActions', ['afiliadoNoEncontrado'])
            ->assertSee('está registrado en SISPAM')
            ->assertSee('No se modificó nada del paciente');

        // El registro no se toca.
        $this->assertSame('Activo', $existente->refresh()->estado_afiliacion);
        $this->assertSame('SUBSIDIADO', $existente->regimen);
        $this->assertSame(1, Paciente::count());
    }

    /* ------------------------------------------------------------------ *
     *  El contacto es de SISPAM y Savia no lo sobrescribe
     * ------------------------------------------------------------------ */

    public function test_una_consulta_nueva_no_sobrescribe_el_contacto_confirmado(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'telefono_movil' => '3109999999',
            'direccion' => 'CL 10 # 20-30 APTO 501',
            'indicaciones_entrega' => 'Portería, torre 2',
        ]);

        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar())
            ->call('create')
            ->assertHasNoFormErrors();

        $paciente->refresh();

        // Savia reporta 3001234567 y KR 40 70A 23: no deben ganar.
        $this->assertSame('3109999999', $paciente->telefono_movil);
        $this->assertSame('CL 10 # 20-30 APTO 501', $paciente->direccion);
        $this->assertSame('Portería, torre 2', $paciente->indicaciones_entrega);

        // Los datos de afiliación sí se actualizan.
        $this->assertSame('JUAN', $paciente->primer_nombre);
    }

    public function test_actualizar_desde_savia_no_toca_el_contacto_confirmado(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'telefono_movil' => '3109999999',
            'direccion' => 'CL 10 # 20-30 APTO 501',
            'barrio' => 'LAURELES',
            'ciudad_residencia' => 'ENVIGADO',
            'regimen' => 'CONTRIBUTIVO',
        ]);

        $confirmadoEn = $paciente->contacto_confirmado_at;

        $this->fakeConsultaExitosa();

        Livewire::test(ListPacientes::class)
            ->callTableAction('actualizarDesdeSavia', $paciente);

        $paciente->refresh();

        $this->assertSame('3109999999', $paciente->telefono_movil);
        $this->assertSame('CL 10 # 20-30 APTO 501', $paciente->direccion);
        $this->assertSame('LAURELES', $paciente->barrio);
        $this->assertSame('ENVIGADO', $paciente->ciudad_residencia);
        $this->assertEquals($confirmadoEn, $paciente->contacto_confirmado_at);

        // Lo de Savia sí entra.
        $this->assertSame('SUBSIDIADO', $paciente->regimen);
        $this->assertSame('JUAN', $paciente->primer_nombre);
    }

    /* ------------------------------------------------------------------ *
     *  Confirmación de contacto
     * ------------------------------------------------------------------ */

    public function test_exige_telefono_direccion_ciudad_y_la_casilla_de_confirmacion(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar()
            ->fillForm([
                'telefono_movil' => '',
                'direccion' => '',
                'ciudad_residencia' => '',
                'contacto_confirmado' => false,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'telefono_movil' => 'required',
                'direccion' => 'required',
                'ciudad_residencia' => 'required',
                'contacto_confirmado' => 'accepted',
            ]);

        $this->assertSame(0, Paciente::count());
    }

    public function test_guarda_quien_confirmo_el_contacto_y_cuando(): void
    {
        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar(), [
            'telefono_movil' => '3151112233',
            'direccion' => 'CR 80 # 30-15',
            'indicaciones_entrega' => 'Casa esquinera, reja verde',
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $paciente = Paciente::where('numero_documento', '1017234567')->firstOrFail();

        $this->assertSame('3151112233', $paciente->telefono_movil);
        $this->assertSame('CR 80 # 30-15', $paciente->direccion);
        $this->assertSame('Casa esquinera, reja verde', $paciente->indicaciones_entrega);
        $this->assertTrue($paciente->tieneContactoConfirmado());
        $this->assertTrue($paciente->contacto_confirmado_at->isToday());
        $this->assertSame($this->admin->id, $paciente->contacto_confirmado_por);
    }

    public function test_la_ficha_muestra_quien_confirmo_el_contacto(): void
    {
        $paciente = Paciente::factory()->create([
            'contacto_confirmado_por' => $this->admin->id,
            'telefono_movil' => '3151112233',
            'indicaciones_entrega' => 'Casa esquinera, reja verde',
        ]);

        Livewire::test(ViewPaciente::class, ['record' => $paciente->getRouteKey()])
            ->assertSee('Contacto confirmado con el paciente')
            ->assertSee('3151112233')
            ->assertSee('Casa esquinera, reja verde')
            ->assertSee('Confirmado por')
            ->assertSee($this->admin->nombre_completo);
    }

    public function test_la_ficha_avisa_cuando_nadie_ha_confirmado_el_contacto(): void
    {
        $paciente = Paciente::factory()->sinContactoConfirmado()->create();

        Livewire::test(ViewPaciente::class, ['record' => $paciente->getRouteKey()])
            ->assertSee('Sin confirmar');
    }

    /* ------------------------------------------------------------------ *
     *  Rastro de los cambios de afiliación
     * ------------------------------------------------------------------ */

    public function test_registra_en_el_log_los_cambios_de_estado_y_regimen(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'estado_afiliacion' => 'Retirado',
            'regimen' => 'CONTRIBUTIVO',
        ]);

        $registros = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(
            function (string $mensaje, array $contexto = []) use (&$registros): void {
                $registros[] = ['mensaje' => $mensaje, 'contexto' => $contexto];
            },
        );

        $this->fakeConsultaExitosa();

        Livewire::test(ListPacientes::class)
            ->callTableAction('actualizarDesdeSavia', $paciente);

        $mensajes = array_column($registros, 'mensaje');
        $this->assertContains('Cambio de estado de afiliación del paciente', $mensajes);
        $this->assertContains('Cambio de régimen del paciente', $mensajes);

        $regimen = collect($registros)->firstWhere('mensaje', 'Cambio de régimen del paciente')['contexto'];

        $this->assertSame($this->admin->id, $regimen['usuario']);
        $this->assertSame('CC', $regimen['tipoDocumento']);
        $this->assertSame('1017234567', $regimen['numeroDocumento']);
        $this->assertSame('CONTRIBUTIVO', $regimen['anterior']);
        $this->assertSame('SUBSIDIADO', $regimen['nuevo']);

        // Nunca la respuesta completa ni datos del paciente.
        $serializado = json_encode($registros, JSON_UNESCAPED_UNICODE);
        foreach (['JUAN', 'PEREZ', 'METROSALUD', 'juan@ejemplo.com'] as $dato) {
            $this->assertStringNotContainsString($dato, $serializado);
        }
    }

    public function test_al_editar_cambiar_el_documento_no_borra_los_datos(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'MARIA',
        ]);

        // En «editar» los datos son del paciente guardado, no de una consulta:
        // tocar el documento no puede vaciar el formulario.
        Livewire::test(EditPaciente::class, ['record' => $paciente->getRouteKey()])
            ->set('data.numero_documento', '1017234568')
            ->assertFormSet([
                'primer_nombre' => 'MARIA',
                'estado_afiliacion' => 'Activo',
            ])
            ->assertFormFieldIsVisible('primer_nombre');
    }

    /* ------------------------------------------------------------------ *
     *  Consulta en Savia
     * ------------------------------------------------------------------ */
    public function test_el_boton_consultar_en_savia_llena_el_formulario(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar()
            ->assertNotified('Afiliado encontrado en Savia')
            ->assertFormSet([
                'tipo_documento' => 'CC',
                'numero_documento' => '1017234567',
                'primer_nombre' => 'JUAN',
                'segundo_nombre' => 'CARLOS',
                'primer_apellido' => 'PEREZ',
                'segundo_apellido' => 'GOMEZ',
                'fecha_nacimiento' => '1990-05-14',
                'estado_afiliacion' => 'Activo',
                'regimen' => 'SUBSIDIADO',
                'municipio_afiliacion' => 'MEDELLIN',
                'email' => 'juan@ejemplo.com',
                'ips_primaria' => 'IPS MENTE PLENA',
            ]);
    }

    public function test_guarda_el_paciente_con_los_datos_que_trajo_savia(): void
    {
        $this->fakeConsultaExitosa([
            'programas' => [
                ['tipo' => 'RIAS', 'descripcion' => 'RIAS VISUAL'],
            ],
        ]);

        $this->confirmarContacto($this->consultar())
            ->call('create')
            ->assertHasNoFormErrors();

        $paciente = Paciente::where('numero_documento', '1017234567')->firstOrFail();

        $this->assertSame('CC', $paciente->tipo_documento);
        $this->assertSame('JUAN CARLOS PEREZ GOMEZ', $paciente->nombre_completo);
        $this->assertSame('1990-05-14', $paciente->fecha_nacimiento->toDateString());
        $this->assertSame('Activo', $paciente->estado_afiliacion);
        $this->assertSame('0', $paciente->codigo_respuesta_savia);
        $this->assertNotNull($paciente->consultado_en_savia_at);

        // El campo "programas" del servicio queda guardado y normalizado.
        $this->assertSame([['tipo' => 'RIAS', 'descripcion' => 'RIAS VISUAL']], $paciente->programas);

        // Lo que no tiene columna propia no se pierde.
        $this->assertSame('URBANA', $paciente->datos_adicionales['Zona de afiliación']);
    }

    public function test_avisa_cuando_el_afiliado_no_aparece_en_savia(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
            ], 404),
        ]);

        $this->consultar('111111111')
            // Se avisa con un modal centrado, no con la notificación de la esquina.
            ->assertDispatched('open-modal')
            ->assertSet('mountedActions', ['afiliadoNoEncontrado'])
            ->assertSee('El afiliado no aparece en Savia')
            ->assertSee('CC 111111111')
            ->assertSee('Aceptar')
            // El formulario queda limpio.
            ->assertFormSet(['primer_nombre' => null])
            ->assertFormFieldIsHidden('primer_nombre');
    }

    public function test_el_modal_de_no_encontrado_no_muestra_codigos_tecnicos(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
            ], 200),
        ]);

        // Los códigos del servicio quedan en el log de auditoría, no en pantalla.
        $this->consultar('111111111')
            ->assertSee('El afiliado no aparece en Savia')
            ->assertDontSee('-1000')
            ->assertDontSee('HTTP 200')
            ->assertDontSee('Código interno');
    }

    public function test_avisa_cuando_el_afiliado_no_esta_activo(): void
    {
        $this->fakeConsultaExitosa([
            'estadoAfiliacion' => 'Retirado',
            'fechaRetiro' => '2025-12-31',
        ]);

        $this->consultar()
            // Mismo modal centrado que el aviso de «no encontrado».
            ->assertDispatched('open-modal')
            ->assertSet('mountedActions', ['afiliadoInactivo'])
            ->assertSee('El afiliado no está activo en Savia')
            ->assertSee('Retirado')
            ->assertSee('Aceptar')
            // Los datos se llenan igual, con el estado que reporta Savia.
            ->assertFormSet([
                'primer_nombre' => 'JUAN',
                'estado_afiliacion' => 'Retirado',
                'fecha_retiro' => '2025-12-31',
            ])
            ->assertFormFieldIsVisible('primer_nombre');
    }

    public function test_el_modal_de_inactivo_incluye_la_causa_del_estado(): void
    {
        $this->fakeConsultaExitosa([
            'estadoAfiliacion' => 'Retirado',
            'causaEstado' => 'TRASLADO A OTRA EPS',
        ]);

        $this->consultar()
            ->assertSee('El afiliado no está activo en Savia')
            ->assertSee('TRASLADO A OTRA EPS')
            ->assertSee('Verifícalo antes de dispensar')
            // Sin códigos del servicio en pantalla.
            ->assertDontSee('Código interno')
            ->assertDontSee('HTTP 200');
    }

    public function test_avisa_cuando_el_servicio_falla_sin_romper_la_pagina(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['error' => 'credenciales'], 401),
        ]);

        $this->consultar()
            ->assertNotified('No se pudo consultar en Savia')
            ->assertFormSet(['primer_nombre' => null])
            ->assertSuccessful();
    }

    public function test_avisa_si_falta_el_documento_antes_de_consultar(): void
    {
        Http::fake();

        Livewire::test(CreatePaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => ''])
            ->callFormComponentAction('consultarEnSaviaAction', 'consultarEnSavia')
            ->assertNotified('Faltan datos para consultar');

        Http::assertNothingSent();
    }

    public function test_caso_3_si_el_documento_ya_existe_actualiza_en_vez_de_duplicar(): void
    {
        $existente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'DESACTUALIZADO',
            'regimen' => 'CONTRIBUTIVO',
        ]);

        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar())
            ->call('create')
            // Sin el error de «ya existe».
            ->assertHasNoFormErrors();

        $this->assertSame(1, Paciente::count(), 'No debía crearse un segundo paciente.');

        $existente->refresh();
        $this->assertSame('JUAN', $existente->primer_nombre);
        $this->assertSame('SUBSIDIADO', $existente->regimen);
    }

    public function test_caso_3_el_boton_dice_actualizar_y_muestra_que_cambio(): void
    {
        Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'regimen' => 'CONTRIBUTIVO',
            'estado_afiliacion' => 'Retirado',
        ]);

        $this->fakeConsultaExitosa();

        $this->consultar()
            ->assertSee('Actualizar paciente')
            ->assertDontSee('Crear y crear otro')
            ->assertSee('Cambios frente a lo registrado en SISPAM')
            ->assertSee('CONTRIBUTIVO → SUBSIDIADO')
            ->assertSee('Retirado → Activo');
    }

    public function test_caso_3_precarga_el_contacto_de_sispam_y_no_el_de_savia(): void
    {
        Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'telefono_movil' => '3109999999',
            'direccion' => 'CL 10 # 20-30 APTO 501',
            'barrio' => 'LAURELES',
            'ciudad_residencia' => 'ENVIGADO',
            'indicaciones_entrega' => 'Portería, torre 2',
        ]);

        $this->fakeConsultaExitosa();

        $this->consultar()
            // Lo confirmado en SISPAM manda.
            ->assertFormSet([
                'telefono_movil' => '3109999999',
                'direccion' => 'CL 10 # 20-30 APTO 501',
                'barrio' => 'LAURELES',
                'ciudad_residencia' => 'ENVIGADO',
                'indicaciones_entrega' => 'Portería, torre 2',
            ])
            // Lo de Savia se muestra como referencia debajo de cada campo.
            ->assertSee('Savia reporta: 3001234567')
            ->assertSee('Savia reporta: KR 40 70A 23');
    }

    public function test_caso_2_precarga_el_contacto_que_reporta_savia(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar()
            ->assertSee('Crear paciente')
            ->assertFormSet([
                'telefono_movil' => '3001234567',
                'telefono' => '6043005012',
                'direccion' => 'KR 40 70A 23',
                'barrio' => 'MANRIQUE ORIENTAL',
                'ciudad_residencia' => 'MEDELLÍN',
            ])
            // Sin nada registrado todavía, no hay lista de cambios.
            ->assertDontSee('Cambios frente a lo registrado en SISPAM');
    }

    public function test_caso_4_un_afiliado_inactivo_se_puede_guardar(): void
    {
        $this->fakeConsultaExitosa(['estadoAfiliacion' => 'Retirado']);

        $this->confirmarContacto($this->consultar())
            ->call('create')
            ->assertHasNoFormErrors();

        $paciente = Paciente::where('numero_documento', '1017234567')->firstOrFail();
        $this->assertSame('Retirado', $paciente->estado_afiliacion);
    }

    public function test_el_mismo_numero_con_otro_tipo_de_documento_si_se_permite(): void
    {
        Paciente::factory()->create(['tipo_documento' => 'TI', 'numero_documento' => '1017234567']);

        $this->fakeConsultaExitosa();

        $this->confirmarContacto($this->consultar())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Paciente::where('numero_documento', '1017234567')->count());
    }

    public function test_rechaza_un_numero_de_documento_con_caracteres_no_permitidos(): void
    {
        Http::fake();

        Livewire::test(CreatePaciente::class)
            ->fillForm([
                'tipo_documento' => 'CC',
                'numero_documento' => '1017 234/567',
                'primer_nombre' => 'JUAN',
                'primer_apellido' => 'PEREZ',
            ])
            ->call('create')
            ->assertHasFormErrors(['numero_documento' => 'regex']);
    }

    public function test_el_listado_muestra_los_pacientes_con_el_estado_de_afiliacion(): void
    {
        $activos = Paciente::factory()->count(2)->create();
        $retirado = Paciente::factory()->retirado()->create();

        Livewire::test(ListPacientes::class)
            ->assertCanSeeTableRecords($activos->push($retirado))
            ->assertTableColumnStateSet('estado_afiliacion', 'Activo', $activos->first())
            ->assertTableColumnStateSet('estado_afiliacion', 'Retirado', $retirado);
    }

    public function test_el_listado_filtra_por_estado_de_afiliacion(): void
    {
        $activo = Paciente::factory()->create();
        $retirado = Paciente::factory()->retirado()->create();

        Livewire::test(ListPacientes::class)
            ->filterTable('estado_afiliacion', 'Retirado')
            ->assertCanSeeTableRecords([$retirado])
            ->assertCanNotSeeTableRecords([$activo]);
    }

    public function test_la_accion_actualizar_desde_savia_refresca_el_paciente(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'NOMBRE',
            'primer_apellido' => 'DESACTUALIZADO',
            'estado_afiliacion' => 'Activo',
            'consultado_en_savia_at' => now()->subMonth(),
        ]);

        $this->fakeConsultaExitosa(['estadoAfiliacion' => 'Suspendido']);

        Livewire::test(ListPacientes::class)
            ->callTableAction('actualizarDesdeSavia', $paciente)
            ->assertNotified('El afiliado no está activo en Savia');

        $paciente->refresh();

        $this->assertSame('JUAN', $paciente->primer_nombre);
        $this->assertSame('PEREZ', $paciente->primer_apellido);
        $this->assertSame('Suspendido', $paciente->estado_afiliacion);
        $this->assertTrue($paciente->consultado_en_savia_at->isToday());
    }

    public function test_actualizar_desde_savia_no_borra_nada_si_el_servicio_falla(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'JUAN',
        ]);

        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response(['codigo' => -1, 'mensaje' => 'Falla interna'], 500),
        ]);

        Livewire::test(ListPacientes::class)
            ->callTableAction('actualizarDesdeSavia', $paciente)
            ->assertNotified('No se pudo consultar en Savia');

        $this->assertSame('JUAN', $paciente->refresh()->primer_nombre);
    }

    public function test_la_busqueda_encuentra_por_nombre_y_por_documento(): void
    {
        $buscado = Paciente::factory()->create([
            'primer_nombre' => 'MARIA',
            'primer_apellido' => 'RESTREPO',
            'numero_documento' => '43555111',
        ]);
        $otro = Paciente::factory()->create([
            'primer_nombre' => 'PEDRO',
            'primer_apellido' => 'GOMEZ',
            'numero_documento' => '71222333',
        ]);

        Livewire::test(ListPacientes::class)
            ->searchTable('RESTREPO')
            ->assertCanSeeTableRecords([$buscado])
            ->assertCanNotSeeTableRecords([$otro]);

        Livewire::test(ListPacientes::class)
            ->searchTable('71222333')
            ->assertCanSeeTableRecords([$otro])
            ->assertCanNotSeeTableRecords([$buscado]);
    }
}
