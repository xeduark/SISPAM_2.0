<?php

namespace Tests\Feature\Auditoria;

use App\Filament\Resources\PacienteResource\Pages\ViewPaciente;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Soporte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La orden médica es un dato de salud: vive en el disco privado, exige permiso
 * y cada acceso queda registrado.
 */
class OrdenMedicaTest extends TestCase
{
    use RefreshDatabase;

    private Paciente $paciente;

    private Soporte $soporte;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
            'primer_nombre' => 'JUAN',
            'primer_apellido' => 'RAMIREZ',
        ]);

        $ruta = UploadedFile::fake()
            ->image('orden.jpg')
            ->storeAs('soportes/ordenes-medicas', 'orden.jpg', ['disk' => 'local']);

        $this->soporte = $this->paciente->soportes()->create([
            'orden_medica' => $ruta,
            'alto_costo_oncologico' => true,
            'cargado_por' => User::factory()->create()->id,
        ]);
    }

    private function url(): string
    {
        return route('soportes.orden-medica', $this->soporte);
    }

    /** Un usuario con el permiso de ver órdenes médicas. */
    private function conPermiso(): User
    {
        Rol::firstOrCreate(['nombre' => 'ORIENTADOR'], [
            'permisos' => ['pacientes' => ['ver'], 'orientacion' => ['usar', 'ver_orden']],
        ]);

        return User::factory()->create(['roles' => ['ORIENTADOR']]);
    }

    /* ------------------------------------------------------------------ *
     *  Quién puede llegar al archivo
     * ------------------------------------------------------------------ */

    public function test_sin_sesion_no_se_llega_al_archivo(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_sin_el_permiso_responde_403(): void
    {
        // Puede ver pacientes, pero no las órdenes médicas.
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['pacientes' => ['ver']]]);
        $this->actingAs(User::factory()->create(['roles' => ['CONSULTA']]));

        $this->get($this->url())->assertForbidden();
    }

    public function test_con_el_permiso_entrega_el_archivo(): void
    {
        $this->actingAs($this->conPermiso());

        $respuesta = $this->get($this->url());

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'image/jpeg');
        $this->assertStringContainsString(
            'orden-medica-1000873458-',
            $respuesta->headers->get('content-disposition'),
        );
    }

    public function test_el_administrador_siempre_puede(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        $this->get($this->url())->assertOk();
    }

    public function test_responde_404_si_el_archivo_ya_no_esta(): void
    {
        $this->actingAs($this->conPermiso());

        Storage::disk('local')->delete($this->soporte->orden_medica);

        $this->get($this->url())->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     *  Cada acceso queda registrado
     * ------------------------------------------------------------------ */

    public function test_abrir_la_orden_queda_en_la_auditoria(): void
    {
        $usuario = $this->conPermiso();
        $this->actingAs($usuario);

        $this->get($this->url())->assertOk();

        $registro = Auditoria::where('accion', Auditoria::ACCION_DESCARGO_ORDEN)->firstOrFail();

        $this->assertSame('Abrió la orden médica del paciente CC 1000873458', $registro->descripcion);
        $this->assertSame($usuario->id, $registro->usuario_id);
        $this->assertSame('orden_medica', $registro->entidad_tipo);
        $this->assertSame($this->soporte->id, $registro->entidad_id);
    }

    public function test_la_auditoria_no_guarda_la_ruta_ni_el_nombre_del_paciente(): void
    {
        $this->actingAs($this->conPermiso());

        $this->get($this->url());

        $registro = Auditoria::where('accion', Auditoria::ACCION_DESCARGO_ORDEN)->firstOrFail();
        $todo = json_encode($registro->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('ordenes-medicas', $todo);
        $this->assertStringNotContainsString('JUAN', $todo);
        $this->assertStringNotContainsString('RAMIREZ', $todo);
        $this->assertStringNotContainsString('alto_costo', $todo);
    }

    public function test_un_intento_sin_permiso_no_deja_registro_de_acceso(): void
    {
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['pacientes' => ['ver']]]);
        $this->actingAs(User::factory()->create(['roles' => ['CONSULTA']]));

        $this->get($this->url())->assertForbidden();

        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_DESCARGO_ORDEN)->count());
    }

    /* ------------------------------------------------------------------ *
     *  El enlace en la ficha del paciente
     * ------------------------------------------------------------------ */

    public function test_la_ficha_muestra_el_enlace_a_quien_tiene_permiso(): void
    {
        $this->actingAs($this->conPermiso());

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Órdenes médicas')
            ->assertSee('Abrir la orden médica')
            ->assertSee($this->url());
    }

    public function test_la_ficha_esconde_el_enlace_a_quien_no_tiene_permiso(): void
    {
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['pacientes' => ['ver']]]);
        $this->actingAs(User::factory()->create(['roles' => ['CONSULTA']]));

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee('Órdenes médicas')
            ->assertDontSee('Abrir la orden médica');
    }

    public function test_la_ficha_no_muestra_la_seccion_si_el_paciente_no_tiene_ordenes(): void
    {
        $this->actingAs($this->conPermiso());

        $otro = Paciente::factory()->create(['numero_documento' => '99999999']);

        Livewire::test(ViewPaciente::class, ['record' => $otro->getRouteKey()])
            ->assertDontSee('Órdenes médicas');
    }
}
