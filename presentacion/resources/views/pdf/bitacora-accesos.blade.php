@php
    use Carbon\CarbonImmutable;
    use Patronato\Negocio\Reportes\BitacoraTextos;

    $fecha = fn (string $valor): string => CarbonImmutable::parse($valor)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    $filtros = $bitacora->filtros;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Bitácora de accesos</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f2933; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .filtros { color: #52606d; margin-bottom: 8px; font-size: 10px; }
        .totales { margin-bottom: 12px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #f0f4f8; padding: 5px; }
        td { padding: 4px 5px; border-bottom: 1px solid #d9e2ec; vertical-align: top; }
        .urgente { color: #b42318; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Bitácora de accesos</h1>
    <div class="filtros">
        Del {{ $fecha($filtros['desde']) }} al {{ $fecha($filtros['hasta']) }}
        · Tipo: {{ BitacoraTextos::tipo($filtros['tipo']) }}
        · Turno: {{ BitacoraTextos::turno($filtros['turno'], 'Todos') }}
        · Guardia: {{ $filtros['guardia'] ?? 'Todos' }}
        · Vivienda: {{ $filtros['vivienda'] ?? 'Todas' }}
        · Medio de entrada: {{ $filtros['medio'] ?? 'Todos' }}
    </div>
    <div class="totales">
        Entradas: {{ $bitacora->totales['entrada'] }}
        · Salidas: {{ $bitacora->totales['salida'] }}
        · Incidencias: {{ $bitacora->totales['incidencia'] }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Fecha y hora</th>
                <th>Tipo</th>
                <th>Guardia</th>
                <th>Turno</th>
                <th>Vivienda</th>
                <th>Medio</th>
                <th>Visitante o tipo</th>
                <th>Placa</th>
                <th>Motivo o qué pasó</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bitacora->eventos as $evento)
                <tr>
                    <td>{{ $evento['fecha'] }}</td>
                    <td>
                        {{ BitacoraTextos::tipo($evento['tipo']) }}
                        @if ($evento['urgente'])
                            <span class="urgente">Urgente</span>
                        @endif
                    </td>
                    <td>{{ $evento['guardia'] }}</td>
                    <td>{{ BitacoraTextos::turno($evento['turno']) }}</td>
                    <td>{{ $evento['vivienda'] ?? '—' }}</td>
                    <td>{{ $evento['medio'] ?? '—' }}</td>
                    <td>{{ $evento['visitante'] ?? $evento['categoria'] }}</td>
                    <td>{{ $evento['placa'] ?? '—' }}</td>
                    <td>{{ $evento['detalle'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No hay registros con estos filtros.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
