<?php

namespace App\Providers;

use App\Contracts\Domina\DominaClientInterface;
use App\Contracts\Inventario\CatalogoInventarioInterface;
use App\Contracts\Ticket\TicketCierreInterface;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Http\Responses\LogoutResponse;
use App\Jobs\DescontarInventarioDeEntrega;
use App\Jobs\LeerFormula;
use App\Listeners\SincronizarEstadoDelTicket;
use App\Models\Auditoria;
use App\Models\Entrega;
use App\Models\Soporte;
use App\Models\Transcripcion;
use App\Services\Domina\DominaClientMock;
use App\Services\Inventario\CatalogoInventarioMock;
use App\Services\Inventario\InventarioApi;
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

        // Inventario: la API (proyecto inventario-api) si está en el .env; si no, el catálogo de prueba.
        $this->app->bind(CatalogoInventarioInterface::class, fn () => InventarioApi::configurada()
            ? new InventarioApi
            : new CatalogoInventarioMock);
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

        // Lo entregado sale del inventario, escuchando el modelo para no tocar el módulo de entrega.
        // `updated` y no `saved`: un save sin cambios repite `saved` con el wasChanged viejo.
        Entrega::updated(function (Entrega $entrega): void {
            if (InventarioApi::configurada()
                && in_array($entrega->estado, [Entrega::ESTADO_COMPLETADA, Entrega::ESTADO_PARCIAL], true)
                && $entrega->wasChanged('estado')) {
                DescontarInventarioDeEntrega::dispatch($entrega)->afterCommit();
            }
        });

        // Cada orden médica cargada entra a la cola de transcripción. Se escucha
        // el modelo para no tocar el código de pacientes ni de tickets.
        Soporte::created(function (Soporte $soporte): void {
            $transcripcion = Transcripcion::create([
                'soporte_id' => $soporte->getKey(),
                'paciente_id' => $soporte->paciente_id,
                'ticket_id' => $soporte->ticket_id,
                'sede_id' => $soporte->ticket?->sede_id ?? $soporte->cargadoPor?->sede_id,
            ]);

            LeerFormula::dispatch($transcripcion)->afterCommit();
        });

        /*
         * Un soporte que se cargó sin ticket y después adopta uno.
         *
         * Pasa cuando la sede no tenía colas activas: la fórmula se guarda
         * igual y Orientación ofrece completarle el turno más tarde. Ese
         * segundo paso es un `update`, no un `create`, así que no vuelve a
         * pasar por el enganche de arriba y la transcripción se quedaría
         * apuntando a ningún ticket.
         *
         * Transcripción ya es defensiva —la orden de entrega y la pantalla de
         * revisión caen de vuelta al ticket del soporte—, pero su bandeja
         * muestra la columna del turno vacía, y la transcriptora no sabría a
         * qué paciente de la fila pertenece lo que está revisando.
         *
         * Se actualiza en masa a propósito: `ticket_id` no está en
         * `Transcripcion::CAMPOS_AUDITADOS`, y esto es una corrección del
         * enlace, no una decisión de nadie que haya que registrar.
         */
        Soporte::updated(function (Soporte $soporte): void {
            if (! $soporte->wasChanged('ticket_id') || $soporte->ticket_id === null) {
                return;
            }

            Transcripcion::query()
                ->where('soporte_id', $soporte->getKey())
                // Nunca se le quita el ticket a una que ya tenía otro.
                ->whereNull('ticket_id')
                ->update([
                    'ticket_id' => $soporte->ticket_id,
                    'sede_id' => $soporte->ticket?->sede_id,
                ]);
        });

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
