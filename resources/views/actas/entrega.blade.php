@php
    $p = $entrega->paciente;
    $entregados = $entrega->items->where('cantidad_entregada', '>', 0)->values();
    $pendientes = $entrega->items->where('cantidad_pendiente', '>', 0)->values();
    $esDomicilio = $entrega->tipo === \App\Models\Entrega::TIPO_DOMICILIO;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acta de entrega #{{ $entrega->id }}</title>
    <style>
        @page { size: letter; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font: 11px/1.35 "Segoe UI", Arial, sans-serif; color: #111; margin: 0; background: #f3f4f6; }
        .hoja { max-width: 216mm; margin: 16px auto; background: #fff; padding: 14mm; box-shadow: 0 1px 4px #0002; }
        .mono { font-family: Consolas, "Courier New", monospace; }
        .muted { color: #6b7280; }
        .lbl { font-size: 9px; font-weight: 700; color: #6b7280; text-transform: uppercase; }
        header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #111; padding-bottom: 8px; }
        header img { height: 38px; }
        header .titulo { margin-left: auto; text-align: right; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 16px; margin: 10px 0; }
        h3 { font-size: 11px; margin: 14px 0 6px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #111; padding: 4px 6px; vertical-align: top; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; text-align: left; }
        td.n { text-align: center; font-weight: 700; }
        .aviso { border: 2px solid #b91c1c; color: #b91c1c; padding: 6px 8px; border-radius: 6px; margin: 8px 0; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 18px; text-align: center; }
        .firma { border-top: 2px solid #111; padding-top: 4px; }
        .firma img { max-height: 70px; display: block; margin: 0 auto 4px; }
        .barra { position: sticky; top: 0; background: #111; color: #fff; padding: 8px 16px; display: flex; gap: 12px; align-items: center; }
        .barra button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 0; cursor: pointer; }
        @media print { body { background: #fff; } .hoja { margin: 0; padding: 0; box-shadow: none; max-width: none; } .barra { display: none; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="barra">
    <button type="button" onclick="window.print()">Imprimir</button>
    <span>Acta de entrega #{{ $entrega->id }} · {{ $entrega->ticket_numero }}</span>
</div>

<div class="hoja">
    <header>
        <img src="{{ config('empresa.logo') }}" alt="{{ config('empresa.nombre') }}">
        <div>
            <strong>{{ config('empresa.nombre') }}</strong><br>
            <span class="muted">NIT: {{ config('empresa.nit') }} · {{ $entrega->sede?->nombre }}</span>
        </div>
        <div class="titulo">
            <strong>ACTA DE ENTREGA DE MEDICAMENTOS{{ $esDomicilio ? ' A DOMICILIO' : '' }}</strong><br>
            <span class="mono">N° {{ str_pad((string) $entrega->id, 6, '0', STR_PAD_LEFT) }} · TIQUETE {{ $entrega->ticket_numero }}</span>
        </div>
    </header>

    <div class="grid">
        <div><div class="lbl">Paciente</div><strong>{{ $p?->nombre_completo }}</strong></div>
        <div><div class="lbl">Documento</div><span class="mono">{{ $p?->documento_completo }}</span></div>
        <div><div class="lbl">Aseguradora (EPS)</div>{{ config('empresa.eps') }}</div>
        <div><div class="lbl">Fecha y hora</div><span class="mono">{{ $entrega->created_at?->format('d/m/Y h:i A') }}</span></div>
        <div><div class="lbl">Recibe</div>{{ $entrega->receptor_nombre }} <span class="mono muted">{{ $entrega->receptor_documento }}</span></div>
        <div><div class="lbl">Parentesco</div>{{ $entrega->receptor_parentesco ?? '—' }}</div>
        <div><div class="lbl">Entregado por</div>{{ $entrega->usuario?->nombre }}</div>
        <div><div class="lbl">Estado</div>{{ ucfirst($entrega->estado) }}</div>
    </div>

    <h3>1. Medicamentos entregados</h3>
    @if ($lotes === null)
        <div class="aviso">No se pudo consultar el inventario: los lotes no aparecen en esta impresión.</div>
    @endif
    <table>
        <thead><tr><th style="width:22px">#</th><th>Medicamento</th><th style="width:110px">Lote</th><th style="width:70px">Vence</th><th style="width:55px">Cant.</th></tr></thead>
        <tbody>
        @forelse ($entregados as $i => $item)
            @php($deEste = $lotes?->get($item->codigo, collect()) ?? collect())
            <tr>
                <td class="n">{{ $i + 1 }}</td>
                <td><strong>{{ $item->nombre }}</strong> <span class="mono muted">{{ $item->codigo }}</span></td>
                <td class="mono">{!! $deEste->pluck('numero_lote')->map(fn ($l) => e($l))->implode('<br>') ?: '—' !!}</td>
                <td class="mono">{!! $deEste->pluck('fecha_vencimiento')->map(fn ($f) => \Illuminate\Support\Carbon::parse($f)->format('d/m/Y'))->implode('<br>') ?: '—' !!}</td>
                <td class="n mono">{{ rtrim(rtrim(number_format($item->cantidad_entregada, 2, '.', ''), '0'), '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted" style="text-align:center">No se entregó ningún medicamento en esta atención.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($pendientes->isNotEmpty())
        <h3>2. Quedan pendientes (se entregan después{{ $p?->direccion ? ', a domicilio: '.$p->direccion : '' }})</h3>
        <table>
            <thead><tr><th style="width:22px">#</th><th>Medicamento</th><th style="width:55px">Pendiente</th><th style="width:35%">Motivo</th></tr></thead>
            <tbody>
            @foreach ($pendientes as $i => $item)
                <tr>
                    <td class="n">{{ $i + 1 }}</td>
                    <td>{{ $item->nombre }} <span class="mono muted">{{ $item->codigo }}</span></td>
                    <td class="n mono">{{ rtrim(rtrim(number_format($item->cantidad_pendiente, 2, '.', ''), '0'), '.') }}</td>
                    <td>{{ $item->motivo ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($noDispensados->isNotEmpty())
        <h3>3. No se dispensan, y por qué</h3>
        <table>
            <thead><tr><th style="width:22px">#</th><th>Medicamento como está en la fórmula</th><th style="width:25%">Fórmula</th><th style="width:30%">Motivo</th></tr></thead>
            <tbody>
            @foreach ($noDispensados as $i => $l)
                <tr>
                    <td class="n">{{ $i + 1 }}</td>
                    <td>{{ $l['prescrito'] }}</td>
                    <td>#{{ $l['formula'] }} {{ $l['ips'] }}</td>
                    <td><strong>{{ $l['motivo'] }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p style="margin-top:14px; font-size:9.5px">
        <strong>CONSTANCIA:</strong> Certifico que recibí de {{ config('empresa.nombre') }} los medicamentos de la sección 1, verificando
        cantidades, fechas de vencimiento e integridad de los empaques, y que recibí información sobre su conservación y uso.
        @if ($pendientes->isNotEmpty())
            Fui informado de los medicamentos que quedan pendientes (sección 2) y de cómo se me entregarán.
        @endif
        @if ($noDispensados->isNotEmpty())
            Fui informado de los medicamentos que no se dispensan y del motivo (sección 3).
        @endif
    </p>

    <div class="firmas">
        <div>
            <div class="firma">
                <strong>ENTREGA (FARMACIA)</strong><br>
                {{ $entrega->usuario?->nombre }}
            </div>
        </div>
        <div>
            @if ($firma)
                <img src="{{ $firma }}" alt="Firma de quien recibe" style="max-height:70px; display:block; margin:0 auto 4px">
            @endif
            <div class="firma">
                <strong>RECIBE (PACIENTE O ACUDIENTE)</strong><br>
                @if ($firma)
                    {{ $entrega->receptor_nombre }} · C.C. {{ $entrega->receptor_documento }}
                @elseif ($esDomicilio)
                    Firma al recibir el domicilio (guía {{ $entrega->domicilioEnvio?->referencia_externa ?? 'pendiente' }})
                @else
                    Firma: ______________ C.C.: ______________
                @endif
            </div>
        </div>
    </div>

    <p class="muted mono" style="margin-top:14px; font-size:9px; border-top:1px solid #d1d5db; padding-top:4px">
        Impreso por {{ auth()->user()?->nombre }} · {{ now()->format('d/m/Y h:i A') }}
    </p>
</div>
</body>
</html>
