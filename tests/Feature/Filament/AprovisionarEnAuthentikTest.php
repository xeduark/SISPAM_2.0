<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Authentik\AprovisionarEnAuthentik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\FingeAuthentik;
use Tests\TestCase;

/**
 * Crear a alguien en Usuarios le crea la cuenta en Authentik, con la
 * contraseña que escribe quien lo crea, para que pueda entrar.
 */
class AprovisionarEnAuthentikTest extends TestCase
{
    use FingeAuthentik, RefreshDatabase;

    private const CLAVE = 'Clave-Segura-2026';

    private User $admin;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create();
        $this->admin = User::factory()->administrador()->create(['documento' => 'AdminSispam']);
        $this->actingAs($this->admin);

        foreach (['CONSULTA', 'DISPENSADOR', 'ORIENTADOR'] as $nombre) {
            Rol::create(['nombre' => $nombre, 'permisos' => []]);
        }

        $this->configurarAuthentik();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crear(array $datos = []): Testable
    {
        return Livewire::test(CreateUser::class)
            ->fillForm([
                'nombre' => 'Ana',
                'apellido' => 'Pérez',
                'documento' => '52123456',
                'email' => 'ana@example.com',
                'clave_authentik' => self::CLAVE,
                'sede_id' => $this->sede->id,
                'activo' => true,
                'roles' => ['ORIENTADOR'],
                ...$datos,
            ])
            ->call('create');
    }

    /**
     * Los avisos enviados, tal como van al navegador. Se leen antes de
     * `assertNotified()`, que los consume de la sesión.
     */
    private function avisos(): string
    {
        return (string) json_encode(session('filament.notifications'), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Que una contraseña no haya quedado en ninguna tabla de SISPAM.
     */
    private function assertNoQuedoEnLaBase(string $clave): void
    {
        foreach (['users', 'auditorias', 'sessions', 'cache', 'jobs'] as $tabla) {
            $this->assertStringNotContainsString($clave, DB::table($tabla)->get()->toJson(), "La contraseña quedó en {$tabla}");
        }
    }

    public function test_crear_un_usuario_le_crea_la_cuenta_en_authentik_con_la_contrasena_escrita(): void
    {
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');

        $creacion = $this->crear()->assertHasNoFormErrors();
        $avisos = $this->avisos();
        $creacion->assertNotified('Ana Pérez ya puede entrar');

        $cuenta = $this->cuentaAuthentik('52123456');
        $this->assertNotNull($cuenta);
        $this->assertSame('Ana Pérez', $cuenta['name']);
        $this->assertSame('ana@example.com', $cuenta['email']);
        $this->assertSame('internal', $cuenta['type']);
        $this->assertSame([$orientador], $cuenta['groups']);
        $this->assertSame(AprovisionarEnAuthentik::ORIGEN, $cuenta['attributes']['creado_desde']);
        $this->assertSame(self::CLAVE, $this->clavesAuthentik[$cuenta['pk']]);

        // La contraseña va para Authentik y nada más: ni en el aviso, ni en la base.
        $this->assertStringNotContainsString(self::CLAVE, $avisos);
        $this->assertNoQuedoEnLaBase(self::CLAVE);

        $usuario = User::where('documento', '52123456')->firstOrFail();
        $this->assertSame(['ORIENTADOR'], $usuario->roles);
        $this->assertNull($usuario->password);
        $this->assertDatabaseHas('auditorias', [
            'accion' => Auditoria::ACCION_CUENTA_AUTHENTIK,
            'entidad_id' => $usuario->id,
            'usuario_documento' => 'AdminSispam',
        ]);
    }

    public function test_la_contrasena_es_obligatoria_y_de_al_menos_8_caracteres(): void
    {
        $this->crear(['clave_authentik' => ''])->assertHasFormErrors(['clave_authentik' => 'required']);
        $this->crear(['clave_authentik' => 'corta'])->assertHasFormErrors(['clave_authentik' => 'min']);

        $this->assertDatabaseMissing('users', ['documento' => '52123456']);
        $this->assertSame([], $this->cuentasAuthentik);
    }

    public function test_si_ya_tenia_cuenta_que_no_creo_sispam_conserva_su_contrasena_y_sus_otros_grupos(): void
    {
        $otraAplicacion = $this->grupoEnAuthentik('documenta-usuarios');
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');
        $pk = $this->cuentaEnAuthentik('52123456', [$otraAplicacion]);

        $creacion = $this->crear()->assertHasNoFormErrors();
        $avisos = $this->avisos();
        $creacion->assertNotified('Usuario guardado');

        $this->assertCount(1, $this->cuentasAuthentik);
        $this->assertArrayNotHasKey($pk, $this->clavesAuthentik);
        $this->assertStringContainsString('la que escribiste no se usó', $avisos);
        $this->assertEqualsCanonicalizing([$otraAplicacion, $orientador], $this->cuentasAuthentik[$pk]['groups']);
    }

    public function test_si_ya_tenia_cuenta_creada_por_sispam_le_pone_la_contrasena_escrita(): void
    {
        $this->grupoEnAuthentik('ORIENTADOR');
        $pk = $this->cuentaEnAuthentik('52123456', atributos: ['creado_desde' => AprovisionarEnAuthentik::ORIGEN]);

        $creacion = $this->crear()->assertHasNoFormErrors();
        $avisos = $this->avisos();
        $creacion->assertNotified('Usuario guardado');

        $this->assertCount(1, $this->cuentasAuthentik);
        $this->assertSame(self::CLAVE, $this->clavesAuthentik[$pk]);
        $this->assertStringContainsString('Ahora entra con la contraseña que escribiste', $avisos);
    }

    public function test_al_crearlo_no_le_quita_los_roles_que_ya_tenia_en_authentik(): void
    {
        $consulta = $this->grupoEnAuthentik('CONSULTA');
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');
        $pk = $this->cuentaEnAuthentik('52123456', [$consulta]);

        $this->crear(['roles' => ['ORIENTADOR']])->assertHasNoFormErrors();

        // Se suman, y la lista de Usuarios ya los muestra sin esperar a que entre.
        $this->assertEqualsCanonicalizing([$consulta, $orientador], $this->cuentasAuthentik[$pk]['groups']);
        $this->assertEqualsCanonicalizing(['CONSULTA', 'ORIENTADOR'], User::where('documento', '52123456')->firstOrFail()->roles);
    }

    public function test_quien_no_es_administrador_lo_crea_con_contrasena_pero_sin_tocar_sus_roles(): void
    {
        Rol::where('nombre', 'ORIENTADOR')->update(['permisos' => ['usuarios' => ['ver', 'crear', 'editar']]]);
        $this->actingAs(User::factory()->create(['roles' => ['ORIENTADOR']]));
        $consulta = $this->grupoEnAuthentik('CONSULTA');
        $pk = $this->cuentaEnAuthentik('52123456', [$consulta]);

        Livewire::test(CreateUser::class)
            ->assertFormFieldIsVisible('clave_authentik')
            ->assertFormFieldIsHidden('roles');

        $this->crear(['roles' => null])->assertHasNoFormErrors();

        $this->assertSame([$consulta], $this->cuentasAuthentik[$pk]['groups']);
        $this->assertSame(['CONSULTA'], User::where('documento', '52123456')->firstOrFail()->roles);
    }

    public function test_si_authentik_falla_no_se_guarda_nada(): void
    {
        $this->fallosAuthentik['POST core/users/'] = 500;

        $this->crear()->assertNotified('No se creó el usuario');

        $this->assertDatabaseMissing('users', ['documento' => '52123456']);
        $this->assertNull($this->cuentaAuthentik('52123456'));
    }

    public function test_un_token_rechazado_se_explica_sin_mostrar_el_token(): void
    {
        $this->fallosAuthentik['GET core/groups/'] = 401;

        $creacion = $this->crear();
        $avisos = $this->avisos();
        $creacion->assertNotified('No se creó el usuario');

        $this->assertStringContainsString('AUTHENTIK_API_TOKEN', $avisos);
        $this->assertStringNotContainsString('token-de-prueba', $avisos);
    }

    public function test_crea_en_authentik_el_grupo_de_un_rol_que_todavia_no_existe(): void
    {
        $this->crear(['roles' => ['DISPENSADOR']])->assertHasNoFormErrors();

        $dispensador = $this->uuidDelGrupo('DISPENSADOR');
        $this->assertNotNull($dispensador);
        $this->assertFalse($this->gruposAuthentik[$dispensador]['is_superuser']);
        $this->assertSame([$dispensador], $this->cuentaAuthentik('52123456')['groups']);
    }

    public function test_nunca_asigna_un_grupo_de_superusuario_de_authentik(): void
    {
        $this->grupoEnAuthentik('CONSULTA', superusuario: true);

        $creacion = $this->crear(['roles' => ['CONSULTA']]);
        $avisos = $this->avisos();
        $creacion->assertNotified('No se creó el usuario');

        $this->assertStringContainsString('superusuario', $avisos);
        $this->assertNull($this->cuentaAuthentik('52123456'));
        $this->assertDatabaseMissing('users', ['documento' => '52123456']);
    }

    public function test_solo_ofrece_y_guarda_roles_que_existen_en_sispam(): void
    {
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');
        $this->grupoEnAuthentik('authentik Admins', superusuario: true);

        // Un valor que no está entre las opciones (petición alterada) se descarta.
        $this->crear(['roles' => ['ORIENTADOR', 'authentik Admins']])->assertHasNoFormErrors();

        $this->assertSame([$orientador], $this->cuentaAuthentik('52123456')['groups']);
        $this->assertSame(['ORIENTADOR'], User::where('documento', '52123456')->firstOrFail()->roles);
    }

    public function test_sin_token_lo_crea_solo_en_sispam_y_avisa(): void
    {
        config(['services.authentik.api_token' => null]);

        Livewire::test(CreateUser::class)->assertFormFieldIsHidden('clave_authentik');

        $this->crear()
            ->assertHasNoFormErrors()
            ->assertNotified('Usuario guardado solo en SISPAM');

        $this->assertDatabaseHas('users', ['documento' => '52123456']);
        Http::assertNothingSent();
    }

    public function test_si_authentik_no_acepta_la_contrasena_el_usuario_queda_y_se_avisa(): void
    {
        $this->fallosAuthentik['POST core/users/{pk}/set_password/'] = 403;

        $this->crear()
            ->assertHasNoFormErrors()
            ->assertNotified('Se creó su cuenta en Authentik, pero sin contraseña');

        $this->assertDatabaseHas('users', ['documento' => '52123456']);
        $this->assertNotNull($this->cuentaAuthentik('52123456'));
    }

    public function test_editar_los_roles_solo_cambia_los_grupos_de_sispam(): void
    {
        $otraAplicacion = $this->grupoEnAuthentik('documenta-usuarios');
        $consulta = $this->grupoEnAuthentik('CONSULTA');
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');
        $usuario = User::factory()->create(['documento' => '52123456', 'roles' => ['CONSULTA', 'documenta-usuarios']]);
        $pk = $this->cuentaEnAuthentik('52123456', [$otraAplicacion, $consulta]);

        Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->assertFormFieldIsHidden('clave_authentik')
            ->assertFormSet(['roles' => ['CONSULTA']])
            ->fillForm(['roles' => ['ORIENTADOR']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([$otraAplicacion, $orientador], $this->cuentasAuthentik[$pk]['groups']);
        $this->assertSame(['ORIENTADOR'], $usuario->refresh()->roles);
        $this->assertArrayNotHasKey($pk, $this->clavesAuthentik);
    }

    public function test_si_al_editar_se_le_crea_la_cuenta_queda_sin_contrasena_y_se_avisa(): void
    {
        $this->grupoEnAuthentik('ORIENTADOR');
        $usuario = User::factory()->create(['documento' => '52123456', 'roles' => []]);

        Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->fillForm(['roles' => ['ORIENTADOR']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Se creó su cuenta en Authentik, pero sin contraseña');

        $this->assertNotNull($cuenta = $this->cuentaAuthentik('52123456'));
        $this->assertArrayNotHasKey($cuenta['pk'], $this->clavesAuthentik);
    }

    public function test_desactivar_o_corregir_el_nombre_no_depende_de_authentik(): void
    {
        $usuario = User::factory()->create(['roles' => ['CONSULTA']]);

        Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->fillForm(['nombre' => 'Cambiado', 'activo' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        Http::assertNothingSent();
        $this->assertFalse($usuario->refresh()->activo);
        $this->assertSame('Cambiado', $usuario->nombre);
    }

    public function test_si_falla_al_editar_no_se_guarda_y_se_puede_reintentar(): void
    {
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');
        $usuario = User::factory()->create(['documento' => '52123456', 'roles' => []]);
        $pk = $this->cuentaEnAuthentik('52123456');
        $this->fallosAuthentik['PATCH core/users/{pk}/'] = 503;

        $edicion = Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->fillForm(['roles' => ['ORIENTADOR']])
            ->call('save')
            ->assertNotified('No se guardaron los cambios');

        $this->assertSame([], $usuario->refresh()->roles ?? []);

        // Authentik volvió: el mismo formulario se guarda y llega allá.
        $this->fallosAuthentik = [];
        $edicion->call('save')->assertHasNoFormErrors();

        $this->assertSame([$orientador], $this->cuentasAuthentik[$pk]['groups']);
        $this->assertSame(['ORIENTADOR'], $usuario->refresh()->roles);
    }

    public function test_cambiar_el_documento_renombra_la_cuenta_en_vez_de_crear_otra(): void
    {
        $usuario = User::factory()->create(['documento' => '52123456']);
        $pk = $this->cuentaEnAuthentik('52123456');

        Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->fillForm(['documento' => '52123457'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(1, $this->cuentasAuthentik);
        $this->assertSame('52123457', $this->cuentasAuthentik[$pk]['username']);
    }

    public function test_cambiar_solo_el_documento_no_toca_sus_grupos(): void
    {
        $consulta = $this->grupoEnAuthentik('CONSULTA');
        // En SISPAM todavía no se le ven roles: no ha vuelto a entrar desde que se los dieron allá.
        $usuario = User::factory()->create(['documento' => '52123456', 'roles' => []]);
        $pk = $this->cuentaEnAuthentik('52123456', [$consulta]);

        Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->fillForm(['documento' => '52123457'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('52123457', $this->cuentasAuthentik[$pk]['username']);
        $this->assertSame([$consulta], $this->cuentasAuthentik[$pk]['groups']);
    }

    public function test_los_roles_y_cambiar_la_contrasena_solo_los_maneja_un_administrador(): void
    {
        Rol::where('nombre', 'ORIENTADOR')->update(['permisos' => ['usuarios' => ['ver', 'crear', 'editar']]]);
        $gestor = User::factory()->create(['roles' => ['ORIENTADOR']]);
        $this->actingAs($gestor);

        Livewire::test(CreateUser::class)->assertFormFieldIsHidden('roles');

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('cambiarClave', $this->admin)
            // Una petición forzada tampoco: la acción oculta no se puede montar.
            ->call('mountTableAction', 'cambiarClave', (string) $this->admin->getKey())
            ->call('callMountedTableAction');

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->assertActionHidden('cambiarClave');

        $this->assertSame([], $this->clavesAuthentik);
    }

    public function test_cambiar_la_contrasena_de_una_cuenta_que_creo_sispam(): void
    {
        $usuario = User::factory()->create(['documento' => '52123456']);
        $pk = $this->cuentaEnAuthentik('52123456', atributos: ['creado_desde' => AprovisionarEnAuthentik::ORIGEN]);

        $lista = Livewire::test(ListUsers::class)->callTableAction('cambiarClave', $usuario, data: ['clave' => 'Otra-Clave-2026']);
        $avisos = $this->avisos();
        $lista->assertHasNoTableActionErrors()->assertNotified('Contraseña cambiada');

        $this->assertSame('Otra-Clave-2026', $this->clavesAuthentik[$pk]);
        $this->assertStringNotContainsString('Otra-Clave-2026', $avisos);
        $this->assertNoQuedoEnLaBase('Otra-Clave-2026');
    }

    public function test_cambiar_la_contrasena_tambien_desde_la_edicion(): void
    {
        $usuario = User::factory()->create(['documento' => '52123456']);
        $pk = $this->cuentaEnAuthentik('52123456', atributos: ['creado_desde' => AprovisionarEnAuthentik::ORIGEN]);

        Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
            ->callAction('cambiarClave', data: ['clave' => 'Otra-Clave-2026'])
            ->assertHasNoActionErrors()
            ->assertNotified('Contraseña cambiada');

        $this->assertSame('Otra-Clave-2026', $this->clavesAuthentik[$pk]);
    }

    public function test_la_contrasena_nueva_tambien_tiene_que_tener_al_menos_8_caracteres(): void
    {
        $usuario = User::factory()->create(['documento' => '52123456']);
        $pk = $this->cuentaEnAuthentik('52123456', atributos: ['creado_desde' => AprovisionarEnAuthentik::ORIGEN]);

        Livewire::test(ListUsers::class)
            ->callTableAction('cambiarClave', $usuario, data: ['clave' => 'corta'])
            ->assertHasTableActionErrors(['clave' => 'min']);

        $this->assertArrayNotHasKey($pk, $this->clavesAuthentik);
    }

    public function test_no_cambia_la_contrasena_de_una_cuenta_que_no_creo_sispam(): void
    {
        $usuario = User::factory()->create(['documento' => '52123456']);
        $pk = $this->cuentaEnAuthentik('52123456');

        Livewire::test(ListUsers::class)
            ->callTableAction('cambiarClave', $usuario, data: ['clave' => 'Otra-Clave-2026'])
            ->assertNotified('No se cambió la contraseña');

        $this->assertArrayNotHasKey($pk, $this->clavesAuthentik);
    }

    public function test_cambiar_la_contrasena_le_crea_la_cuenta_si_no_la_tiene(): void
    {
        $consulta = $this->grupoEnAuthentik('CONSULTA');
        $usuario = User::factory()->create(['documento' => 'ConsultaPRP', 'roles' => ['CONSULTA']]);

        Livewire::test(ListUsers::class)
            ->callTableAction('cambiarClave', $usuario, data: ['clave' => 'Clave-De-La-Sede'])
            ->assertNotified("{$usuario->nombre_completo} ya puede entrar");

        $cuenta = $this->cuentaAuthentik('ConsultaPRP');
        $this->assertSame([$consulta], $cuenta['groups']);
        $this->assertSame('Clave-De-La-Sede', $this->clavesAuthentik[$cuenta['pk']]);
    }
}
