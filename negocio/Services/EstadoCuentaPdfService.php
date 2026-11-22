<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;

/**
 * Estado de cuenta de una vivienda en PDF, para un rango de fechas
 *
 * Reglas:
 * - Usa los movimientos y el saldo acumulado del estado de cuenta: cargos, ajustes y
 *   pagos confirmados. No calcula saldos por su cuenta ni cambia ningún dato.
 * - El saldo inicial es el acumulado antes del rango y el final, el acumulado al cerrarlo;
 *   además muestra el saldo oficial de la vivienda a la fecha de emisión.
 * - Un cargo tiene la fecha del primer día de su período.
 * - Vale para viviendas activas e inactivas, sin límite de años.
 * - Cada generación queda en la auditoría con la vivienda y el rango.
 */
class EstadoCuentaPdfService
{
    public const VISTA = 'pdf.estado-cuenta';

    /**
     * Zona horaria de la fecha de emisión
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    public function __construct(
        private EstadoCuentaService $estados,
        private ResidencialRepositorio $residenciales,
        private ViviendaService $viviendas,
        private LogoResidencialService $logos,
        private PeriodoService $periodos,
        private GeneradorPdf $generador,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Viviendas activas e inactivas que coinciden con la búsqueda
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $buscar): array
    {
        return $buscar === '' ? [] : $this->viviendas->opcionesConInactivas($buscar);
    }

    /**
     * Vivienda elegida para el formulario; null si no es del residencial
     *
     * @return array<string, mixed>|null
     */
    public function opcion(int $viviendaId): ?array
    {
        return $this->viviendas->opcionesConInactivas(viviendaId: $viviendaId)[0] ?? null;
    }

    /**
     * Rango que propone el formulario: del inicio del año hasta hoy, en Honduras
     *
     * @return array{desde: string, hasta: string}
     */
    public function rangoPredeterminado(): array
    {
        $hoy = $this->periodos->hoy();

        return ['desde' => $hoy->startOfYear()->toDateString(), 'hasta' => $hoy->toDateString()];
    }

    /**
     * Datos que muestra el PDF
     *
     * @return array<string, mixed>
     */
    public function datos(Vivienda $vivienda, string $desde, string $hasta): array
    {
        $estado = $this->estados->deVivienda($vivienda);
        $residencial = $this->residenciales->buscar($vivienda->residencial_id);

        /** @var list<array<string, mixed>> $todos */
        $todos = $estado['movimientos'];

        $anteriores = array_values(array_filter($todos, fn (array $movimiento): bool => $movimiento['fecha'] < $desde));
        $movimientos = array_values(array_filter($todos, fn (array $movimiento): bool => $movimiento['fecha'] >= $desde && $movimiento['fecha'] <= $hasta));

        // El saldo de cada línea ya es el acumulado; no se vuelve a calcular
        $saldoInicial = $anteriores === [] ? '0.00' : (string) end($anteriores)['saldo'];
        $saldoFinal = $movimientos === [] ? $saldoInicial : (string) end($movimientos)['saldo'];

        return [
            'residencial' => [
                'nombre' => $residencial->nombre,
                'direccion' => $residencial->direccion,
                'telefono' => $residencial->telefono,
                'correo' => $residencial->correo,
            ],
            'logo' => $this->logos->enLinea($residencial->logo_path),
            'vivienda' => $estado['vivienda'],
            'desde' => $desde,
            'hasta' => $hasta,
            'movimientos' => $movimientos,
            'saldo_inicial' => $saldoInicial,
            'saldo_final' => $saldoFinal,
            'saldo_oficial' => $estado['totales']['saldo'],
            'emitido' => CarbonImmutable::now(self::ZONA_LOCAL)->format('Y-m-d H:i'),
        ];
    }

    /**
     * Genera el PDF, deja constancia en la auditoría y devuelve el archivo
     *
     * @return array{contenido: string, tipo: string, nombre: string}
     */
    public function generar(User $usuario, Vivienda $vivienda, string $desde, string $hasta): array
    {
        $contenido = $this->generador->generar(self::VISTA, $this->datos($vivienda, $desde, $hasta));

        $this->auditoria->registrar($usuario, 'estado_cuenta_pdf', 'estado_cuenta_pdf_generado', $vivienda, [
            'valor_nuevo' => [
                'vivienda_id' => $vivienda->id,
                'codigo' => $vivienda->codigo,
                'desde' => $desde,
                'hasta' => $hasta,
            ],
        ]);

        return [
            'contenido' => $contenido,
            'tipo' => 'application/pdf',
            'nombre' => $this->nombreArchivo($vivienda, $desde, $hasta),
        ];
    }

    /**
     * Nombre del archivo, sin caracteres que el navegador no acepte
     */
    public function nombreArchivo(Vivienda $vivienda, string $desde, string $hasta): string
    {
        $codigo = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $vivienda->codigo), '-') ?: 'vivienda';

        return "estado-de-cuenta-{$codigo}-{$desde}-{$hasta}.pdf";
    }
}
