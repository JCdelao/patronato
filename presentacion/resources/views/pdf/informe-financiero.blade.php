@php
    use Carbon\CarbonImmutable;

    $lempiras = fn (string $monto): string => 'L '.number_format((float) $monto, 2, '.', ',');
    $fecha = fn (string $valor): string => CarbonImmutable::parse($valor)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    $mes = fn (string $valor): string => ucfirst(CarbonImmutable::parse($valor.'-01')->locale('es')->isoFormat('MMMM'));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe financiero · {{ $informe->titulo }}</title>
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
    </style>
</head>
<body>
    <h1>Informe financiero · {{ $informe->titulo }}</h1>
    <div class="filtros">
        {{ $informe->residencial }} · Del {{ $fecha($informe->desde) }} al {{ $fecha($informe->hasta) }}
        · Solo movimientos vigentes; los anulados no suman.
    </div>

    <table>
        <tbody>
            <tr class="subtotal">
                <td>Saldo inicial</td>
                <td class="derecha">{{ $lempiras($informe->saldoInicial) }}</td>
            </tr>
            @if ($informe->apertura !== null)
                <tr>
                    <td>Saldo de apertura del {{ $fecha($informe->apertura['fecha']) }}</td>
                    <td class="derecha">{{ $lempiras($informe->apertura['monto']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <h2>Ingresos por categoría</h2>
    <table>
        <tbody>
            @forelse ($informe->ingresos as $fila)
                <tr>
                    <td>{{ $fila['categoria'] }}</td>
                    <td class="derecha">{{ $lempiras($fila['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="2">Sin ingresos en el período.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>Total de ingresos</td>
                <td class="derecha">{{ $lempiras($informe->totalIngresos) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Egresos por categoría</h2>
    <table>
        <tbody>
            @forelse ($informe->egresos as $fila)
                <tr>
                    <td>{{ $fila['categoria'] }}</td>
                    <td class="derecha">{{ $lempiras($fila['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="2">Sin egresos en el período.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>Total de egresos</td>
                <td class="derecha">{{ $lempiras($informe->totalEgresos) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="margin-top: 16px">
        <tbody>
            <tr class="total">
                <td>Saldo final</td>
                <td class="derecha">{{ $lempiras($informe->saldoFinal) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($informe->meses !== [])
        <h2>Mes a mes</h2>
        <table>
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="derecha">Saldo inicial</th>
                    <th class="derecha">Apertura</th>
                    <th class="derecha">Ingresos</th>
                    <th class="derecha">Egresos</th>
                    <th class="derecha">Saldo final</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($informe->meses as $fila)
                    <tr>
                        <td>{{ $mes($fila['mes']) }}</td>
                        <td class="derecha">{{ $lempiras($fila['saldo_inicial']) }}</td>
                        <td class="derecha">{{ $fila['apertura'] === null ? '—' : $lempiras($fila['apertura']) }}</td>
                        <td class="derecha">{{ $lempiras($fila['ingresos']) }}</td>
                        <td class="derecha">{{ $lempiras($fila['egresos']) }}</td>
                        <td class="derecha">{{ $lempiras($fila['saldo_final']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
