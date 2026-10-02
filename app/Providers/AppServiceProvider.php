<?php

namespace App\Providers;

use App\Contracts\Domina\DominaClientInterface;
use App\Contracts\Ticket\TicketCierreInterface;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Http\Responses\LogoutResponse;
use App\Listeners\SincronizarEstadoDelTicket;
use App\Models\Auditoria;
use App\Models\Entrega;
use App\Services\Domina\DominaClientMock;
use App\Services\Savia\SaviaClient;
use App\Services\Ticket\TicketCierreDb;
use App\Services\Ticket\TicketConsultaDb;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);

        // Un solo cliente por petición: así el token en caché se reutiliza.
        $this->app->singleton(SaviaClient::class, fn () => SaviaClient::desdeConfig());

        // El módulo de ticket ya existe: entrega lee tickets reales. El mock
        // sigue en el proyecto como doble de prueba del módulo de entrega.
        $this->app->bind(TicketConsultaInterface::class, TicketConsultaDb::class);
        $this->app->bind(TicketCierreInterface::class, TicketCierreDb::class);

        // Dómina sigue en mock hasta que exista la integración real.
        $this->app->bind(DominaClientInterface::class, DominaClientMock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('authentik', AuthentikProvider::class);
        });

        // Quién entra y quién sale queda en la auditoría. Se escucha el evento
        // y no el controlador, para cubrir cualquier camino de autenticación.
        Event::listen(Login::class, function (Login $evento): void {
            Auditoria::registrar(
                accion: Auditoria::ACCION_INGRESO,
                descripcion: 'Ingresó al sistema',
                entidadTipo: 'usuario',
                entidadId: $evento->user->getKey(),
                usuario: $evento->user,
            );
        });

        // Cuando entrega guarda una atención, el ticket se pone al día. Se
        // escucha el modelo para no tocar el código del módulo de entrega:
        // los dos se hablan solo por el puerto `TicketCierreInterface`.
        Entrega::saved(fn (Entrega $entrega) => app(SincronizarEstadoDelTicket::class)->handle($entrega));

        Event::listen(Logout::class, function (Logout $evento): void {
            if ($evento->user === null) {
                return;
            }

            Auditoria::registrar(
                accion: Auditoria::ACCION_CERRO_SESION,
                descripcion: 'Cerró sesión',
                entidadTipo: 'usuario',
                entidadId: $evento->user->getKey(),
                usuario: $evento->user,
            );
        });
    }
}
