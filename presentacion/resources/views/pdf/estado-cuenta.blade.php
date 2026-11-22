@php
    use Carbon\CarbonImmutable;

    $lempiras = fn (?string $monto): string => $monto === null ? '' : 'L '.number_format((float) $monto, 2, '.', ',');
    $fecha = fn (string $valor): string => CarbonImmutable::parse($valor)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    $periodo = fn (string $valor): string => ucfirst(CarbonImmutable::parse($valor)->locale('es')->isoFormat('MMMM [de] YYYY'));
    $formas = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estado de cuenta {{ $vivienda['codigo'] }}</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
        .encabezado { width: 100%; border-bottom: 2px solid #1f2933; padding-bottom: 10px; }
        .encabezado td { vertical-align: middle; }
        .logo { max-height: 60px; max-width: 120px; }
        .residencial { font-size: 15px; font-weight: bold; }
        .secundario { color: #52606d; }
        .titulo { text-align: right; }
        .titulo h1 { font-size: 15px; margin: 0; }
        .datos { width: 100%; margin-top: 14px; border-collapse: collapse; }
        .datos th { text-align: left; width: 22%; padding: 3px 0; color: #52606d; font-weight: normal; }
        .datos td { padding: 3px 0; font-weight: bold; }
        .saldos { width: 100%; margin-top: 12px; border-collapse: collapse; }
        .saldos td { width: 33%; padding: 8px; border: 1px solid #d9e2ec; }
        .saldos .valor { font-size: 13px; font-weight: bold; }
        .movimientos { width: 100%; margin-top: 14px; border-collapse: collapse; }
        .movimientos th { text-align: left; background: #f0f4f8; padding: 5px; }
        .movimientos td { padding: 5px; border-bottom: 1px solid #d9e2ec; vertical-align: top; }
        .derecha { text-align: right; }
        .detalle { color: #52606d; }
        .vacio { margin-top: 14px; color: #52606d; }
        .pie { margin-top: 20px; color: #52606d; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            @if ($logo)
                <td style="width: 130px;"><img class="logo" src="{{ $logo }}" alt="Logo"></td>
            @endif
            <td>
                <div class="residencial">{{ $residencial['nombre'] }}</div>
                @if ($residencial['direccion'])
                    <div class="secundario">{{ $residencial['direccion'] }}</div>
                @endif
                @if ($residencial['telefono'])
                    <div class="secundario">Tel. {{ $residencial['telefono'] }}</div>
                @endif
                @if ($residencial['correo'])
                    <div class="secundario">{{ $residencial['correo'] }}</div>
                @endif
            </td>
            <td class="titulo">
                <h1>Estado de cuenta</h1>
                <div class="secundario">Emitido el {{ $emitido }}</div>
            </td>
        </tr>
    </table>

    <table class="datos">
        <tr><th>Vivienda</th><td>{{ $vivienda['codigo'] }}{{ $vivienda['activa'] ? '' : ' (inactiva)' }}</td></tr>
        <tr><th>Responsable de pago</th><td>{{ $vivienda['responsable'] ?? 'Sin responsable de pago' }}</td></tr>
        <tr><th>Período consultado</th><td>Del {{ $fecha($desde) }} al {{ $fecha($hasta) }}</td></tr>
    </table>

    <table class="saldos">
        <tr>
            <td>Saldo inicial del período<div class="valor">{{ $lempiras($saldo_inicial) }}</div></td>
            <td>Saldo final del período<div class="valor">{{ $lempiras($saldo_final) }}</div></td>
            <td>Saldo actual<div class="valor">{{ $lempiras($saldo_oficial) }}</div></td>
        </tr>
    </table>

    @if ($movimientos === [])
        <p class="vacio">No hay movimientos en el período consultado.</p>
    @else
        <table class="movimientos">
            <thead>
                <tr>
                    <th style="width: 15%;">Fecha</th>
                    <th>Movimiento</th>
                    <th class="derecha" style="width: 13%;">Debe</th>
                    <th class="derecha" style="width: 13%;">Haber</th>
                    <th class="derecha" style="width: 14%;">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movimientos as $movimiento)
                    <tr>
                        <td>{{ $movimiento['fecha'] }}</td>
                        <td>
                            @if ($movimiento['clase'] === 'cargo')
                                {{ $movimiento['concepto'] }} · {{ $periodo($movimiento['periodo']) }}
                            @elseif ($movimiento['clase'] === 'ajuste')
                                Ajuste · {{ $movimiento['concepto'] }}
                                <div class="detalle">{{ $movimiento['motivo'] }}</div>
                            @else
                                {{ $movimiento['tipo'] === 'abono' ? 'Abono' : 'Pago' }} · {{ $formas[$movimiento['forma_pago']] ?? $movimiento['forma_pago'] }}
                                @if ($movimiento['numero_recibo'])
                                    · Recibo N.º {{ $movimiento['numero_recibo'] }}
                                @endif
                                @if ($movimiento['referencia'])
                                    <div class="detalle">{{ $movimiento['referencia'] }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="derecha">{{ $lempiras($movimiento['debe']) }}</td>
                        <td class="derecha">{{ $lempiras($movimiento['haber']) }}</td>
                        <td class="derecha">{{ $lempiras($movimiento['saldo']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="pie">Un saldo negativo es dinero a favor de la vivienda. Los cargos tienen la fecha del primer día de su período.</p>
</body>
</html>
