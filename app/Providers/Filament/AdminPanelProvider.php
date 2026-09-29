<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
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
            ]);
    }
}
