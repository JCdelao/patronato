@php
    use Carbon\CarbonImmutable;

    $lempiras = fn (string $monto): string => 'L '.number_format((float) $monto, 2, '.', ',');
    $fecha = CarbonImmutable::parse($comprobante['fecha'])->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante de pago N.º {{ $comprobante['numero_recibo'] }}</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2933; }
        .encabezado { width: 100%; border-bottom: 2px solid #1f2933; padding-bottom: 12px; }
        .encabezado td { vertical-align: middle; }
        .logo { max-height: 70px; max-width: 140px; }
        .residencial { font-size: 18px; font-weight: bold; }
        .secundario { color: #52606d; }
        .titulo { text-align: right; }
        .titulo h1 { font-size: 16px; margin: 0; }
        .recibo { font-size: 20px; font-weight: bold; }
        .datos { width: 100%; margin-top: 20px; border-collapse: collapse; }
        .datos th { text-align: left; width: 30%; padding: 6px 0; color: #52606d; font-weight: normal; }
        .datos td { padding: 6px 0; font-weight: bold; }
        .periodos { width: 100%; margin-top: 20px; border-collapse: collapse; }
        .periodos th { text-align: left; background: #f0f4f8; padding: 8px; }
        .periodos td { padding: 8px; border-bottom: 1px solid #d9e2ec; }
        .derecha { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; font-size: 14px; }
        .pie { margin-top: 32px; color: #52606d; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            @if ($logo)
                <td style="width: 150px;"><img class="logo" src="{{ $logo }}" alt="Logo"></td>
            @endif
            <td>
                <div class="residencial">{{ $comprobante['residencial'] }}</div>
                @if ($comprobante['direccion'])
                    <div class="secundario">{{ $comprobante['direccion'] }}</div>
                @endif
                @if ($comprobante['telefono'])
                    <div class="secundario">Tel. {{ $comprobante['telefono'] }}</div>
                @endif
            </td>
            <td class="titulo">
                <h1>Comprobante de pago</h1>
                <div class="recibo">Recibo N.º {{ $comprobante['numero_recibo'] }}</div>
            </td>
        </tr>
    </table>

    <table class="datos">
        <tr><th>Fecha</th><td>{{ $fecha }}, {{ $comprobante['hora'] }}</td></tr>
        <tr><th>Vivienda</th><td>{{ $comprobante['vivienda'] }}</td></tr>
        <tr><th>Monto pagado</th><td>{{ $lempiras($comprobante['monto']) }}</td></tr>
        <tr><th>Forma de pago</th><td>Efectivo</td></tr>
        <tr><th>Cobrador</th><td>{{ $comprobante['cobrador'] }}</td></tr>
    </table>

    <table class="periodos">
        <thead>
            <tr>
                <th>Período pagado</th>
                <th>Concepto</th>
                <th class="derecha">Monto aplicado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($comprobante['periodos'] as $periodo)
                <tr>
                    <td>{{ ucfirst(CarbonImmutable::parse($periodo['periodo'])->locale('es')->isoFormat('MMMM [de] YYYY')) }}</td>
                    <td>{{ $periodo['concepto'] }}</td>
                    <td class="derecha">{{ $lempiras($periodo['monto']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total</td>
                <td class="derecha">{{ $lempiras($comprobante['monto']) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="pie">Este comprobante es la constancia del pago registrado en el sistema del patronato.</p>
</body>
</html>
