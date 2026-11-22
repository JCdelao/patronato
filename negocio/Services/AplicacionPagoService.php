<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Reparte un pago entre los cargos de la vivienda
 *
 * Aplica a los meses elegidos o, si no se elige ninguno, a todos los pendientes,
 * del más antiguo al más reciente. Lo que sobra de un pago sin meses queda como
 * saldo a favor y se aplica a los cargos que se generen después.
 */
class AplicacionPagoService
{
    // Principio D (inversión de dependencias): recibe la interfaz CargosPendientes, no una clase concreta
    public function __construct(
        private CargosPendientes $cargos,
        private PagoRepositorio $pagos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Aplica el pago a los meses elegidos, del más antiguo al más reciente
     *
     * Sin meses elegidos se aplica a todos los pendientes de la vivienda. El último
     * mes puede quedar cubierto en parte. Con $admiteExcedente, un pago sin meses
     * puede superar lo pendiente: lo que sobra queda sin aplicar, como saldo a favor.
     * Debe llamarse dentro de la transacción que guarda el pago.
     *
     * @param  array<int, int>  $cargoIds  vacío para aplicar a los pendientes más antiguos
     * @return list<int> cargos a los que se aplicó, en orden
     *
     * @throws ValidationException
     * @throws CargosNoIntegrados
     */
    public function aplicar(Pago $pago, array $cargoIds, bool $admiteExcedente = false): array
    {
        $restante = self::centavos($pago->monto);
        $elegidos = $this->elegidos($pago->vivienda_id, $cargoIds);
        $aplicados = [];

        if ($restante > $this->total($elegidos) && ! ($admiteExcedente && $cargoIds === [])) {
            throw ValidationException::withMessages(['monto' => $cargoIds === []
                ? 'El monto supera lo pendiente de la vivienda.'
                : 'El monto supera lo pendiente de los meses elegidos.']);
        }

        foreach ($elegidos as $cargo) {
            $aplicado = min($restante, self::centavos($cargo['pendiente']));

            if ($aplicado === 0) {
                break;
            }

            $this->pagos->aplicar([
                'residencial_id' => $pago->residencial_id,
                'pago_id' => $pago->id,
                'cargo_id' => $cargo['cargo_id'],
                'monto' => self::monto($aplicado),
                'origen' => PagoAplicacion::PAGO,
            ]);

            $aplicados[] = $cargo['cargo_id'];
            $restante -= $aplicado;
        }

        return $aplicados;
    }

    /**
     * Aplica el saldo a favor de los pagos del residencial a los cargos pendientes
     *
     * Cada vivienda usa primero el saldo de su pago más antiguo, por fecha y luego por
     * número, y lo aplica del cargo más antiguo al más reciente. No depende de que el
     * residencial siga aceptando saldo a favor: ese ajuste solo decide si se acepta un
     * excedente nuevo. Los pagos migrados no aportan saldo.
     *
     * Debe llamarse dentro de una transacción. Toma el bloqueo del residencial antes de
     * leer, así ve todo lo que los cobros ya confirmaron; supone el aislamiento
     * Read Committed de PostgreSQL.
     *
     * @throws CargosNoIntegrados
     */
    public function aplicarSaldoAFavor(int $residencialId, ?User $usuario): void
    {
        if (! $this->transaccion->activa()) {
            throw new LogicException('El saldo a favor debe aplicarse dentro de una transacción.');
        }

        $this->pagos->bloquear($residencialId);

        foreach ($this->pagos->conSaldoDisponible($residencialId)->groupBy('vivienda_id') as $viviendaId => $pagos) {
            // Lo pendiente de cada cargo baja a medida que se aplica el saldo de cada pago
            $pendientes = $this->cargos->pendientesDeVivienda((int) $viviendaId)
                ->map(fn (array $cargo): array => ['cargo_id' => $cargo['cargo_id'], 'pendiente' => self::centavos($cargo['pendiente'])])
                ->values()
                ->all();

            foreach ($pagos as $pago) {
                /** @var Pago $pago */
                $disponible = self::centavos($pago->monto) - self::centavos((string) $pago->getAttribute('aplicado'));
                $cubiertos = [];
                $usado = 0;

                foreach ($pendientes as $indice => $cargo) {
                    $aplicado = min($disponible - $usado, $cargo['pendiente']);

                    if ($aplicado <= 0) {
                        continue;
                    }

                    $this->pagos->aplicar([
                        'residencial_id' => $residencialId,
                        'pago_id' => $pago->id,
                        'cargo_id' => $cargo['cargo_id'],
                        'monto' => self::monto($aplicado),
                        'origen' => PagoAplicacion::SALDO_A_FAVOR,
                    ]);

                    $pendientes[$indice]['pendiente'] -= $aplicado;
                    $cubiertos[] = $cargo['cargo_id'];
                    $usado += $aplicado;

                    if ($usado === $disponible) {
                        break;
                    }
                }

                if ($usado > 0) {
                    $this->auditarSaldo($residencialId, $usuario, $pago, $cubiertos, $usado, $disponible - $usado);
                }
            }
        }
    }

    /**
     * Total pendiente, en centavos, de los meses elegidos de una vivienda
     *
     * Sin meses elegidos es el total pendiente de la vivienda.
     *
     * @param  array<int, int>  $cargoIds
     *
     * @throws ValidationException
     * @throws CargosNoIntegrados
     */
    public function pendienteDe(int $viviendaId, array $cargoIds): int
    {
        return $this->total($this->elegidos($viviendaId, $cargoIds));
    }

    /**
     * Convierte un monto con dos decimales a centavos
     */
    public static function centavos(string $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    /**
     * Convierte centavos a un monto con dos decimales
     */
    public static function monto(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Meses elegidos que siguen pendientes, en el orden que da la generación de cargos
     *
     * Sin meses elegidos devuelve todos los pendientes: del período más antiguo al
     * más reciente y, dentro de un período, por concepto.
     *
     * @param  array<int, int>  $cargoIds
     * @return Collection<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>
     *
     * @throws ValidationException
     */
    private function elegidos(int $viviendaId, array $cargoIds): Collection
    {
        $cargoIds = array_values(array_unique($cargoIds));
        $pendientes = $this->cargos->pendientesDeVivienda($viviendaId);

        if ($cargoIds === []) {
            return $pendientes->values();
        }

        $elegidos = $pendientes
            ->filter(fn (array $cargo): bool => in_array($cargo['cargo_id'], $cargoIds, true))
            ->values();

        // Rechaza meses de otra vivienda, de otro residencial o ya pagados
        if ($elegidos->count() !== count($cargoIds)) {
            throw ValidationException::withMessages(['cargos' => 'Uno de los meses elegidos no está pendiente para esta vivienda.']);
        }

        return $elegidos;
    }

    /**
     * @param  Collection<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>  $cargos
     */
    private function total(Collection $cargos): int
    {
        return $cargos->sum(fn (array $cargo): int => self::centavos($cargo['pendiente']));
    }

    /**
     * Deja constancia del saldo a favor aplicado; la hace el sistema si generó el comando programado
     *
     * @param  list<int>  $cargos
     */
    private function auditarSaldo(int $residencialId, ?User $usuario, Pago $pago, array $cargos, int $usado, int $restante): void
    {
        $datos = ['valor_nuevo' => [
            'cargos' => $cargos,
            'monto' => self::monto($usado),
            'disponible_restante' => self::monto($restante),
        ]];

        if ($usuario === null) {
            $this->auditoria->registrarSistema($residencialId, 'cobros', 'saldo_favor_aplicado', $pago, $datos);
        } else {
            $this->auditoria->registrar($usuario, 'cobros', 'saldo_favor_aplicado', $pago, $datos);
        }
    }
}
