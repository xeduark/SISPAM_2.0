<?php

namespace Tests\Feature\Auditoria;

use App\Filament\Resources\AuditoriaResource;
use App\Filament\Resources\AuditoriaResource\Pages\ListAuditorias;
use App\Filament\Resources\PacienteResource;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rastro de quién hizo qué, en base de datos y consultable desde el panel.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->administrador()->create();
        $this->actingAs($this->admin);
    }

    /** Las auditorías de una entidad, de la más vieja a la más nueva. */
    private function registrosDe(string $entidadTipo): Collection
    {
        return Auditoria::where('entidad_tipo', $entidadTipo)->orderBy('id')->get();
    }

    /* ------------------------------------------------------------------ *
     *  Sesiones
     * ------------------------------------------------------------------ */

    public function test_registra_el_ingreso_y_el_cierre_de_sesion(): void
    {
        event(new Login('web', $this->admin, false));
        event(new Logout('web', $this->admin));

        $acciones = Auditoria::whereIn('accion', [
            Auditoria::ACCION_INGRESO,
            Auditoria::ACCION_CERRO_SESION,
        ])->orderBy('id')->get();

        $this->assertCount(2, $acciones);
        $this->assertSame('Ingresó al sistema', $acciones[0]->descripcion);
        $this->assertSame($this->admin->id, $acciones[0]->usuario_id);
        $this->assertSame($this->admin->documento, $acciones[0]->usuario_documento);
        $this->assertSame('Cerró sesión', $acciones[1]->descripcion);
    }

    /* ------------------------------------------------------------------ *
     *  Cambios en los modelos
     * ------------------------------------------------------------------ */

    public function test_registra_crear_actualizar_y_eliminar_un_rol(): void
    {
        $rol = Rol::create(['nombre' => 'FARMACIA', 'permisos' => ['pacientes' => ['ver']]]);
        $rol->update(['permisos' => ['pacientes' => ['ver', 'crear']]]);
        $rol->delete();

        $registros = $this->registrosDe('rol');

        $this->assertCount(3, $registros);

        $this->assertSame(Auditoria::ACCION_CREO, $registros[0]->accion);
        $this->assertSame('Creó el rol «FARMACIA»', $registros[0]->descripcion);

        $this->assertSame(Auditoria::ACCION_ACTUALIZO, $registros[1]->accion);
        $this->assertSame('Actualizó el rol «FARMACIA»', $registros[1]->descripcion);
        $this->assertSame('{"pacientes":["ver"]}', $registros[1]->cambios['permisos'][0]);
        $this->assertSame('{"pacientes":["ver","crear"]}', $registros[1]->cambios['permisos'][1]);

        $this->assertSame(Auditoria::ACCION_ELIMINO, $registros[2]->accion);
        $this->assertSame($this->admin->id, $registros[2]->usuario_id);
    }

    public function test_guarda_el_antes_y_el_despues_de_lo_que_cambio(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
            'regimen' => 'SUBSIDIADO',
            'estado_afiliacion' => 'Activo',
        ]);

        Auditoria::query()->delete();

        $paciente->update(['regimen' => 'CONTRIBUTIVO', 'estado_afiliacion' => 'Retirado']);

        $registro = $this->registrosDe('paciente')->first();

        $this->assertSame(Auditoria::ACCION_ACTUALIZO, $registro->accion);
        $this->assertSame(['SUBSIDIADO', 'CONTRIBUTIVO'], $registro->cambios['regimen']);
        $this->assertSame(['Activo', 'Retirado'], $registro->cambios['estado_afiliacion']);
    }

    /* ------------------------------------------------------------------ *
     *  La lista blanca: lo que no se declara, no se registra
     * ------------------------------------------------------------------ */

    public function test_solo_registra_los_campos_declarados_en_la_lista_blanca(): void
    {
        $paciente = Paciente::factory()->create(['regimen' => 'SUBSIDIADO']);

        Auditoria::query()->delete();

        $paciente->update([
            'regimen' => 'CONTRIBUTIVO',   // declarado
            'primer_nombre' => 'CAMBIADO',  // NO declarado
            'grupo_poblacional' => 'OTRO',  // NO declarado
        ]);

        $cambios = $this->registrosDe('paciente')->first()->cambios;

        $this->assertArrayHasKey('regimen', $cambios);
        $this->assertArrayNotHasKey('primer_nombre', $cambios);
        $this->assertArrayNotHasKey('grupo_poblacional', $cambios);
    }

    public function test_no_registra_nada_si_solo_cambian_campos_fuera_de_la_lista(): void
    {
        $paciente = Paciente::factory()->create();

        Auditoria::query()->delete();

        $paciente->update(['primer_nombre' => 'CAMBIADO', 'nivel_sisben' => '3']);

        $this->assertSame(0, $this->registrosDe('paciente')->count());
    }

    /* ------------------------------------------------------------------ *
     *  Datos de salud: nunca entran
     * ------------------------------------------------------------------ */

    public function test_del_paciente_solo_queda_el_documento_y_nunca_su_nombre(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
            'primer_nombre' => 'JUAN',
            'primer_apellido' => 'RAMIREZ',
            'regimen' => 'SUBSIDIADO',
        ]);

        $paciente->update(['regimen' => 'CONTRIBUTIVO']);

        $todo = json_encode($this->registrosDe('paciente')->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('CC 1000873458', $todo);
        $this->assertStringNotContainsString('JUAN', $todo);
        $this->assertStringNotContainsString('RAMIREZ', $todo);
    }

    public function test_de_la_orden_medica_no_guarda_ni_la_ruta_ni_el_alto_costo(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
        ]);

        $paciente->soportes()->create([
            'orden_medica' => 'soportes/ordenes-medicas/secreto.pdf',
            'alto_costo_oncologico' => true,
            'cargado_por' => $this->admin->id,
        ]);

        $registro = $this->registrosDe('orden_medica')->first();

        $this->assertSame('Creó una orden médica del paciente CC 1000873458', $registro->descripcion);
        $this->assertNull($registro->cambios);

        $todo = json_encode($registro->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('secreto.pdf', $todo);
        $this->assertStringNotContainsString('alto_costo', $todo);
    }

    public function test_la_consulta_a_savia_queda_registrada_sin_datos_del_afiliado(): void
    {
        config(['savia.username' => '900000000', 'savia.password' => 'clave', 'savia.auditar' => false]);

        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A']),
            '*rest/afiliado/consultar-afiliado' => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'afiliados' => [[
                    'tipoDocumentoAfiliado' => 'CC',
                    'documentoAfiliado' => '1000873458',
                    'primerNombreAfiliado' => 'JUAN',
                    'primerApellidoAfiliado' => 'RAMIREZ',
                    'estadoAfiliacion' => 'Activo',
                    'regimen' => 'CONTRIBUTIVO',
                ]],
            ]),
        ]);

        PacienteResource::consultarEnSavia('CC', '1000873458');

        $registro = Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->firstOrFail();

        $this->assertSame('Consultó en Savia el documento CC 1000873458', $registro->descripcion);
        $this->assertSame($this->admin->id, $registro->usuario_id);

        $todo = json_encode($registro->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('JUAN', $todo);
        $this->assertStringNotContainsString('CONTRIBUTIVO', $todo);
    }

    /* ------------------------------------------------------------------ *
     *  La auditoría sobrevive al usuario
     * ------------------------------------------------------------------ */

    public function test_sigue_siendo_legible_si_se_elimina_el_usuario(): void
    {
        $otro = User::factory()->create(['nombre' => 'Ana', 'apellido' => 'Pérez', 'documento' => '52123456']);

        Auditoria::registrar(
            accion: Auditoria::ACCION_INGRESO,
            descripcion: 'Ingresó al sistema',
            usuario: $otro,
        );

        $otro->delete();

        $registro = Auditoria::where('usuario_documento', '52123456')->firstOrFail();

        $this->assertNull($registro->usuario_id);
        $this->assertSame('Ana Pérez', $registro->usuario_nombre);
        $this->assertSame('52123456', $registro->usuario_documento);
    }

    /* ------------------------------------------------------------------ *
     *  La pantalla
     * ------------------------------------------------------------------ */

    public function test_la_pantalla_lista_los_registros(): void
    {
        $registros = Auditoria::factory()->count(3)->create();

        Livewire::test(ListAuditorias::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($registros);
    }

    public function test_la_pantalla_filtra_por_accion(): void
    {
        $ingreso = Auditoria::factory()->accion(Auditoria::ACCION_INGRESO)->create();
        $eliminacion = Auditoria::factory()->accion(Auditoria::ACCION_ELIMINO)->create();

        Livewire::test(ListAuditorias::class)
            ->filterTable('accion', [Auditoria::ACCION_INGRESO])
            ->assertCanSeeTableRecords([$ingreso])
            ->assertCanNotSeeTableRecords([$eliminacion]);
    }

    public function test_solo_entra_quien_tiene_el_permiso(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/auditorias')->assertForbidden();

        Rol::create(['nombre' => 'AUDITOR', 'permisos' => ['auditoria' => ['ver']]]);
        $this->actingAs(User::factory()->create(['roles' => ['AUDITOR']]));
        $this->get('/admin/auditorias')->assertOk();
    }

    public function test_nadie_puede_crear_editar_ni_borrar_auditorias(): void
    {
        $registro = Auditoria::factory()->create();

        // Ni siquiera el administrador, que normalmente puede todo.
        $this->assertFalse(AuditoriaResource::canCreate());
        $this->assertFalse(AuditoriaResource::canEdit($registro));
        $this->assertFalse(AuditoriaResource::canDelete($registro));
        $this->assertFalse(AuditoriaResource::canDeleteAny());

        $this->get('/admin/auditorias/create')->assertNotFound();
        $this->get("/admin/auditorias/{$registro->id}/edit")->assertNotFound();
    }

    public function test_registra_los_cambios_de_sede_y_de_usuario(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'La 30']);
        Auditoria::query()->delete();

        $sede->update(['activa' => false]);
        $this->assertSame('Actualizó la sede «La 30»', $this->registrosDe('sede')->first()->descripcion);
        $this->assertSame(['Sí', 'No'], $this->registrosDe('sede')->first()->cambios['activa']);

        // Con el valor anterior cargado: así llega el modelo desde la base de datos.
        $usuario = User::factory()->create(['documento' => '52123456', 'es_administrador' => false]);
        Auditoria::query()->delete();

        $usuario->update(['es_administrador' => true]);
        $registro = $this->registrosDe('usuario')->first();

        $this->assertSame('Actualizó el usuario 52123456', $registro->descripcion);
        $this->assertSame(['No', 'Sí'], $registro->cambios['es_administrador']);
    }
}
