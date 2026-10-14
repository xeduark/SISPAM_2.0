{{--
    Página de error de SISPAM, con el estilo institucional
    (estilos_generador_consolidado.md: Arial, paleta, tema claro y oscuro,
    encabezado, radios y sombras).

    La usan 401, 403, 404, 419, 429, 500, 503, 4xx y 5xx con un texto **fijo**
    para cada código: nunca el mensaje de la excepción, que en un 500 puede
    traer una consulta SQL, una ruta del servidor o un dato del paciente.

    CSS embebido y sin nada externo: si algo se rompió, esta página igual tiene
    que poder pintarse.

    Variables: $codigo, $titulo, $mensaje y, opcional, $sinBoton.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $codigo }} · SISPAM 2</title>
    <style>
        :root {
            --bg: #f3f5f7;
            --card: #ffffff;
            --border: #dfe4e8;
            --text: #1f2933;
            --muted: #66727d;
            --primary: #1f4e79;
            --primary-hover: #173b5c;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #101417;
                --card: #171d21;
                --border: #303a40;
                --text: #edf2f4;
                --muted: #aab5bc;
                --primary: #4d8ac4;
                --primary-hover: #619bd0;
                --shadow: 0 8px 25px rgba(0, 0, 0, 0.28);
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
        }

        .header {
            display: flex;
            align-items: center;
            gap: 22px;
            padding: 20px 30px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .brand {
            font-size: 15px;
            line-height: 1.15;
            letter-spacing: 4px;
            font-weight: 500;
            white-space: nowrap;
        }

        .brand-divider { width: 1px; height: 52px; background: var(--border); }

        .brand-text strong { display: block; font-size: 22px; line-height: 1.1; }
        .brand-text span { color: var(--muted); font-size: 14px; }

        main {
            display: flex;
            justify-content: center;
            padding: 48px 16px;
        }

        .tarjeta {
            width: 100%;
            max-width: 560px;
            padding: 32px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .codigo {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        h1 { margin: 16px 0 10px; font-size: 25px; line-height: 1.2; }

        p { margin: 0 0 8px; color: var(--muted); line-height: 1.5; }

        .hora { font-size: 13px; }

        a.boton {
            display: inline-block;
            margin-top: 20px;
            padding: 11px 20px;
            border-radius: 9px;
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s ease;
        }

        a.boton:hover { background: var(--primary-hover); }

        @media (max-width: 480px) {
            .header { padding: 16px; gap: 14px; }
            .brand { font-size: 12px; letter-spacing: 3px; }
            .tarjeta { padding: 24px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">COMITÉ DE<br>ESTUDIOS<br>MÉDICOS</div>
        <div class="brand-divider"></div>
        <div class="brand-text">
            <strong>SISPAM 2</strong>
            <span>Dispensación de Medicamentos</span>
        </div>
    </header>

    <main>
        <section class="tarjeta">
            <span class="codigo">ERROR {{ $codigo }}</span>
            <h1>{{ $titulo }}</h1>
            <p>{{ $mensaje }}</p>
            {{-- La hora ayuda a encontrar el error en el log si alguien lo reporta. --}}
            <p class="hora">{{ now()->format('d/m/Y h:i a') }}</p>

            @unless ($sinBoton ?? false)
                <a class="boton" href="{{ url('/admin') }}">Volver al inicio</a>
            @endunless
        </section>
    </main>
</body>
</html>
