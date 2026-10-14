{{--
    Reloj de inactividad de las cuentas compartidas, el del navegador.

    Cuando se cumple el plazo sin peticiones, la pantalla cierra sesión sola:
    así el último paciente consultado no queda a la vista en un equipo que usa
    más de una persona, y la sesión de Authentik también se cierra
    (`LogoutResponse`). El reloj del servidor, `CerrarCuentaCompartidaInactiva`,
    mide lo mismo y es la garantía si este no corrió (equipo suspendido).

    Cuenta como uso cada petición, igual que el servidor. La hora del último
    uso se comparte entre pestañas: si no, una pestaña quieta cerraría la
    sesión de otra que sí se está usando.
--}}
@php($cierre = \App\Http\Middleware\CerrarCuentaCompartidaInactiva::class)

<form id="sispam-cierre-por-inactividad" method="POST" action="{{ filament()->getLogoutUrl() }}" hidden>
    @csrf
    <input type="hidden" name="motivo" value="{{ $cierre::MOTIVO }}">
</form>

<script>
    (() => {
        const limite = {{ $cierre::minutos() }} * 60 * 1000;
        const clave = 'sispam.cuenta-compartida.ultimo-uso';
        let ultimoUso = Date.now();
        let cerrando = false;

        const marcarUso = () => {
            ultimoUso = Date.now();
            try { localStorage.setItem(clave, String(ultimoUso)); } catch (e) {}
        };

        const ultimoUsoEnCualquierPestana = () => {
            let compartido = 0;
            try { compartido = Number(localStorage.getItem(clave)) || 0; } catch (e) {}

            return Math.max(ultimoUso, compartido);
        };

        marcarUso();

        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({ fail }) => {
                marcarUso();

                // El servidor ya dio la sesión por vencida: se recarga la
                // página, y esa recarga cierra todo, Authentik incluido.
                fail(({ status, preventDefault }) => {
                    if (status === 419) {
                        preventDefault();
                        window.location.reload();
                    }
                });
            });
        });

        setInterval(() => {
            if (cerrando || Date.now() - ultimoUsoEnCualquierPestana() < limite) {
                return;
            }

            cerrando = true;
            document.getElementById('sispam-cierre-por-inactividad').submit();
        }, 15000);
    })();
</script>
