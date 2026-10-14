<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\CerrarCuentaCompartidaInactiva;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Paletas de estilos_generador_consolidado.md. Filament usa el tono 600 para
     * acciones en tema claro (hover 700) y el 500 en tema oscuro (hover 400).
     */
    private const AZUL_INSTITUCIONAL = [
        50 => '238, 244, 250',
        100 => '214, 228, 241',
        200 => '176, 203, 228',
        300 => '134, 173, 211',
        400 => '97, 155, 208',   // #619bd0 hover oscuro
        500 => '77, 138, 196',   // #4d8ac4 primario oscuro
        600 => '31, 78, 121',    // #1f4e79 primario claro
        700 => '23, 59, 92',     // #173b5c hover claro
        800 => '18, 47, 74',
        900 => '14, 36, 58',
        950 => '8, 23, 42',
    ];

    private const GRIS_INSTITUCIONAL = [
        50 => '243, 245, 247',   // #f3f5f7 fondo claro
        100 => '233, 237, 240',
        200 => '223, 228, 232',  // #dfe4e8 borde claro
        300 => '197, 205, 211',
        400 => '170, 181, 188',  // #aab5bc texto secundario oscuro
        500 => '102, 114, 125',  // #66727d texto secundario claro
        600 => '79, 91, 102',
        700 => '48, 58, 64',     // #303a40 borde oscuro
        800 => '29, 37, 42',     // #1d252a tarjeta suave oscura
        900 => '23, 29, 33',     // #171d21 tarjeta oscura
        950 => '16, 20, 23',     // #101417 fondo oscuro
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('SISPAM 2')
            ->brandLogo(fn () => view('filament.marca'))
            ->favicon(asset('img/favicon.svg'))
            ->font('Arial', provider: LocalFontProvider::class)
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Light)
            ->colors([
                'primary' => self::AZUL_INSTITUCIONAL,
                'gray' => self::GRIS_INSTITUCIONAL,
                'success' => Color::hex('#198754'),
                'warning' => Color::hex('#b7791f'),
                'danger' => Color::hex('#c53030'),
                'info' => Color::hex('#2563eb'),
            ])
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/sispam-tema.css').'">',
            )
            // En qué sede está trabajando quien entró. Solo se pinta si tiene
            // más de una asignada; el componente lo decide.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => Blade::render('@livewire(\'selector-de-sede\')'),
            )
            // Las cuentas compartidas se cierran solas tras un rato sin uso;
            // este es el reloj del navegador. El del servidor es
            // `CerrarCuentaCompartidaInactiva`.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->user()?->cuenta_compartida
                    ? view('filament.cuenta-compartida.cierre-por-inactividad')->render()
                    : '',
            )
            // En producción, un error de Livewire sale como notificación en
            // español en vez del recuadro con «Server Error».
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => config('app.debug') ? '' : view('filament.errores-de-livewire')->render(),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // El Escritorio es `App\Filament\Pages\Dashboard`, que se descubre
            // solo: a quien solo puede consultar lo manda a Consultar paciente.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // La pantalla pública de la sala de la sede de quien entra (ej. /sala/PRIN).
            ->navigationItems([
                NavigationItem::make('Pantalla de sala')
                    ->group('Turnero')
                    ->icon('heroicon-o-tv')
                    ->sort(9)
                    ->url(fn (): string => route('sala', ['sede' => auth()->user()?->sede?->codigo ?? 'PRIN']))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => (bool) auth()->user()?->puede('turnos.ver')),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // Sin el recuadro de Filament (versión, documentación, GitHub):
            // no le sirve a nadie que use SISPAM.
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Solo actúa sobre cuentas compartidas. Persistente para que
            // también corra en las peticiones de Livewire: consultar es una
            // de ellas, y cuenta como uso.
            ->authMiddleware([
                CerrarCuentaCompartidaInactiva::class,
            ], isPersistent: true);
    }
}
