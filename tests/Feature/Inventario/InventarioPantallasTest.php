<?php

namespace Tests\Feature\Inventario;

use App\Filament\Pages\ExistenciasInventario;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Consulta por SKU en SISPAM_2. La API de inventario se finge con Http::fake(). */
class InventarioPantallasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.inventario.url' => 'http://inventario.test', 'services.inventario.token' => 'secreto']);
        $sede = Sede::factory()->create(['codigo' => 'LA30']);
        $this->actingAs(User::factory()->create(['es_administrador' => true, 'sede_id' => $sede->id]));
    }

    public function test_consulta_un_sku_en_la_sede(): void
    {
        Http::fake(['inventario.test/api/productos/DEMO-GOT-1*' => Http::response([
            'producto' => ['codigo' => 'DEMO-GOT-1', 'codigo_agrupador' => 'DEMO', 'nombre_generico' => 'TIMOLOL', 'concentracion' => '0.5 %',
                'forma_farmaceutica' => 'SOLUCION OFTALMICA', 'presentacion_comercial' => 'FRASCO X 5 ML', 'unidad_minima' => 'GOTA',
                'unidades_por_presentacion' => 100, 'codigo_cums' => null, 'codigo_atc' => null, 'activo' => true],
            'stock_dispensable' => 24,
            'lotes' => [['numero_lote' => 'L-TIMOLOL', 'fecha_vencimiento' => now()->addDays(30)->toDateString(), 'cantidad' => 4,
                'estado' => 'DISPONIBLE', 'dispensable' => true, 'bodega' => 'Bodega La 30', 'codigo_sede' => 'LA30']],
            'movimientos' => [['fecha' => now()->toIso8601String(), 'tipo' => 'SALIDA_DISPENSACION', 'cantidad' => -2, 'stock_anterior' => 6,
                'stock_nuevo' => 4, 'numero_lote' => 'L-TIMOLOL', 'bodega' => 'Bodega La 30', 'referencia' => 'ENTREGA-7', 'usuario' => 'Ana']],
        ])]);

        Livewire::test(ExistenciasInventario::class)
            ->assertSet('sede', 'LA30') // la sede de quien consulta
            ->set('sku', ' demo-got-1 ')
            ->call('consultar')
            ->assertSee('L-TIMOLOL')
            ->assertSee('100 gota(s) por presentación')
            ->assertSee('ENTREGA-7')
            ->assertSee('30 días');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/productos/DEMO-GOT-1?sede=LA30') && $r->hasHeader('Authorization', 'Bearer secreto'));
    }

    public function test_sku_que_no_existe(): void
    {
        Http::fake(['inventario.test/*' => Http::response(['message' => 'No query results'], 404)]);

        Livewire::test(ExistenciasInventario::class)
            ->set('sku', 'NO-EXISTE')
            ->call('consultar')
            ->assertSet('resultado', null)
            ->assertSee('no existe en el inventario');
    }

    public function test_sin_permiso_no_entra(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['CONSULTA']]));

        $this->get(ExistenciasInventario::getUrl())->assertForbidden();
    }
}
