@php
    use Carbon\CarbonImmutable;

    $lempiras = fn (string $monto): string => 'L '.number_format((float) $monto, 2, '.', ',');
    $fecha = fn (string $valor): string => CarbonImmutable::parse($valor)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    $mes = fn (?string $valor): string => $valor === null ? '—' : ucfirst(CarbonImmutable::parse($valor)->locale('es')->isoFormat('MMMM YYYY'));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de morosidad</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .filtros { color: #52606d; margin-bottom: 12px; }
        .resumen { margin-bottom: 12px; }
        .resumen span { margin-right: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #f0f4f8; padding: 5px; }
        td { padding: 4px 5px; border-bottom: 1px solid #d9e2ec; }
        .derecha { text-align: right; }
        .centro { text-align: center; }
        .mora { color: #b42318; font-weight: bold; }
        .inactiva { color: #7b8794; }
        .total td { font-weight: bold; font-size: 11px; border-bottom: none; }
    </style>
</head>
<body>
    <h1>Reporte de morosidad</h1>
    <div class="filtros">
        {{ $reporte->residencial }} · Al {{ $fecha($reporte->fechaCorte) }}
        · Ordenado por {{ $reporte->orden === 'antiguedad' ? 'antigüedad de la deuda' : 'monto' }}
    </div>

    <div class="resumen">
        <span>Viviendas con saldo: <strong>{{ count($reporte->viviendas) }}</strong></span>
        <span>En mora: <strong>{{ $reporte->enMora }}</strong></span>
        <span>Vencido: <strong>{{ $lempiras($reporte->totalVencido) }}</strong></span>
        <span>Saldo total: <strong>{{ $lempiras($reporte->total) }}</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Vivienda</th>
                <th>Responsable de pago</th>
                <th class="centro">Meses adeudados</th>
                <th>Deuda desde</th>
                <th class="centro">Meses vencidos</th>
                <th class="derecha">Vencido</th>
                <th class="derecha">Saldo</th>
                <th class="derecha">Por verificar</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reporte->viviendas as $vivienda)
                <tr>
                    <td>
                        {{ $vivienda['codigo'] }}
                        @unless ($vivienda['activa'])
                            <span class="inactiva">(inactiva)</span>
                        @endunless
                    </td>
                    <td>{{ $vivienda['responsable'] ?? 'Sin responsable' }}</td>
                    <td class="centro">{{ $vivienda['meses_adeudados'] }}</td>
                    <td>{{ $mes($vivienda['deuda_desde']) }}</td>
                    <td class="centro">{{ $vivienda['meses_vencidos'] }}</td>
                    <td class="derecha">{{ $lempiras($vivienda['vencido']) }}</td>
                    <td class="derecha">{{ $lempiras($vivienda['saldo']) }}</td>
                    <td class="derecha">{{ (float) $vivienda['por_verificar'] > 0 ? $lempiras($vivienda['por_verificar']) : '—' }}</td>
                    <td @class(['mora' => $vivienda['estado'] === 'en_mora'])>{{ $vivienda['estado'] === 'en_mora' ? 'En mora' : 'Al día' }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No hay viviendas con saldo pendiente.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="5">Total</td>
                <td class="derecha">{{ $lempiras($reporte->totalVencido) }}</td>
                <td class="derecha">{{ $lempiras($reporte->total) }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
