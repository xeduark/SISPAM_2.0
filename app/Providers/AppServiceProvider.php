<?php

namespace App\Providers;

use App\Contracts\Domina\DominaClientInterface;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Http\Responses\LogoutResponse;
use App\Services\Domina\DominaClientMock;
use App\Services\Savia\SaviaClient;
use App\Services\Ticket\TicketConsultaMock;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse as LogoutResponseContract;
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

        // Ticket y Dómina: mocks hasta que existan las implementaciones reales.
        $this->app->bind(TicketConsultaInterface::class, TicketConsultaMock::class);
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
    }
}
