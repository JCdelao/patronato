<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Repositorios\AuditoriaRepositorio;

class BitacoraService
{
    /**
     * Nombre de cada tabla y la columna que la identifica
     */
    private const ENTIDADES = [
        'users' => ['etiqueta' => 'Cuenta', 'columna' => 'name'],
        'roles' => ['etiqueta' => 'Rol', 'columna' => 'nombre'],
        'viviendas' => ['etiqueta' => 'Vivienda', 'columna' => 'codigo'],
        'residentes' => ['etiqueta' => 'Residente', 'columna' => "nombres || ' ' || apellidos"],
        'residenciales' => ['etiqueta' => 'Residencial', 'columna' => 'nombre'],
        'conceptos_cobro' => ['etiqueta' => 'Concepto de cobro', 'columna' => 'nombre'],
        'tipos_vivienda' => ['etiqueta' => 'Tipo de vivienda', 'columna' => 'nombre'],
    ];

    /**
     * Nombre de cada dato guardado en la bitácora
     */
    private const CAMPOS = [
        'email' => 'Correo',
        'correo' => 'Correo',
        'rol_id' => 'Rol',
        'permisos' => 'Permisos',
        'codigo' => 'Código',
        'tipo' => 'Tipo',
        'edificio_torre' => 'Edificio o torre',
        'calle_bloque' => 'Calle o bloque',
        'numero' => 'Número',
        'estado_ocupacion' => 'Ocupación',
        'cuota' => 'Cuota mensual',
        'pertenece_a' => 'Pertenece a',
        'cuota_especial' => 'Cuota especial',
        'nombres' => 'Nombres',
        'apellidos' => 'Apellidos',
        'identidad' => 'Identidad',
        'telefono' => 'Teléfono',
        'vivienda_id' => 'Vivienda',
        'viviendas' => 'Viviendas',
        'estado' => 'Estado',
        'monto' => 'Monto',
        'modalidad' => 'Modalidad',
        'vigente_desde' => 'Aplica desde',
        'desglose' => 'Desglose informativo',
    ];

    public function __construct(private AuditoriaRepositorio $auditoria) {}

    /**
     * Prepara los registros para que se lean sin conocer la base de datos
     *
     * @param  Collection<int, Auditoria>  $registros
     * @return array<int, array{referencia: string|null, cambios: list<array{campo: string, antes: string|null, despues: string|null}>}>
     */
    public function presentar(Collection $registros): array
    {
        $nombres = $this->nombres($registros);

        return $registros->mapWithKeys(fn (Auditoria $registro) => [$registro->id => [
            'referencia' => $this->referencia($registro, $nombres),
            'cambios' => $this->cambios($registro, $nombres),
        ]])->all();
    }

    /**
     * Historial de cambios de varios registros, del más reciente al más antiguo
     *
     * @param  array<string, list<int>>  $entidades
     * @return list<array{id: int, fecha: string, usuario: string, accion: string, referencia: string|null, cambios: list<array{campo: string, antes: string|null, despues: string|null}>, motivo: string|null}>
     */
    public function historial(int $residencialId, array $entidades): array
    {
        $registros = $this->auditoria->historial($residencialId, $entidades);

        $detalles = $this->presentar($registros);

        return array_values($registros->map(fn (Auditoria $registro) => [
            'id' => $registro->id,
            'fecha' => $registro->created_at->toIso8601String(),
            'usuario' => $registro->usuario->name ?? 'Sistema',
            'accion' => $registro->accion,
            'referencia' => $detalles[$registro->id]['referencia'],
            'cambios' => $detalles[$registro->id]['cambios'],
            'motivo' => $registro->motivo,
        ])->all());
    }

    /**
     * @param  array<string, array<int, string>>  $nombres
     */
    private function referencia(Auditoria $registro, array $nombres): ?string
    {
        if ($registro->entidad === null) {
            return null;
        }

        $etiqueta = self::ENTIDADES[$registro->entidad]['etiqueta'] ?? Str::ucfirst($registro->entidad);
        $nombre = $nombres[$registro->entidad][$registro->entidad_id] ?? null;

        return $nombre !== null ? "{$etiqueta}: {$nombre}" : "{$etiqueta} (ya no existe)";
    }

    /**
     * @param  array<string, array<int, string>>  $nombres
     * @return list<array{campo: string, antes: string|null, despues: string|null}>
     */
    private function cambios(Auditoria $registro, array $nombres): array
    {
        $antes = $registro->valor_anterior ?? [];
        $despues = $registro->valor_nuevo ?? [];

        $cambios = [];

        foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $campo) {
            $valorAntes = $this->valor($campo, $antes[$campo] ?? null, $nombres);
            $valorDespues = $this->valor($campo, $despues[$campo] ?? null, $nombres);

            // Omite los datos que estaban vacíos antes y después
            if ($valorAntes === null && $valorDespues === null) {
                continue;
            }

            $cambios[] = [
                'campo' => self::CAMPOS[$campo] ?? Str::ucfirst(str_replace('_', ' ', $campo)),
                'antes' => $valorAntes,
                'despues' => $valorDespues,
            ];
        }

        return $cambios;
    }

    /**
     * Convierte un valor guardado en texto
     *
     * @param  array<string, array<int, string>>  $nombres
     */
    private function valor(string $campo, mixed $valor, array $nombres): ?string
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return null;
        }

        return match (true) {
            $campo === 'rol_id' => $nombres['roles'][(int) $valor] ?? 'Rol eliminado',
            $campo === 'vivienda_id' => $nombres['viviendas'][(int) $valor] ?? 'Vivienda eliminada',
            $campo === 'viviendas' && is_array($valor) => collect($valor)->map(fn ($id) => $nombres['viviendas'][(int) $id] ?? 'Vivienda eliminada')->implode(', '),
            $campo === 'permisos' && is_array($valor) => collect($valor)->map(fn ($permiso) => $this->permiso((string) $permiso))->implode(', '),
            $campo === 'desglose' && is_array($valor) => collect($valor)->map(fn ($fila) => is_array($fila) ? ($fila['concepto'] ?? '').': L. '.($fila['monto'] ?? '') : (string) $fila)->implode(', '),
            is_bool($valor) => $valor ? 'Sí' : 'No',
            $campo === 'tipo' && $valor === 'unico' => 'Único',
            is_array($valor) => collect($valor)->map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item))->implode(', '),
            in_array($campo, ['tipo', 'estado', 'estado_ocupacion', 'modalidad'], true) => Str::ucfirst(str_replace('_', ' ', (string) $valor)),
            default => is_scalar($valor) ? (string) $valor : (string) json_encode($valor),
        };
    }

    /**
     * Convierte un permiso como padron.ver en "Viviendas y residentes: ver"
     */
    private function permiso(string $permiso): string
    {
        [$modulo, $accion] = array_pad(explode('.', $permiso, 2), 2, '');

        $etiqueta = PermisoService::MODULOS[$modulo]['etiqueta'] ?? $modulo;

        return $accion !== '' ? "{$etiqueta}: {$accion}" : $etiqueta;
    }

    /**
     * Busca de una vez los nombres de los registros y de los datos que guardan ids
     *
     * @param  Collection<int, Auditoria>  $registros
     * @return array<string, array<int, string>>
     */
    private function nombres(Collection $registros): array
    {
        $ids = [];

        foreach ($registros as $registro) {
            if ($registro->entidad !== null && $registro->entidad_id !== null) {
                $ids[$registro->entidad][] = $registro->entidad_id;
            }

            foreach ([$registro->valor_anterior ?? [], $registro->valor_nuevo ?? []] as $valores) {
                if (isset($valores['rol_id'])) {
                    $ids['roles'][] = (int) $valores['rol_id'];
                }

                if (isset($valores['vivienda_id'])) {
                    $ids['viviendas'][] = (int) $valores['vivienda_id'];
                }

                if (isset($valores['viviendas']) && is_array($valores['viviendas'])) {
                    $ids['viviendas'] = [...$ids['viviendas'] ?? [], ...array_map('intval', $valores['viviendas'])];
                }
            }
        }

        $nombres = [];

        foreach ($ids as $tabla => $lista) {
            if (! isset(self::ENTIDADES[$tabla])) {
                continue;
            }

            $nombres[$tabla] = $this->auditoria->nombres($tabla, self::ENTIDADES[$tabla]['columna'], $lista);
        }

        return $nombres;
    }
}
