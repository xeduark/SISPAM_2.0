{{--
    El ticket impreso que se lleva el paciente. Impresora térmica de 80 mm.

    Tres cosas que conviene leer antes de tocar esto:

    1. **El CSS va embebido.** El panel no compila Tailwind (ver CLAUDE.md), y
       además una vista de impresión que dependa de un build es una vista que
       un día sale en blanco.

    2. **Todo en monospace y mayúsculas.** No es capricho: es lo que imprime
       limpio en una térmica y lo que se lee de lejos en el mostrador.

    3. **Lo clínico no entra.** El nombre del paciente y la prioridad sí van
       —el paciente tiene que reconocer su papel—, pero los medicamentos, el
       nombre de la cola (que delataría alto costo y oncológicos) y el motivo
       de la prioridad no. Un ticket se queda en un mostrador y lo recoge
       cualquiera. Hay pruebas que fallan si alguno se asoma.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket {{ $ticket->turno }}</title>
    <style>
        /* 80 mm de papel; el alto lo decide el contenido. */
        @page {
            size: 80mm auto;
            margin: 0;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #000000;
            /* Monospace: es lo que imprime limpio en una térmica. */
            font-family: "Courier New", Courier, monospace;
            font-weight: 700;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .ticket {
            width: 72mm;           /* 80 mm de papel menos los márgenes del cabezal */
            margin: 0 auto;
            padding: 5mm 2mm 6mm;
            text-align: center;
            text-transform: uppercase;
        }

        /* Separador de guiones, como en el papel de verdad. */
        .corte {
            font-size: 12pt;
            line-height: 1;
            letter-spacing: 1px;
            overflow: hidden;
            white-space: nowrap;
            margin: 3mm 0;
        }

        .sede {
            font-size: 12pt;
            line-height: 1.3;
            letter-spacing: 1px;
        }

        /*
           El turno: lo único que se mira de lejos, así que ocupa todo el
           ancho del papel. El tamaño lo calcula
           `TicketImpresionController::tamanoDelTurno()` a partir de cuántos
           caracteres tenga —«A-0001» y «AC-10000» no miden igual—, y por eso
           aquí el `letter-spacing` va en cero: cualquier separación extra
           rompería esa cuenta.
        */
        .turno {
            line-height: 1;
            letter-spacing: 0;
            white-space: nowrap;
            margin: 4mm 0;
        }

        .numero {
            font-size: 10.5pt;
            letter-spacing: 1px;
        }

        .rotulo {
            font-size: 9pt;
            letter-spacing: 2px;
            margin-top: 3mm;
        }

        .paciente {
            font-size: 12pt;
            line-height: 1.3;
            letter-spacing: 0.5px;
            margin-top: 1mm;
        }

        .dato {
            font-size: 10.5pt;
            letter-spacing: 1px;
            margin-top: 1.5mm;
        }

        /* La caja de la alerta, con borde de guiones. */
        .alerta {
            border: 2px dashed #000000;
            padding: 2.5mm 2mm;
            margin-top: 4mm;
            font-size: 11pt;
            line-height: 1.35;
            letter-spacing: 0.5px;
        }

        /* Solo en pantalla: el botón de reimprimir. */
        .acciones { margin-top: 6mm; }

        .acciones button {
            font-family: inherit;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #ffffff;
            background: #1f4e79;
            border: 0;
            border-radius: 8px;
            padding: 10px 18px;
            cursor: pointer;
        }

        @media print {
            .acciones { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="sede">SEDE: {{ $sede }}</div>

        <div class="corte">------------------------------</div>

        <div class="turno" style="font-size: {{ $tamanoTurno }}pt">{{ $ticket->turno }}</div>

        <div class="corte">------------------------------</div>

        <div class="numero">{{ $ticket->numero }}</div>

        <div class="rotulo">PACIENTE</div>
        <div class="paciente">{{ $paciente }}</div>

        <div class="corte">------------------------------</div>

        <div class="dato">{{ $ticket->fecha?->format('d/m/Y') }} {{ $ticket->created_at?->format('h:i A') }}</div>
        {{-- La prioridad sí; el motivo («gestante», «discapacidad») no: eso es
             un dato de salud y no tiene por qué ir en un papel suelto. --}}
        <div class="dato">PRIORIDAD: {{ $prioridad }}</div>

        {{--
            Aquí va el código de barras cuando se decida que hay escáner en el
            ingreso: las barras de `$ticket->numero` y, debajo, el número otra
            vez como leyenda. Se agrega este bloque y nada más.
        --}}

        <div class="alerta">ALERTA DE INGRESO<br>{{ $sedeNombre }}</div>

        <div class="acciones">
            <button type="button" onclick="window.print()">Imprimir de nuevo</button>
        </div>
    </div>

    <script>
        // El diálogo de impresión se abre solo: quien llega aquí viene de
        // pulsar «Imprimir ticket», no a mirar la página.
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
