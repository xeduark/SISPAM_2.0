<?php

namespace App\Filament\Pages;

use App\Models\Entrega;
use App\Models\Sede;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteEntregas extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reportes de entrega';

    protected static ?string $navigationGroup = 'Entrega';

    protected static ?string $title = 'Reportes de entrega';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.reporte-entregas';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->puede('entrega.reportes');
    }

    /** @var array<string, mixed> */
    public array $filtros = [];

    /** @var Collection<int, Entrega>|null */
    public ?Collection $resultados = null;

    public function mount(): void
    {
        $this->form->fill([
            'desde' => now()->startOfMonth()->toDateString(),
            'hasta' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('desde')->label('Desde')->required(),
                Forms\Components\DatePicker::make('hasta')->label('Hasta')->required(),
                Forms\Components\Select::make('sede_id')
                    ->label('Sede de atención')
                    ->options(Sede::opciones())
                    ->searchable()
                    ->placeholder('Todas'),
                Forms\Components\Select::make('tipo')
                    ->label('Tipo')
                    ->options([
                        Entrega::TIPO_PRESENCIAL => 'Presencial',
                        Entrega::TIPO_DOMICILIO => 'Domicilio',
                    ])
                    ->placeholder('Todos'),
                Forms\Components\Select::make('estado')
                    ->label('Estado')
                    ->options([
                        Entrega::ESTADO_PARCIAL => 'Parcial',
                        Entrega::ESTADO_COMPLETADA => 'Completada',
                        Entrega::ESTADO_ANULADA => 'Anulada',
                    ])
                    ->placeholder('Todos'),
                Forms\Components\Toggle::make('solo_faltantes_pendientes')
                    ->label('Solo con cantidades pendientes'),
            ])
            ->columns(3)
            ->statePath('filtros');
    }

    public function consultar(): void
    {
        $this->form->validate();
        $this->resultados = $this->consulta()
            ->with(['paciente', 'sede', 'usuario', 'items', 'domicilioEnvio'])
            ->get();
    }

    public function exportarCsv(): StreamedResponse
    {
        $this->form->validate();
        $filas = $this->consulta()
            ->with(['paciente', 'sede', 'usuario', 'items', 'domicilioEnvio'])
            ->get();

        return response()->streamDownload(function () use ($filas): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'entrega_id', 'ticket', 'paciente', 'documento', 'fecha', 'sede_atencion', 'tipo',
                'codigo', 'medicamento', 'solicitada', 'entregada', 'pendiente', 'motivo',
                'resultado_item', 'estado_entrega', 'usuario_dispensador', 'estado_domicilio',
            ]);

            foreach ($filas as $entrega) {
                $documento = trim(($entrega->paciente?->tipo_documento ?? '').' '.($entrega->paciente?->numero_documento ?? ''));
                foreach ($entrega->items as $item) {
                    fputcsv($out, [
                        $entrega->id,
                        $entrega->ticket_numero,
                        $entrega->paciente?->nombre_completo,
                        $documento,
                        $entrega->created_at?->format('Y-m-d H:i'),
                        $entrega->sede?->nombre,
                        $entrega->tipo,
                        $item->codigo,
                        $item->nombre,
                        $item->cantidad_solicitada,
                        $item->cantidad_entregada,
                        $item->cantidad_pendiente,
                        $item->motivo,
                        $item->resultado,
                        $entrega->estado,
                        $entrega->usuario?->nombre_completo,
                        $entrega->domicilioEnvio?->estado,
                    ]);
                }
            }

            fclose($out);
        }, 'reporte-entregas-'.now()->format('Ymd-His').'.csv');
    }

    /**
     * @return Builder<Entrega>
     */
    private function consulta(): Builder
    {
        $f = $this->filtros;

        return Entrega::query()
            ->when($f['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('created_at', '>=', $desde))
            ->when($f['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('created_at', '<=', $hasta))
            ->when($f['sede_id'] ?? null, fn (Builder $q, $sede) => $q->where('sede_id', $sede))
            ->when($f['tipo'] ?? null, fn (Builder $q, $tipo) => $q->where('tipo', $tipo))
            ->when($f['estado'] ?? null, fn (Builder $q, $estado) => $q->where('estado', $estado))
            ->when(! empty($f['solo_faltantes_pendientes']), fn (Builder $q) => $q->whereHas(
                'items',
                fn (Builder $iq) => $iq->where('cantidad_pendiente', '>', 0)
            ))
            ->latest('id');
    }
}
