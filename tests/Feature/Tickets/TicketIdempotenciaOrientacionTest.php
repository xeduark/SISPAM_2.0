<?php

namespace Tests\Feature\Tickets;

use App\Filament\Resources\PacienteResource\Pages\EditPaciente;
use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\ContadorTurno;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Evita que la misma orden médica genere dos tickets por un segundo Guardar.
 */
class TicketIdempotenciaOrientacionTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Cola $general;

    private User $orientador;

    private Paciente $paciente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->sede = Sede::factory()->create(['nombre' => 'La 30', 'codigo' => 'LA30']);
        $this->general = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'atiende_alto_costo' => false,
        ]);

        Rol::updateOrCreate(['nombre' => 'ORIENTADOR'], ['permisos' => [
            'pacientes' => ['ver', 'crear', 'editar'],
            'orientacion' => ['usar'],
        ]]);

        $this->orientador = User::factory()->create([
            'sede_id' => $this->sede->id,
            'roles' => ['ORIENTADOR'],
        ]);

        $this->paciente = Paciente::factory()->create();
        $this->actingAs($this->orientador);
    }

    public function test_segundo_guardar_sin_orden_nueva_no_duplica_ticket_ni_soporte(): void
    {
        $pagina = Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => UploadedFile::fake()->image('orden-x.png'),
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Ticket A-001 generado');

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertNull(data_get($pagina->get('data'), 'orden_medica'));

        $pagina->fillForm(['contacto_confirmado' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame(1, ContadorTurno::query()->where('cola_id', $this->general->id)->value('ultimo') ?? 1);
    }

    public function test_misma_ruta_de_orden_no_crea_segundo_ticket_ni_consume_turno(): void
    {
        Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => UploadedFile::fake()->image('orden-x.png'),
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $ruta = Soporte::query()->value('orden_medica');
        $ticket = Ticket::query()->sole();
        $auditoriasAntes = Auditoria::query()->count();

        Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                // FileUpload guarda estado como [uuid => ruta], no string suelto.
                'orden_medica' => ['reintento' => $ruta],
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Orden médica ya procesada');

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame($ticket->id, Ticket::query()->value('id'));
        $this->assertSame('A-001', $ticket->fresh()->turno);

        // No se reservó A-002.
        $this->assertSame(1, (int) ContadorTurno::query()->where('cola_id', $this->general->id)->value('ultimo'));

        // No inventa «Creó el ticket» / «Creó una orden médica» otra vez.
        $nuevas = Auditoria::query()->where('id', '>', $auditoriasAntes)->get();
        foreach ($nuevas as $registro) {
            $this->assertStringNotContainsString('Creó el ticket', (string) $registro->descripcion);
            $this->assertStringNotContainsString('Creó una orden médica', (string) $registro->descripcion);
        }
    }

    public function test_orden_distinta_genera_segundo_ticket_legitimo(): void
    {
        Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => UploadedFile::fake()->image('orden-x.png'),
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => UploadedFile::fake()->image('orden-y.png'),
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Ticket A-002 generado');

        $this->assertSame(2, Ticket::count());
        $this->assertSame(2, Soporte::count());
        $this->assertSame(
            ['A-001', 'A-002'],
            Ticket::query()->orderBy('id')->pluck('turno')->all()
        );
        $this->assertCount(2, Soporte::query()->pluck('orden_medica')->unique());
    }

    public function test_ticket_anulado_con_misma_orden_no_regenera_turno(): void
    {
        Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => UploadedFile::fake()->image('orden-x.png'),
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $ruta = Soporte::query()->value('orden_medica');
        $ticket = Ticket::query()->sole();
        $ticket->update([
            'estado' => Ticket::ESTADO_ANULADO,
            'motivo_anulacion' => 'prueba',
            'cerrado_en' => now(),
        ]);

        Livewire::test(EditPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => ['reintento' => $ruta],
                'prioridad' => Ticket::PRIORIDAD_NORMAL,
            ])
            ->call('save')
            ->assertNotified('Orden médica ya procesada');

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame(Ticket::ESTADO_ANULADO, $ticket->fresh()->estado);
        $this->assertSame(1, (int) ContadorTurno::query()->where('cola_id', $this->general->id)->value('ultimo'));
    }
}
