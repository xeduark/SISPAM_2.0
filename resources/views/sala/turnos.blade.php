{{--
    Pantalla de la sala de espera (fase 6).

    Pública a propósito: un televisor no inicia sesión. Solo muestra turno y
    ventanilla; el dato que se publique aquí lo ve toda la sala, así que nunca
    se agregan nombres, documentos ni medicamentos.

    Paleta y tipografía: estilos_generador_consolidado.md (tema oscuro, que es
    el que se lee de lejos en un televisor).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Turnos — {{ $sede->nombre }}</title>
    <link rel="icon" href="{{ asset('img/favicon.svg') }}">
    <style>
        :root {
            --bg: #101417;
            --card: #171d21;
            --card-soft: #1d252a;
            --border: #303a40;
            --text: #edf2f4;
            --muted: #aab5bc;
            --primary: #4d8ac4;
            --warning: #ecc94b;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.28);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 20px 32px;
            border-bottom: 1px solid var(--border);
            background: var(--card);
        }

        .marca {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .marca__institucion {
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: 0.5px;
            color: var(--muted);
        }

        .marca__divisor {
            width: 2px;
            height: 44px;
            background: var(--border);
        }

        .marca__titulo { font-size: 22px; font-weight: 700; }
        .marca__sede { font-size: 15px; color: var(--muted); }

        .reloj { font-size: 30px; font-weight: 700; font-variant-numeric: tabular-nums; }

        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 32px;
            text-align: center;
        }

        .rotulo {
            font-size: 22px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--muted);
        }

        .turno {
            font-size: clamp(90px, 22vw, 260px);
            font-weight: 700;
            line-height: 1;
            color: var(--primary);
        }

        .turno--preferencial { color: var(--warning); }

        .ventanilla {
            font-size: clamp(28px, 6vw, 64px);
            font-weight: 700;
        }

        .etiqueta-preferencial {
            font-size: 20px;
            color: var(--warning);
        }

        .vacio { font-size: clamp(26px, 4vw, 44px); color: var(--muted); }

        /* Un parpadeo corto cuando cambia el turno, para que se note de lejos. */
        .destello { animation: destello 1.6s ease-out 2; }

        @keyframes destello {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.25; }
        }

        footer {
            border-top: 1px solid var(--border);
            background: var(--card);
            padding: 18px 32px;
        }

        .anteriores {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: center;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .anteriores li {
            flex: 1 1 160px;
            max-width: 240px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--card-soft);
            box-shadow: var(--shadow);
            padding: 12px 16px;
        }

        .anteriores__turno { font-size: 30px; font-weight: 700; }
        .anteriores__detalle { font-size: 15px; color: var(--muted); }

        .aviso-sonido {
            position: fixed;
            right: 18px;
            bottom: 18px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--card-soft);
            color: var(--muted);
            font-family: inherit;
            font-size: 14px;
            padding: 10px 14px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <header>
        <div class="marca">
            <div class="marca__institucion">COMITÉ DE<br>ESTUDIOS<br>MÉDICOS</div>
            <div class="marca__divisor"></div>
            <div>
                <div class="marca__titulo">SISPAM 2</div>
                <div class="marca__sede">{{ $sede->nombre }}</div>
            </div>
        </div>

        <div class="reloj" id="reloj"></div>
    </header>

    <main id="actual" aria-live="polite">
        {{-- Lo pinta el script; esto es lo que se ve mientras llega el primer dato. --}}
        <div class="vacio">Esperando el próximo turno…</div>
    </main>

    <footer>
        <ul class="anteriores" id="anteriores"></ul>
    </footer>

    <button class="aviso-sonido" id="aviso-sonido" type="button">🔔 Activar aviso</button>

    <script>
        const URL_TURNOS = @json(route('sala.turnos', ['sede' => $sede->codigo]));
        const PRIMEROS = @json($turnos);

        const actual = document.getElementById('actual');
        const anteriores = document.getElementById('anteriores');
        const botonSonido = document.getElementById('aviso-sonido');

        let ultimoTurno = null;
        let sonidoActivo = false;
        let audio = null;

        const escapar = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        function reloj() {
            document.getElementById('reloj').textContent = new Date()
                .toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
        }

        /* Un pito corto cuando cambia el turno. El navegador no deja sonar nada
           hasta que alguien toca la pantalla, así que hay botón para activarlo. */
        function pitar() {
            if (!sonidoActivo || !audio) {
                return;
            }

            const oscilador = audio.createOscillator();
            const volumen = audio.createGain();

            oscilador.frequency.value = 880;
            volumen.gain.value = 0.25;
            oscilador.connect(volumen).connect(audio.destination);
            oscilador.start();
            oscilador.stop(audio.currentTime + 0.35);
        }

        botonSonido.addEventListener('click', () => {
            try {
                audio = audio || new (window.AudioContext || window.webkitAudioContext)();
                audio.resume();
                sonidoActivo = true;
                botonSonido.textContent = '🔔 Aviso activado';
                pitar();
            } catch (e) {
                botonSonido.textContent = 'Este navegador no deja sonar el aviso';
            }
        });

        function pintar(turnos) {
            const llamado = turnos[0] ?? null;

            if (!llamado) {
                actual.innerHTML = '<div class="vacio">Esperando el próximo turno…</div>';
                anteriores.innerHTML = '';
                return;
            }

            const cambio = llamado.turno !== ultimoTurno;
            ultimoTurno = llamado.turno;

            actual.innerHTML = `
                <div class="rotulo">Turno</div>
                <div class="turno ${llamado.preferencial ? 'turno--preferencial' : ''} ${cambio ? 'destello' : ''}">
                    ${escapar(llamado.turno)}
                </div>
                <div class="ventanilla">${escapar(llamado.ventanilla ?? 'Ventanilla')}</div>
                ${llamado.preferencial ? '<div class="etiqueta-preferencial">Atención preferencial</div>' : ''}
            `;

            anteriores.innerHTML = turnos.slice(1).map((t) => `
                <li>
                    <div class="anteriores__turno">${escapar(t.turno)}</div>
                    <div class="anteriores__detalle">${escapar(t.ventanilla ?? '—')} · ${escapar(t.hora)}</div>
                </li>
            `).join('');

            if (cambio) {
                pitar();
            }
        }

        async function actualizar() {
            try {
                const respuesta = await fetch(URL_TURNOS, { headers: { Accept: 'application/json' } });

                if (!respuesta.ok) {
                    return;
                }

                const datos = await respuesta.json();
                pintar(datos.turnos ?? []);
            } catch (e) {
                // La pantalla se queda con lo último que alcanzó a mostrar.
            }
        }

        reloj();
        setInterval(reloj, 10000);

        pintar(PRIMEROS);
        setInterval(actualizar, 5000);
    </script>
</body>
</html>
