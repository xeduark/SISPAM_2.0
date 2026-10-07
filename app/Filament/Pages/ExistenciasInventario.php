<?php

namespace App\Filament\Pages;

use App\Models\Sede;
use App\Services\Inventario\InventarioApi;
use Filament\Pages\Page;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Consulta de un producto por SKU: stock entregable, lotes y kardex en la sede.
 * Solo consulta: el inventario se administra en su propio sistema (inventario-api).
 */
class ExistenciasInventario extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Consultar por SKU';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?string $title = 'Existencias y kardex por SKU';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'inventario/existencias';

    protected static string $view = 'filament.pages.existencias-inventario';

    public static function canAccess(): bool
    {
        return InventarioApi::configurada() && (bool) auth()->user()?->puede('inventario.ver');
    }

    public string $sku = '';

    public ?string $sede = null;

    /** @var array<string, mixed>|null */
    public ?array $resultado = null;

    public ?string $aviso = null;

    public function mount(): void
    {
        $this->sede = auth()->user()?->sede?->codigo;
    }

    /** @return array<string, string> */
    public function getSedesProperty(): array
    {
        return Sede::where('activa', true)->orderBy('nombre')->pluck('nombre', 'codigo')->all();
    }

    public function consultar(InventarioApi $api): void
    {
        $sku = mb_strtoupper(trim($this->sku));
        $this->resultado = null;
        $this->aviso = null;

        if ($sku === '') {
            $this->aviso = 'Escribe el SKU (código del medicamento), por ejemplo MX804-1.';

            return;
        }

        try {
            $this->resultado = $api->producto($sku, $this->sede ?: null);
            $this->aviso = $this->resultado ? null : "El SKU {$sku} no existe en el inventario.";
        } catch (ConnectionException|RequestException) {
            $this->aviso = 'La API de inventario no respondió. Revisa que esté encendida.';
        }
    }

    public function urlSistemaInventarios(): string
    {
        return (string) config('services.inventario.url');
    }
}
