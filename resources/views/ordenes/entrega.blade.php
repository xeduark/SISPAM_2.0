@php
    $p = $ticket->paciente;
    $fmt = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f)->format('d/m/Y') : 'NO REGISTRA';
    $cie = fn ($f) => trim(($f['cie10_principal']['codigo'] ?? '').' '.($f['cie10_principal']['descripcion'] ?? '')) ?: 'NO REGISTRA';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orden {{ $ticket->numero }}</title>
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
        .tk { background: #111; color: #fff; border-radius: 10px; padding: 1px 8px; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 16px; margin: 10px 0; }
        .formulas { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
        .formula { border: 1px dashed #9ca3af; border-radius: 6px; padding: 6px 8px; font-size: 10px; }
        h3 { font-size: 11px; margin: 14px 0 6px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #111; padding: 4px 6px; vertical-align: top; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; text-align: left; }
        td.n { text-align: center; font-weight: 700; }
        .fo { float: right; font-size: 9px; color: #6b7280; }
        .check { width: 14px; height: 14px; border: 1.5px solid #111; border-radius: 3px; margin: auto; }
        .aviso { border: 2px solid #b91c1c; color: #b91c1c; padding: 6px 8px; border-radius: 6px; margin: 8px 0; font-weight: 700; }
        .nota { border: 1px solid #d1d5db; padding: 6px 8px; border-radius: 6px; margin: 8px 0; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 28px; text-align: center; }
        .firmas div { border-top: 2px solid #111; padding-top: 4px; }
        .barra { position: sticky; top: 0; background: #111; color: #fff; padding: 8px 16px; display: flex; gap: 12px; align-items: center; }
        .barra button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 0; cursor: pointer; }
        @media print { body { background: #fff; } .hoja { margin: 0; padding: 0; box-shadow: none; max-width: none; } .barra { display: none; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="barra">
    <button type="button" onclick="window.print()">Imprimir</button>
    <span>Orden de dispensación · {{ $ticket->numero }}</span>
</div>

<div class="hoja">
    <header>
        <img src="{{ config('empresa.logo') }}" alt="{{ config('empresa.nombre') }}">
        <div>
            <strong>{{ config('empresa.nombre') }}</strong><br>
            <span class="muted">NIT: {{ config('empresa.nit') }} · {{ $ticket->sede?->nombre }}{{ $ticket->sede?->telefono ? ' · Tel: '.$ticket->sede->telefono : '' }}</span>
        </div>
        <div class="titulo">
            <strong>ORDEN DE DISPENSACIÓN &amp; ALISTAMIENTO</strong><br>
            <span class="mono">TIQUETE: <span class="tk">{{ $ticket->numero }}</span></span>
            @if ($version > 1)<br><strong style="color:#b7791f">VERSIÓN {{ $version }} — RECTIFICADA</strong>@endif
        </div>
    </header>

    @if ($sinConfirmar > 0)
        <div class="aviso">BORRADOR: {{ $sinConfirmar }} fórmula(s) de este ticket todavía no están confirmadas. No alistar hasta que transcripción las confirme.</div>
    @endif

    <div class="grid">
        <div><div class="lbl">Paciente</div><strong>{{ $p?->nombre_completo }}</strong></div>
        <div><div class="lbl">Documento</div><span class="mono">{{ $p?->documento_completo }}</span></div>
        <div><div class="lbl">Aseguradora (EPS)</div>{{ config('empresa.eps') }}</div>
        <div><div class="lbl">Turno / fecha</div><span class="mono">{{ $ticket->turno }} · {{ $ticket->created_at?->format('d/m/Y h:i A') }}</span></div>
        <div><div class="lbl">Radicado por</div>{{ $ticket->creadoPor?->nombre ?? '—' }}</div>
        <div><div class="lbl">Transcrito y validado por</div>{{ $validadoPor ?: 'Pendiente' }}</div>
        <div><div class="lbl">Destino / ventanilla</div>{{ $ticket->ventanilla?->nombre ?? 'Por asignar' }}</div>
        <div><div class="lbl">Contacto</div>{{ $p?->telefono_movil ?? '—' }}</div>
    </div>

    <div class="lbl" style="margin-bottom:4px">Fórmulas de este turno ({{ count($formulas) }} {{ count($formulas) === 1 ? 'orden médica' : 'órdenes médicas independientes' }})</div>
    <div class="formulas">
        @foreach ($formulas as $f)
            <div class="formula">
                <strong>FÓRMULA #{{ $f['n'] }}: {{ $f['ips'] ?? 'IPS NO REGISTRA' }}</strong>
                @if (! empty($f['rechazada']))
                    <strong style="color:#b91c1c"> — NO SE DISPENSA: {{ \App\Models\Transcripcion::MOTIVOS_RECHAZO_FORMULA[$f['motivo_rechazo'] ?? ''] ?? '' }}{{ filled($f['detalle_rechazo'] ?? null) ? '. '.$f['detalle_rechazo'] : '' }}</strong>
                @endif
                <br>
                Médico: {{ $f['medico']['nombre'] ?? 'NO REGISTRA' }}{{ filled($f['medico']['registro_medico'] ?? null) ? ' (RM: '.$f['medico']['registro_medico'].')' : '' }}{{ filled($f['medico']['especialidad'] ?? null) ? ' · '.$f['medico']['especialidad'] : '' }}<br>
                CIE-10: <strong>{{ $cie($f) }}</strong> · MIPRES: {{ $f['mipres'] ?? 'NO REGISTRA' }} · Autorización: {{ $f['autorizacion'] ?? 'NO REGISTRA' }}<br>
                <span class="muted">Exp: {{ $fmt($f['fecha_expedicion'] ?? null) }} · Vence: {{ $fmt($f['vigencia'] ?? null) }}</span>
            </div>
        @endforeach
    </div>

    <h3>1. Para entregar en ventanilla {{ $stockConsultado ? '(hay existencias en la sede)' : '' }}</h3>
    @unless ($stockConsultado)
        <div class="nota">No se pudo consultar el inventario al imprimir: farmacia confirma existencias al alistar y anota lo que quede pendiente.</div>
    @endunless
    <table>
        <thead>
        <tr>
            <th style="width:22px">#</th>
            <th>Medicamento / producto</th>
            <th style="width:70px">Lote</th>
            <th style="width:62px">Vence</th>
            <th style="width:26%">Posología</th>
            <th style="width:48px">Cant.</th>
            <th style="width:40px">Check</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($ventanilla as $i => $l)
            <tr>
                <td class="n">{{ $i + 1 }}</td>
                <td>
                    <span class="fo mono">Fór. #{{ $l['formula'] ?? '?' }}</span>
                    <strong>{{ $l['producto'] }}</strong> <span class="mono muted">{{ $l['codigo'] }}</span><br>
                    <span class="muted">Rx: {{ $l['prescrito'] }}</span>
                    @if ($l['meses'])
                        <br><strong>Tratamiento {{ $l['meses'] }} {{ $l['meses'] == 1 ? 'mes' : 'meses' }} · entrega {{ $l['entrega_mes'] }} de {{ $l['meses'] }}</strong>
                        @if ($l['saldo'] !== null) <span class="muted">· saldo después: {{ $l['saldo'] }}</span> @endif
                    @endif
                </td>
                <td></td>
                <td></td>
                <td>{{ $l['posologia'] ?: 'Según fórmula' }}</td>
                <td class="n mono">{{ $l['entrega'] ?? $l['cantidad'] }}</td>
                <td><div class="check"></div></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center">Nada para entregar en ventanilla.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="muted" style="margin-top:4px">{{ $ventanilla->count() }} medicamento(s). Lote y vencimiento los anota quien alista (el que vence primero).</div>

    @if ($pendientes->isNotEmpty())
        <h3>2. Pendiente: sin existencias en la sede (envío a domicilio)</h3>
        <div class="nota">
            Dirección: <strong>{{ $p?->direccion ?? 'NO REGISTRA' }}</strong>{{ $p?->barrio ? ', '.$p->barrio : '' }}{{ $p?->ciudad_residencia ? ' · '.$p->ciudad_residencia : '' }}
            · Tel: <strong>{{ $p?->telefono_movil ?? 'NO REGISTRA' }}</strong>
            @if (! $p?->contacto_confirmado_at) <strong style="color:#b91c1c">· Confirmar dirección y teléfono con el paciente</strong> @endif
        </div>
        <table>
            <thead><tr><th style="width:22px">#</th><th>Medicamento / producto</th><th style="width:26%">Posología</th><th style="width:60px">Pendiente</th></tr></thead>
            <tbody>
            @foreach ($pendientes as $i => $l)
                <tr>
                    <td class="n">{{ $i + 1 }}</td>
                    <td><span class="fo mono">Fór. #{{ $l['formula'] ?? '?' }}</span><strong>{{ $l['producto'] }}</strong> <span class="mono muted">{{ $l['codigo'] }}</span></td>
                    <td>{{ $l['posologia'] ?: 'Según fórmula' }}</td>
                    <td class="n mono">{{ $l['falta'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($noDispensados->isNotEmpty())
        <h3>3. No se dispensan (se le informa al paciente)</h3>
        <table>
            <thead><tr><th style="width:22px">#</th><th>Medicamento como está en la fórmula</th><th style="width:38%">Motivo</th></tr></thead>
            <tbody>
            @foreach ($noDispensados as $i => $l)
                <tr>
                    <td class="n">{{ $i + 1 }}</td>
                    <td><span class="fo mono">Fór. #{{ $l['formula'] }}</span>{{ $l['prescrito'] }}</td>
                    <td><strong>{{ $l['motivo'] }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p style="margin-top:14px; font-size:9.5px">
        <strong>CONSTANCIA DE RECIBIDO A ENTERA SATISFACCIÓN:</strong> Certifico que recibí de {{ config('empresa.nombre') }} los
        medicamentos detallados en la sección 1, verificando cantidades, fechas de vencimiento vigentes e integridad de los empaques.
        Recibí información sobre su conservación y vía de administración según la prescripción médica.
    </p>

    <div class="firmas">
        <div><strong>RESPONSABLE DE ALISTAMIENTO / FARMACIA</strong><br>Nombre: ____________________ Firma: ____________</div>
        <div><strong>RECIBIDO A CONFORMIDAD (PACIENTE O ACUDIENTE)</strong><br>Firma: ______________ C.C.: ______________<br>Nombre: ______________ Tel: ______________</div>
    </div>

    <p class="muted mono" style="margin-top:14px; font-size:9px; border-top:1px solid #d1d5db; padding-top:4px">
        Impreso por {{ auth()->user()?->nombre }} · {{ now()->format('d/m/Y h:i A') }} · Las existencias son las del momento de imprimir.
    </p>
</div>
</body>
</html>
