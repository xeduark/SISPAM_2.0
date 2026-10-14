{{--
    Errores de Livewire en producción (APP_DEBUG=false).

    En Filament casi todo es una petición de Livewire, y Livewire pide JSON:
    si algo falla, por defecto pinta un recuadro con lo que respondió el
    servidor, que en producción es `{"message":"Server Error"}`, en inglés y
    sin decir qué hacer. Aquí se cambia por una notificación en español.

    - 419 (sesión vencida): se recarga la página, que lleva al ingreso.
    - Lo demás: una notificación con qué hacer, nunca el detalle del error,
      que queda en el log.

    En desarrollo no se carga: el recuadro de depuración de Livewire sirve.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const mensajes = {
            403: ['No tienes permiso para hacer esto', 'Si crees que deberías poder, pide a un administrador que revise tus permisos.'],
            404: ['Eso ya no existe', 'Recarga la página e inténtalo de nuevo.'],
            429: ['Demasiadas solicitudes', 'Espera un momento y vuelve a intentarlo.'],
            503: ['No se pudo completar', 'SISPAM está en mantenimiento o no hay conexión. Inténtalo en unos minutos.'],
        };

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                preventDefault();

                if (status === 419) {
                    window.location.reload();

                    return;
                }

                const [titulo, detalle] = mensajes[status]
                    ?? ['Algo falló', 'El error quedó registrado. Vuelve a intentarlo; si sigue pasando, avisa a un administrador.'];

                new FilamentNotification().title(titulo).body(detalle).danger().send();
            });
        });
    });
</script>
