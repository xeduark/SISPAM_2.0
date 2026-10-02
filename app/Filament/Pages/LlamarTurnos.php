<?php

namespace App\Filament\Pages;

use App\Models\Cola;
use App\Models\Llamado;
use App\Models\Ticket;
use App\Models\Ventanilla;
use App\Services\Turnos\LlamadorDeTurnos;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * La pantalla de quien atiende en la ventanilla: llama el siguiente turno,
 * vuelve a llamar y marca a los que no se presentaron.
 *
 * Lo que ve el paciente es la pantalla pública de la sala (`/sala/{codigo}`),
 * que muestra solo turno y ventanilla. Aquí sí aparece el paciente, porque
 * quien llama tiene sesión y permiso.
 */
class LlamarTurnos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Llamar turnos';

    protected static ?string $title = 'Llamar turnos';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.llamar-turnos';

    /** Dónde se guarda la ventanilla escogida, para no volver a elegirla cada rato. */
    private const SESION_VENTANILLA = 'turnos.ventanilla';

    /** @var array<string, mixed> */
    public array $config = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->puede('turnos.ver');
    }

    /** Cuántos pacientes esperan en la sede: sale como aviso en el menú. */
    public static function getNavigationBadge(): ?string
    {
        $sedeId = auth()->user()?->sede_id;

        if ($sedeId === null) {
            return null;
        }

        $esperando = app(LlamadorDeTurnos::class)->cuantosEsperan((int) $sedeId);

        return $esperando > 0 ? (string) $esperando : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public function mount(): void
    {
        $disponibles = $this->ventanillasDisponibles()->keys();
        $guardada = session(self::SESION_VENTANILLA);

        $this->configForm->fill([
            // La guardada puede ser de otra sede (al usuario lo trasladaron) o
            // estar desactivada: en ese caso se arranca con la primera.
            'ventanilla_id' => $disponibles->contains($guardada) ? $guardada : $disponibles->first(),
            'colas' => [],
        ]);
    }

    protected function getForms(): array
    {
        return ['configForm'];
    }

    public function configForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('ventanilla_id')
                    ->label('Ventanilla')
                    ->options(fn (): array => $this->ventanillasDisponibles()->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state) => session([self::SESION_VENTANILLA => $state]))
                    ->helperText('Desde aquí se llama. Queda guardada para la próxima.'),

                Forms\Components\CheckboxList::make('colas')
                    ->label('Llamar de estas colas')
                    ->options(fn (): array => $this->colasDisponibles())
                    ->columns(2)
                    ->live()
                    ->helperText('Si no marcas ninguna, se llama de todas.'),
            ])
            ->columns(2)
            ->statePath('config');
    }

    /* ------------------------------------------------------ Acciones */

    public function llamarSiguiente(): void
    {
        $ventanilla = $this->ventanilla();

        if (! $this->puedeLlamar($ventanilla)) {
            return;
        }

        $ticket = app(LlamadorDeTurnos::class)
            ->siguiente($ventanilla, auth()->user(), $this->colasEscogidas());

        if ($ticket === null) {
            Notification::make()
                ->title('No hay nadie esperando')
                ->body('Cuando farmacia deje un ticket listo, aparece aquí.')
                ->info()
                ->send();

            return;
        }

        $this->avisarLlamado($ticket, $ventanilla);
    }

    /** Llamar a uno puntual de la lista, sin respetar el orden. */
    public function llamarEste(int $ticketId): void
    {
        $ventanilla = $this->ventanilla();

        if (! $this->puedeLlamar($ventanilla)) {
            return;
        }

        $ticket = $this->ticketDeLaSala($ticketId);

        if ($ticket === null) {
            return;
        }

        try {
            app(LlamadorDeTurnos::class)->llamar($ticket, $ventanilla, auth()->user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('No se pudo llamar')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->avisarLlamado($ticket, $ventanilla);
    }

    public function noSePresento(int $ticketId): void
    {
        if (! auth()->user()?->puede('turnos.ausente')) {
            Notification::make()->title('No tienes permiso para marcar ausentes')->danger()->send();

            return;
        }

        $ticket = $this->ticketDeLaSala($ticketId);

        if ($ticket === null) {
            return;
        }

        try {
            app(LlamadorDeTurnos::class)->marcarAusente($ticket);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('No se pudo marcar')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title("El turno {$ticket->turno} no se presentó")
            ->body('Queda abajo, por si aparece y hay que volver a llamarlo.')
            ->warning()
            ->send();
    }

    /* -------------------------------------------------- Lo que se ve */

    public function ventanilla(): ?Ventanilla
    {
        $id = $this->config['ventanilla_id'] ?? null;

        if (blank($id)) {
            return null;
        }

        return Ventanilla::query()
            ->whereKey($id)
            ->when(! auth()->user()?->es_administrador, fn (Builder $q) => $q->where('sede_id', auth()->user()?->sede_id))
            ->first();
    }

    /** El turno que esta ventanilla tiene en la mano. */
    public function enAtencion(): ?Ticket
    {
        $ventanilla = $this->ventanilla();

        return $ventanilla === null
            ? null
            : app(LlamadorDeTurnos::class)->enAtencion($ventanilla);
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function enEspera(): Collection
    {
        $ventanilla = $this->ventanilla();

        return $ventanilla === null
            ? new Collection
            : app(LlamadorDeTurnos::class)->enEspera((int) $ventanilla->sede_id, $this->colasEscogidas());
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function ausentes(): Collection
    {
        $ventanilla = $this->ventanilla();

        return $ventanilla === null
            ? new Collection
            : app(LlamadorDeTurnos::class)->ausentes((int) $ventanilla->sede_id, $this->colasEscogidas());
    }

    /**
     * @return Collection<int, Llamado>
     */
    public function ultimosLlamados(): Collection
    {
        $ventanilla = $this->ventanilla();

        return $ventanilla === null
            ? new Collection
            : app(LlamadorDeTurnos::class)->ultimosLlamados((int) $ventanilla->sede_id);
    }

    /** La pantalla que se pone en el televisor de la sala. */
    public function urlDeLaSala(): ?string
    {
        $sede = $this->ventanilla()?->sede;

        return blank($sede?->codigo) ? null : route('sala', ['sede' => $sede->codigo]);
    }

    /* ---------------------------------------------------- Adentro */

    /** Las ventanillas activas de la sede; el administrador ve todas. */
    private function ventanillasDisponibles(): \Illuminate\Support\Collection
    {
        $usuario = auth()->user();

        return Ventanilla::query()
            ->with('sede')
            ->where('activa', true)
            ->when(! $usuario?->es_administrador, fn (Builder $q) => $q->where('sede_id', $usuario?->sede_id))
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Ventanilla $v): array => [
                $v->getKey() => $usuario?->es_administrador
                    ? $v->nombre.' — '.$v->sede?->nombre
                    : $v->nombre,
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function colasDisponibles(): array
    {
        $sedeId = $this->ventanilla()?->sede_id ?? auth()->user()?->sede_id;

        if ($sedeId === null) {
            return [];
        }

        return Cola::query()
            ->where('sede_id', $sedeId)
            ->where('activa', true)
            ->orderBy('orden')
            ->get()
            ->mapWithKeys(fn (Cola $cola): array => [$cola->getKey() => $cola->nombre.' ('.$cola->prefijo.')'])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function colasEscogidas(): array
    {
        return array_values(array_map('intval', $this->config['colas'] ?? []));
    }

    private function puedeLlamar(?Ventanilla $ventanilla): bool
    {
        if (! auth()->user()?->puede('turnos.llamar')) {
            Notification::make()->title('No tienes permiso para llamar turnos')->danger()->send();

            return false;
        }

        if ($ventanilla === null) {
            Notification::make()
                ->title('Escoge la ventanilla')
                ->body('Hay que decir desde cuál ventanilla se está llamando.')
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    /** El ticket, siempre dentro de la sede de la ventanilla escogida. */
    private function ticketDeLaSala(int $ticketId): ?Ticket
    {
        $ventanilla = $this->ventanilla();

        $ticket = Ticket::query()
            ->whereKey($ticketId)
            ->when($ventanilla !== null, fn (Builder $q) => $q->where('sede_id', $ventanilla->sede_id))
            ->first();

        if ($ticket === null) {
            Notification::make()->title('Ese turno ya no está en la sala')->warning()->send();
        }

        return $ticket;
    }

    private function avisarLlamado(Ticket $ticket, Ventanilla $ventanilla): void
    {
        Notification::make()
            ->title("Turno {$ticket->turno} a {$ventanilla->nombre}")
            ->body($ticket->esPreferencial() ? 'Preferencial.' : null)
            ->success()
            ->send();
    }
}
