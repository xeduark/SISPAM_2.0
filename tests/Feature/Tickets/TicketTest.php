<?php

namespace Tests\Feature\Tickets;

use App\Filament\Resources\PacienteResource\Pages\CreatePaciente;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\AlistarTicket;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El ticket de una visita: cómo nace, cómo se alista y quién lo ve.
 */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Cola $general;

    private Cola $altoCosto;

    private User $orientador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create(['nombre' => 'La 30', 'codigo' => 'LA30']);

        $this->general = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'atiende_alto_costo' => false,
        ]);

        $this->altoCosto = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Alto costo y oncológicos',
            'prefijo' => 'B',
            'orden' => 2,
            'atiende_alto_costo' => true,
        ]);

        $this->orientador = User::factory()->create(['sede_id' => $this->sede->id]);
    }

    private function generar(array $datos = [], ?Paciente $paciente = null): Ticket
    {
        return app(GenerarTicket::class)->handle(
            $paciente ?? Paciente::factory()->create(),
            $this->sede,
            $this->orientador,
            $datos,
        );
    }

    /** Usuario con permisos sobre tickets en la sede de prueba. */
    private function farmaceutico(array $acciones = ['ver', 'alistar', 'anular']): User
    {
        Rol::updateOrCreate(['nombre' => 'FARMACIA'], ['permisos' => ['tickets' => $acciones]]);

        return User::factory()->create(['sede_id' => $this->sede->id, 'roles' => ['FARMACIA']]);
    }

    /* ------------------------------------------------------------------ *
     *  Cómo nace el ticket
     * ------------------------------------------------------------------ */

    public function test_nace_en_generado_y_sin_medicamentos(): void
    {
        $ticket = $this->generar();

        $this->assertSame(Ticket::ESTADO_GENERADO, $ticket->estado);
        $this->assertSame(0, $ticket->items()->count());
        $this->assertSame($this->orientador->id, $ticket->creado_por);
        $this->assertTrue($ticket->fecha->isToday());
    }

    public function test_el_numero_lleva_sede_fecha_y_turno_y_es_unico(): void
    {
        $ticket = $this->generar();

        $esperado = 'SP-LA30-'.now()->format('Ymd').'-A001';

        $this->assertSame($esperado, $ticket->numero);
        $this->assertSame('A-001', $ticket->turno);

        // El segundo no repite ni número ni turno.
        $segundo = $this->generar();
        $this->assertSame('SP-LA30-'.now()->format('Ymd').'-A002', $segundo->numero);
        $this->assertSame('A-002', $segundo->turno);
    }

    public function test_el_turno_se_repite_entre_sedes_pero_el_numero_no(): void
    {
        $bic = Sede::factory()->create(['nombre' => 'BIC', 'codigo' => 'BIC']);
        Cola::factory()->create(['sede_id' => $bic->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);

        $enLa30 = $this->generar();
        $enBic = app(GenerarTicket::class)->handle(
            Paciente::factory()->create(),
            $bic,
            User::factory()->create(['sede_id' => $bic->id]),
        );

        // El paciente ve el mismo turno en las dos sedes...
        $this->assertSame('A-001', $enLa30->turno);
        $this->assertSame('A-001', $enBic->turno);

        // ...pero el número que busca entrega es distinto.
        $this->assertNotSame($enLa30->numero, $enBic->numero);
        $this->assertStringContainsString('LA30', $enLa30->numero);
        $this->assertStringContainsString('BIC', $enBic->numero);
    }

    public function test_el_alto_costo_va_a_su_cola_y_el_resto_a_la_general(): void
    {
        $corriente = $this->generar(['alto_costo' => false]);
        $oncologico = $this->generar(['alto_costo' => true]);

        $this->assertSame($this->general->id, $corriente->cola_id);
        $this->assertSame('A-001', $corriente->turno);

        $this->assertSame($this->altoCosto->id, $oncologico->cola_id);
        $this->assertSame('B-001', $oncologico->turno);
        $this->assertTrue($oncologico->alto_costo);
    }

    public function test_si_la_sede_no_tiene_cola_de_alto_costo_usa_la_general(): void
    {
        $this->altoCosto->delete();

        $ticket = $this->generar(['alto_costo' => true]);

        $this->assertSame($this->general->id, $ticket->cola_id);
        $this->assertTrue($ticket->alto_costo);
    }

    public function test_una_sede_sin_colas_activas_avisa_con_un_mensaje_claro(): void
    {
        $this->sede->colas()->update(['activa' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no tiene colas activas');

        $this->generar();
    }

    public function test_guarda_la_prioridad_preferencial_y_su_motivo(): void
    {
        $ticket = $this->generar([
            'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
            'motivo_prioridad' => 'gestante',
        ]);

        $this->assertTrue($ticket->esPreferencial());
        $this->assertSame('gestante', $ticket->motivo_prioridad);
    }

    /* ------------------------------------------------------------------ *
     *  Alistamiento
     * ------------------------------------------------------------------ */

    public function test_farmacia_captura_los_medicamentos_y_lo_deja_listo(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_LISTO, $ticket->estado);
        $this->assertTrue($ticket->estaEntregable());
        $this->assertSame(2, $ticket->items()->count());
        $this->assertSame($farmaceutico->id, $ticket->alistado_por);
        $this->assertNotNull($ticket->alistado_en);
        $this->assertSame('ACETAMINOFEN 500 MG', $ticket->items()->orderBy('id')->first()->nombre);
    }

    public function test_volver_a_alistar_reemplaza_los_medicamentos_sin_duplicar(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-001', 'nombre' => 'PRIMERO', 'cantidad' => 1],
        ]);

        // Se corrige el alistamiento: queda solo lo último.
        $ticket->update(['estado' => Ticket::ESTADO_EN_ALISTAMIENTO]);
        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-002', 'nombre' => 'SEGUNDO', 'cantidad' => 2],
        ]);

        $ticket->refresh();

        $this->assertSame(1, $ticket->items()->count());
        $this->assertSame('SEGUNDO', $ticket->items()->first()->nombre);
    }

    public function test_no_se_alista_sin_medicamentos(): void
    {
        $ticket = $this->generar();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('al menos un medicamento');

        app(AlistarTicket::class)->handle($ticket, $this->farmaceutico(), []);
    }

    public function test_no_se_alista_un_ticket_ya_entregado(): void
    {
        $ticket = $this->generar();
        $ticket->update(['estado' => Ticket::ESTADO_ENTREGADO]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ya no se puede alistar');

        app(AlistarTicket::class)->handle($ticket, $this->farmaceutico(), [
            ['codigo' => 'MED-001', 'nombre' => 'X', 'cantidad' => 1],
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Anulación
     * ------------------------------------------------------------------ */

    public function test_anular_cierra_el_ticket_con_su_motivo(): void
    {
        $ticket = $this->generar();

        app(AlistarTicket::class)->anular($ticket, $this->farmaceutico(), 'El paciente se retiró');

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_ANULADO, $ticket->estado);
        $this->assertSame('El paciente se retiró', $ticket->motivo_anulacion);
        $this->assertNotNull($ticket->cerrado_en);
        $this->assertTrue($ticket->estaCerrado());
        $this->assertFalse($ticket->estaEntregable());
    }

    public function test_no_se_anula_dos_veces(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        app(AlistarTicket::class)->anular($ticket, $farmaceutico, 'Primera');

        $this->expectException(\InvalidArgumentException::class);

        app(AlistarTicket::class)->anular($ticket->fresh(), $farmaceutico, 'Segunda');
    }

    /* ------------------------------------------------------------------ *
     *  La pantalla
     * ------------------------------------------------------------------ */

    public function test_cada_quien_ve_solo_los_tickets_de_su_sede(): void
    {
        $delaSede = $this->generar();

        $bic = Sede::factory()->create(['nombre' => 'BIC', 'codigo' => 'BIC']);
        $deOtraSede = Ticket::factory()->create(['sede_id' => $bic->id]);

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$delaSede])
            ->assertCanNotSeeTableRecords([$deOtraSede]);
    }

    public function test_la_pestana_por_alistar_muestra_solo_los_pendientes(): void
    {
        $pendiente = $this->generar();
        $listo = $this->generar();
        app(AlistarTicket::class)->handle($listo, $this->farmaceutico(), [
            ['codigo' => 'MED-001', 'nombre' => 'X', 'cantidad' => 1],
        ]);

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$pendiente])
            ->assertCanNotSeeTableRecords([$listo->fresh()]);
    }

    public function test_alistar_desde_la_pantalla_deja_el_ticket_listo(): void
    {
        $ticket = $this->generar();

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->callTableAction('alistar', $ticket, data: [
                'items' => [
                    ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20, 'unidad' => 'TAB'],
                ],
            ]);

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_LISTO, $ticket->estado);
        $this->assertSame(1, $ticket->items()->count());
    }

    public function test_anular_desde_la_pantalla_pide_motivo(): void
    {
        $ticket = $this->generar();

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->callTableAction('anular', $ticket, data: ['motivo' => 'Orden duplicada']);

        $this->assertSame(Ticket::ESTADO_ANULADO, $ticket->fresh()->estado);
        $this->assertSame('Orden duplicada', $ticket->fresh()->motivo_anulacion);
    }

    public function test_sin_permiso_de_alistar_la_accion_no_aparece(): void
    {
        $ticket = $this->generar();

        $this->actingAs($this->farmaceutico(['ver']));

        Livewire::test(ListTickets::class)
            ->assertTableActionHidden('alistar', $ticket)
            ->assertTableActionHidden('anular', $ticket);
    }

    public function test_sin_permiso_no_se_entra_a_la_pantalla(): void
    {
        $this->actingAs(User::factory()->create(['sede_id' => $this->sede->id]));

        $this->get('/admin/tickets')->assertForbidden();
    }

    public function test_el_ticket_no_se_crea_ni_se_edita_desde_la_pantalla(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        $this->assertFalse(TicketResource::canCreate());
        $this->get('/admin/tickets/create')->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     *  El flujo real: el ticket nace al registrar al paciente
     * ------------------------------------------------------------------ */

    public function test_registrar_un_paciente_con_orden_medica_genera_su_ticket(): void
    {
        Storage::fake('local');

        Rol::updateOrCreate(['nombre' => 'ORIENTADOR'], ['permisos' => [
            'pacientes' => ['ver', 'crear', 'editar'],
            'orientacion' => ['usar'],
        ]]);

        $orientador = User::factory()->create([
            'sede_id' => $this->sede->id,
            'roles' => ['ORIENTADOR'],
        ]);
        $this->actingAs($orientador);

        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A']),
            '*rest/afiliado/consultar-afiliado' => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'afiliados' => [[
                    'tipoDocumentoAfiliado' => 'CC',
                    'documentoAfiliado' => '1017234567',
                    'primerNombreAfiliado' => 'JUAN',
                    'primerApellidoAfiliado' => 'PEREZ',
                    'estadoAfiliacion' => 'Activo',
                    'regimen' => 'SUBSIDIADO',
                    'telefonoMovil' => '3001234567',
                    'direccion' => 'KR 40 70A 23',
                    'descripcionCiudadResidencia' => 'MEDELLÍN',
                ]],
            ]),
        ]);

        $pagina = Livewire::test(CreatePaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1017234567'])
            ->call('mountFormComponentAction', 'data.consultarEnSaviaAction', 'consultarEnSavia');

        $pagina->fillForm([
            'contacto_confirmado' => true,
            'alto_costo_oncologico' => true,
            'orden_medica' => UploadedFile::fake()->image('orden.jpg'),
            'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
            'motivo_prioridad' => 'adulto_mayor',
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $paciente = Paciente::where('numero_documento', '1017234567')->firstOrFail();
        $ticket = $paciente->tickets()->sole();

        // Por ser de alto costo, fue a la cola B.
        $this->assertSame($this->altoCosto->id, $ticket->cola_id);
        $this->assertSame('B-001', $ticket->turno);
        $this->assertTrue($ticket->alto_costo);
        $this->assertTrue($ticket->esPreferencial());
        $this->assertSame('adulto_mayor', $ticket->motivo_prioridad);
        $this->assertSame(Ticket::ESTADO_GENERADO, $ticket->estado);
        $this->assertSame($this->sede->id, $ticket->sede_id);

        // Y la orden médica quedó colgada de ese ticket.
        $this->assertSame($ticket->id, $paciente->soportes()->sole()->ticket_id);
    }

    public function test_si_la_sede_no_tiene_colas_el_paciente_igual_queda_registrado(): void
    {
        Storage::fake('local');

        // Una sede sin configurar: no debería costarle al paciente su registro.
        $sinColas = Sede::factory()->create(['nombre' => 'Sede sin configurar']);

        Rol::updateOrCreate(['nombre' => 'ORIENTADOR'], ['permisos' => [
            'pacientes' => ['ver', 'crear', 'editar'],
            'orientacion' => ['usar'],
        ]]);

        $this->actingAs(User::factory()->create([
            'sede_id' => $sinColas->id,
            'roles' => ['ORIENTADOR'],
        ]));

        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A']),
            '*rest/afiliado/consultar-afiliado' => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'afiliados' => [[
                    'tipoDocumentoAfiliado' => 'CC',
                    'documentoAfiliado' => '1017234567',
                    'primerNombreAfiliado' => 'JUAN',
                    'primerApellidoAfiliado' => 'PEREZ',
                    'estadoAfiliacion' => 'Activo',
                    'telefonoMovil' => '3001234567',
                    'direccion' => 'KR 40 70A 23',
                    'descripcionCiudadResidencia' => 'MEDELLÍN',
                ]],
            ]),
        ]);

        Livewire::test(CreatePaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1017234567'])
            ->call('mountFormComponentAction', 'data.consultarEnSaviaAction', 'consultarEnSavia')
            ->fillForm([
                'contacto_confirmado' => true,
                'alto_costo_oncologico' => false,
                'orden_medica' => UploadedFile::fake()->image('orden.jpg'),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('El paciente quedó registrado, pero sin ticket');

        $paciente = Paciente::where('numero_documento', '1017234567')->firstOrFail();

        // Nada de la orientación se perdió...
        $soporte = $paciente->soportes()->sole();
        Storage::disk('local')->assertExists($soporte->orden_medica);
        $this->assertSame('3001234567', $paciente->telefono_movil);

        // ...y simplemente no hay ticket.
        $this->assertNull($soporte->ticket_id);
        $this->assertSame(0, Ticket::count());
    }
    /* ------------------------------------------------------------------ *
     *  Auditoría
     * ------------------------------------------------------------------ */

    public function test_los_cambios_de_estado_quedan_en_la_auditoria(): void
    {
        $this->actingAs($this->farmaceutico());

        $ticket = $this->generar();
        Auditoria::query()->delete();

        app(AlistarTicket::class)->handle($ticket, auth()->user(), [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20],
        ]);

        $registro = Auditoria::where('entidad_tipo', 'ticket')->firstOrFail();

        $this->assertSame("Actualizó el ticket {$ticket->numero}", $registro->descripcion);
        $this->assertSame(['generado', 'listo'], $registro->cambios['estado']);

        // Ni el nombre del medicamento ni el del paciente entran al rastro.
        $todo = json_encode($registro->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ACETAMINOFEN', $todo);
    }
}
