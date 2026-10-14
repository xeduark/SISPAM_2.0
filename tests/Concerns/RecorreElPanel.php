<?php

namespace Tests\Concerns;

use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\DomicilioEnvio;
use App\Models\Entrega;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\Ventanilla;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page as PaginaDeRecurso;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as Ruta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Recorre todas las pantallas del panel, para probar quién entra a qué.
 *
 * Las rutas salen del enrutador, no de una lista escrita a mano: una pantalla
 * nueva entra sola a las pruebas, y si olvida su permiso, alguna falla.
 */
trait RecorreElPanel
{
    /**
     * Todas las rutas GET del panel, menos las que se nombran.
     *
     * @param  list<string>  $menos
     * @return Collection<int, Ruta>
     */
    protected function rutasDelPanel(array $menos = []): Collection
    {
        $rutas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (Ruta $ruta): bool => in_array('GET', $ruta->methods(), true))
            ->filter(fn (Ruta $ruta): bool => str_starts_with((string) $ruta->getName(), 'filament.admin.'))
            ->reject(fn (Ruta $ruta): bool => in_array($ruta->getName(), [
                'filament.admin.home',
                'filament.admin.auth.login',
                'filament.admin.auth.logout',
                ...$menos,
            ], true))
            ->values();

        $this->assertGreaterThan(20, $rutas->count(), 'No se encontraron las rutas del panel.');

        return $rutas;
    }

    /** La dirección de la ruta, con un registro que exista en cada `{record}`. */
    protected function urlDe(Ruta $ruta): string
    {
        return '/'.ltrim(preg_replace_callback(
            '/\{(\w+)\??\}/',
            fn (): string => (string) $this->idPara($ruta),
            $ruta->uri(),
        ), '/');
    }

    /**
     * Las direcciones que sirven archivos fuera del panel: órdenes médicas,
     * ticket impreso, orden de entrega y acta.
     *
     * @return list<string>
     */
    protected function archivosFueraDelPanel(): array
    {
        $ticket = Ticket::query()->firstOrFail();
        $soporte = $ticket->paciente->soportes()->firstOrFail();

        return [
            route('soportes.orden-medica', $soporte),
            route('soportes.miniatura', $soporte),
            route('tickets.imprimir', $ticket),
            route('tickets.orden-entrega', $ticket),
            route('entregas.acta', Entrega::query()->firstOrFail()),
        ];
    }

    /**
     * Las etiquetas del menú de quien está en sesión. Pide antes una página
     * del panel para que Filament lo arme.
     *
     * @return list<string>
     */
    protected function menuDelPanel(): array
    {
        return collect(Filament::getNavigation())
            ->flatMap(fn ($grupo) => $grupo->getItems())
            ->map(fn ($item): string => (string) $item->getLabel())
            ->values()
            ->all();
    }

    /** Un registro de cada cosa que tiene ficha en el panel. */
    protected function crearUnRegistroDeCadaTipo(Sede $sede): void
    {
        Storage::fake('local');
        Queue::fake(); // la transcripción no lee la fórmula de verdad

        $ticket = Ticket::factory()->create(['sede_id' => $sede->id]);
        $ruta = UploadedFile::fake()->image('orden.jpg')->store('soportes/ordenes-medicas', 'local');
        $ticket->paciente->soportes()->create([
            'ticket_id' => $ticket->id,
            'orden_medica' => $ruta,
            'cargado_por' => $ticket->creado_por,
        ]);

        $entrega = Entrega::create([
            'ticket_numero' => $ticket->numero,
            'paciente_id' => $ticket->paciente_id,
            'sede_id' => $ticket->sede_id,
            'usuario_id' => $ticket->creado_por,
            'tipo' => Entrega::TIPO_PRESENCIAL,
        ]);
        DomicilioEnvio::create(['entrega_id' => $entrega->id]);

        Cola::factory()->create(['sede_id' => $sede->id]);
        Ventanilla::factory()->create(['sede_id' => $sede->id]);
        Auditoria::factory()->create();
    }

    private function idPara(Ruta $ruta): int
    {
        $pagina = $ruta->getControllerClass();
        $this->assertTrue(is_string($pagina) && is_subclass_of($pagina, PaginaDeRecurso::class), "No sé qué registro poner en {$ruta->uri()}.");

        $modelo = $pagina::getResource()::getModel();
        $id = $modelo::query()->value('id');
        $this->assertNotNull($id, "Falta un registro de {$modelo} para probar {$ruta->uri()}.");

        return (int) $id;
    }
}
