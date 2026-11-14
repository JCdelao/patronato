@php
    use Carbon\CarbonImmutable;

    $lempiras = fn (string $monto): string => 'L '.number_format((float) $monto, 2, '.', ',');
    $fecha = fn (?string $valor): string => $valor === null ? '—' : CarbonImmutable::parse($valor)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    $porMes = $reporte->filtros['agrupacion'] === 'mes';
    $periodo = fn (string $valor): string => $porMes
        ? ucfirst(CarbonImmutable::parse($valor.'-01')->locale('es')->isoFormat('MMMM [de] YYYY'))
        : CarbonImmutable::parse($valor)->locale('es')->isoFormat('ddd D [de] MMM YYYY');
    $estados = ['abierta' => 'Abiertas', 'cerrada' => 'Cerradas'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cobros por cobrador</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 20px 0 6px; }
        .filtros { color: #52606d; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #f0f4f8; padding: 6px; }
        td { padding: 5px 6px; border-bottom: 1px solid #d9e2ec; }
        .derecha { text-align: right; }
        .subtotal td { font-weight: bold; background: #f8fafc; }
        .total td { font-weight: bold; font-size: 12px; border-bottom: none; }
        .anulado td { color: #9aa5b1; }
    </style>
</head>
<body>
    <h1>Cobros en efectivo por cobrador</h1>
    <div class="filtros">
        Del {{ $fecha($reporte->filtros['desde']) }} al {{ $fecha($reporte->filtros['hasta']) }}
        · Cobrador: {{ $reporte->filtros['cobrador'] ?? 'Todos' }}
        · Cajas: {{ $estados[$reporte->filtros['estado_caja'] ?? ''] ?? 'Todas' }}
        · Agrupado por {{ $porMes ? 'mes' : 'día' }}
    </div>

    <h2>Resumen</h2>
    <table>
        <thead>
            <tr>
                <th>Cobrador</th>
                <th>{{ $porMes ? 'Mes' : 'Día' }}</th>
                <th class="derecha">Cobros</th>
                <th class="derecha">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reporte->subtotales as $subtotal)
                @foreach ($reporte->resumen as $fila)
                    @if ($fila['cobrador_id'] === $subtotal['cobrador_id'])
                        <tr>
                            <td>{{ $fila['cobrador'] }}</td>
                            <td>{{ $periodo($fila['periodo']) }}</td>
                            <td class="derecha">{{ $fila['cobros'] }}</td>
                            <td class="derecha">{{ $lempiras($fila['total']) }}</td>
                        </tr>
                    @endif
                @endforeach
                <tr class="subtotal">
                    <td colspan="2">Subtotal {{ $subtotal['cobrador'] }}</td>
                    <td class="derecha">{{ $subtotal['cobros'] }}</td>
                    <td class="derecha">{{ $lempiras($subtotal['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No hay cobros con estos filtros.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">Total</td>
                <td class="derecha">{{ $reporte->totalCobros }}</td>
                <td class="derecha">{{ $lempiras($reporte->total) }}</td>
            </tr>
            @if ($reporte->anulados > 0)
                <tr class="anulado">
                    <td colspan="2">Anulados (no suman)</td>
                    <td class="derecha">{{ $reporte->anulados }}</td>
                    <td class="derecha">{{ $lempiras($reporte->montoAnulado) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($reporte->detalle !== [])
        <h2>Detalle de cobros</h2>
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Recibo</th>
                    <th>Cobrador</th>
                    <th>Vivienda</th>
                    <th>Estado</th>
                    <th>Caja</th>
                    <th class="derecha">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reporte->detalle as $cobro)
                    <tr @class(['anulado' => $cobro['estado'] !== 'confirmado'])>
                        <td>{{ $cobro['fecha_pago'] }}</td>
                        <td>{{ $cobro['numero_recibo'] }}</td>
                        <td>{{ $cobro['cobrador'] }}</td>
                        <td>{{ $cobro['vivienda'] }}</td>
                        <td>{{ $cobro['estado'] }}</td>
                        <td>{{ $cobro['estado_caja'] }}</td>
                        <td class="derecha">{{ $lempiras($cobro['monto']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
