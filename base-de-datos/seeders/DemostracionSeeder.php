<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Patronato\Persistencia\Models\Residencial;

/**
 * Datos de demostración para probar el sistema a mano
 *
 * Crea dos residenciales con cuentas de cada rol, viviendas, residentes,
 * conceptos, meses pendientes y cobros en cajas abiertas y cerradas,
 * una transferencia pendiente de verificación con su comprobante y otra
 * que un vecino reportó desde el portal con su cuenta de residente.
 * Todas las cuentas usan la contraseña "password".
 *
 * Solo para una base local: php artisan db:seed --class=DemostracionSeeder
 */
class DemostracionSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const RESIDENCIAL = 'Residencial Las Palmas';

    public const OTRO_RESIDENCIAL = 'Residencial Los Pinos';

    public const ADMIN_PLATAFORMA = 'admin@demo.local';

    private const MOTIVO_ANULACION = 'Recibo emitido a la vivienda equivocada.';

    /**
     * Meses con cargos: septiembre, octubre y noviembre de 2026
     */
    private const MESES = ['2026-09-01', '2026-10-01', '2026-11-01'];

    private string $password;

    private CarbonImmutable $ahora;

    /** @var array<string, int> */
    private array $roles = [];

    public function run(): void
    {
        if (DB::table('residenciales')->where('nombre', self::RESIDENCIAL)->exists()) {
            $this->command?->warn('Los datos de demostración ya existen; no se cargó nada.');

            return;
        }

        $this->call(RolesSeeder::class);

        $this->password = Hash::make(self::PASSWORD);
        $this->ahora = CarbonImmutable::now();
        $this->roles = DB::table('roles')->pluck('id', 'codigo')->map(fn ($id): int => (int) $id)->all();

        DB::transaction(function (): void {
            $this->cuenta(null, 'admin_plataforma', 'Administrador de la plataforma', self::ADMIN_PLATAFORMA);

            $this->lasPalmas();
            $this->losPinos();
        });

        $this->command?->info('Datos de demostración cargados. Contraseña de todas las cuentas: '.self::PASSWORD);
    }

    private function lasPalmas(): void
    {
        $residencial = $this->residencial(self::RESIDENCIAL, 'Col. Las Palmas, Tegucigalpa');

        $this->cuenta($residencial, 'admin_tecnico', 'Técnico Las Palmas', 'tecnico@laspalmas.demo');
        $presidente = $this->cuenta($residencial, 'presidente', 'Patricia Mejía', 'presidente@laspalmas.demo');
        $this->cuenta($residencial, 'secretario', 'Sergio Lagos', 'secretario@laspalmas.demo');
        $tesorero = $this->cuenta($residencial, 'tesorero', 'Teresa Flores', 'tesorero@laspalmas.demo');
        $this->cuenta($residencial, 'fiscal', 'Fernando Castro', 'fiscal@laspalmas.demo');
        $this->cuenta($residencial, 'vocal', 'Valeria Núñez', 'vocal@laspalmas.demo');
        $this->cuenta($residencial, 'guardia', 'Gustavo Pineda', 'guardia@laspalmas.demo');
        $cobrador = $this->cuenta($residencial, 'cobrador', 'Carlos Rivera', 'cobrador@laspalmas.demo');
        $cobrador2 = $this->cuenta($residencial, 'cobrador', 'Claudia Ramos', 'cobrador2@laspalmas.demo');

        $viviendas = $this->viviendas($residencial, [
            ['A-01', 'casa', 'Bloque A', '1', 'María', 'López'],
            ['A-02', 'casa', 'Bloque A', '2', 'José', 'Martínez'],
            ['A-03', 'casa', 'Bloque A', '3', 'Ana', 'Hernández'],
            ['A-04', 'casa', 'Bloque A', '4', 'Luis', 'García'],
            ['B-01', 'casa', 'Bloque B', '1', 'Rosa', 'Zelaya'],
            ['B-02', 'casa', 'Bloque B', '2', 'Pedro', 'Aguilar'],
            ['B-03', 'casa', 'Bloque B', '3', 'Carmen', 'Reyes'],
            ['T1-101', 'apartamento', null, '101', 'Jorge', 'Mendoza'],
            ['T1-102', 'apartamento', null, '102', 'Lucía', 'Ortiz'],
            ['T1-201', 'apartamento', null, '201', 'Miguel', 'Cruz'],
        ]);

        $cargos = $this->cargos($residencial, $viviendas, $tesorero, [
            ['Cuota de mantenimiento', '500.00'],
            ['Cuota de seguridad', '150.00'],
        ]);

        // Caja de octubre, ya cerrada por la tesorera con un faltante justificado
        $cerrada = $this->caja($residencial, $cobrador, $this->ahora->setDate(2026, 10, 5)->setTime(14, 0));
        $this->cobro($residencial, $cerrada, $cobrador, $viviendas['A-01'], '2026-10-05', [$cargos['A-01']['2026-09-01'], $cargos['A-01']['2026-10-01']]);
        $this->cobro($residencial, $cerrada, $cobrador, $viviendas['A-02'], '2026-10-05', [$cargos['A-02']['2026-09-01']]);
        $this->cobro($residencial, $cerrada, $cobrador, $viviendas['B-01'], '2026-10-12', [$cargos['B-01']['2026-09-01']]);
        $this->cobro($residencial, $cerrada, $cobrador, $viviendas['T1-101'], '2026-10-12', [$cargos['T1-101']['2026-09-01']], anulacion: ['solicitada_por' => $tesorero, 'aprobada_por' => $presidente]);
        $this->cerrar($cerrada, $tesorero, '2550.00', 'Faltaron L 50.00 del cambio; el cobrador los repone en la próxima entrega.');

        // Caja abierta de este mes, lista para el cierre de caja
        $abierta = $this->caja($residencial, $cobrador, $this->ahora->setDate(2026, 11, 3)->setTime(15, 0));
        $this->cobro($residencial, $abierta, $cobrador, $viviendas['A-03'], '2026-11-03', [$cargos['A-03']['2026-09-01'], $cargos['A-03']['2026-10-01']]);
        $this->cobro($residencial, $abierta, $cobrador, $viviendas['A-04'], '2026-11-03', [$cargos['A-04']['2026-09-01']]);
        $this->cobro($residencial, $abierta, $cobrador, $viviendas['A-01'], '2026-11-10', [$cargos['A-01']['2026-11-01']]);

        // Otra cobradora con su caja abierta
        $abierta2 = $this->caja($residencial, $cobrador2, $this->ahora->setDate(2026, 11, 8)->setTime(16, 0));
        $this->cobro($residencial, $abierta2, $cobrador2, $viviendas['T1-102'], '2026-11-08', [$cargos['T1-102']['2026-09-01'], $cargos['T1-102']['2026-10-01'], $cargos['T1-102']['2026-11-01']]);
        $pagoB02 = $this->cobro($residencial, $abierta2, $cobrador2, $viviendas['B-02'], '2026-11-12', [$cargos['B-02']['2026-09-01']]);

        // Anulación pedida por la tesorera, para que el presidente o el fiscal la resuelvan
        $this->solicitarAnulacion($residencial, $pagoB02, $tesorero, 'El vecino pagó por transferencia; este cobro en efectivo quedó duplicado.');

        // Transferencia registrada por la tesorera, pendiente de verificación
        $this->transferencia($residencial, $tesorero, $viviendas['B-03'], '500.00', '2026-11-13', 'BAC Credomatic', 'FT2611130042', '730-123456-7');

        // Cuenta del propietario de A-04 para el portal, con una transferencia que reportó sin cuenta receptora
        $vecino = $this->cuenta($residencial, 'residente', 'Luis García', 'residente@laspalmas.demo');
        DB::table('residentes')->where('residencial_id', $residencial)->where('nombres', 'Luis')->where('apellidos', 'García')->update(['user_id' => $vecino]);
        $this->transferencia($residencial, $vecino, $viviendas['A-04'], '650.00', '2026-11-14', 'Banco Atlántida', 'ATL2611140017', null, 'reportado_por_vecino', 'portal');
    }

    private function losPinos(): void
    {
        $residencial = $this->residencial(self::OTRO_RESIDENCIAL, 'Col. Los Pinos, San Pedro Sula');

        $tecnico = $this->cuenta($residencial, 'admin_tecnico', 'Técnico Los Pinos', 'tecnico@lospinos.demo');
        $this->cuenta($residencial, 'fiscal', 'Francisco Paz', 'fiscal@lospinos.demo');
        $cobrador = $this->cuenta($residencial, 'cobrador', 'Daniel Soto', 'cobrador@lospinos.demo');

        $viviendas = $this->viviendas($residencial, [
            ['P-01', 'casa', 'Calle 1', '1', 'Elena', 'Bonilla'],
            ['P-02', 'casa', 'Calle 1', '2', 'Raúl', 'Matute'],
            ['P-03', 'casa', 'Calle 1', '3', 'Sofía', 'Andino'],
        ]);

        $cargos = $this->cargos($residencial, $viviendas, $tecnico, [['Cuota mensual', '400.00']]);

        $caja = $this->caja($residencial, $cobrador, $this->ahora->setDate(2026, 11, 6)->setTime(15, 0));
        $this->cobro($residencial, $caja, $cobrador, $viviendas['P-01'], '2026-11-06', [$cargos['P-01']['2026-09-01']]);
        $this->cobro($residencial, $caja, $cobrador, $viviendas['P-02'], '2026-11-06', [$cargos['P-02']['2026-09-01'], $cargos['P-02']['2026-10-01']]);
    }

    /**
     * Crea el residencial con su configuración; sus permisos predeterminados
     * se asignan al crearse (AppServiceProvider)
     */
    private function residencial(string $nombre, string $direccion): int
    {
        $residencial = Residencial::query()->create(['nombre' => $nombre, 'direccion' => $direccion]);

        DB::table('configuracion_residencial')->insert(['residencial_id' => $residencial->id, 'updated_at' => $this->ahora]);

        return $residencial->id;
    }

    private function cuenta(?int $residencialId, string $rol, string $nombre, string $correo): int
    {
        return (int) DB::table('users')->insertGetId([
            'residencial_id' => $residencialId,
            'rol_id' => $this->roles[$rol],
            'name' => $nombre,
            'email' => $correo,
            'email_verified_at' => $this->ahora,
            'password' => $this->password,
            'activo' => true,
            'debe_cambiar_password' => false,
            'created_at' => $this->ahora,
            'updated_at' => $this->ahora,
        ]);
    }

    /**
     * Crea las viviendas, cada una con su propietario responsable del pago
     *
     * @param  list<array{string, string, ?string, string, string, string}>  $filas
     * @return array<string, int>
     */
    private function viviendas(int $residencialId, array $filas): array
    {
        $ids = [];

        // Los tipos Casa y Apartamento se crean con el residencial (AppServiceProvider)
        $tipos = DB::table('tipos_vivienda')->where('residencial_id', $residencialId)->pluck('id', 'nombre');

        foreach ($filas as [$codigo, $tipo, $bloque, $numero, $nombres, $apellidos]) {
            $ids[$codigo] = (int) DB::table('viviendas')->insertGetId([
                'residencial_id' => $residencialId,
                'codigo' => $codigo,
                'tipo_vivienda_id' => $tipos[ucfirst($tipo)],
                'edificio_torre' => $tipo === 'apartamento' ? 'Torre 1' : null,
                'calle_bloque' => $bloque,
                'numero' => $numero,
                'estado_ocupacion' => 'ocupada',
                'activa' => true,
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ]);

            $residenteId = DB::table('residentes')->insertGetId([
                'residencial_id' => $residencialId,
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'telefono' => '9900-'.str_pad((string) count($ids), 4, '0', STR_PAD_LEFT),
                'correo' => mb_strtolower($this->sinTildes($nombres).'.'.$this->sinTildes($apellidos)).'@correo.demo',
                'activo' => true,
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ]);

            DB::table('vivienda_residentes')->insert([
                'residencial_id' => $residencialId,
                'vivienda_id' => $ids[$codigo],
                'residente_id' => $residenteId,
                'tipo' => 'propietario',
                'es_responsable_pago' => true,
                'fecha_inicio' => '2026-01-01',
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ]);
        }

        return $ids;
    }

    /**
     * Crea los conceptos recurrentes con su tarifa y un cargo por vivienda, mes y concepto
     *
     * Devuelve, por vivienda y mes, los cargos de todos los conceptos: pagar un mes los cubre todos.
     *
     * @param  array<string, int>  $viviendas
     * @param  list<array{string, string}>  $conceptos
     * @return array<string, array<string, list<array{id: int, monto: string}>>>
     */
    private function cargos(int $residencialId, array $viviendas, int $usuarioId, array $conceptos): array
    {
        $cargos = [];

        foreach ($conceptos as [$nombre, $monto]) {
            $conceptoId = DB::table('conceptos_cobro')->insertGetId([
                'residencial_id' => $residencialId,
                'nombre' => $nombre,
                'tipo' => 'recurrente',
                'activo' => true,
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ]);

            DB::table('tarifas')->insert([
                'residencial_id' => $residencialId,
                'concepto_id' => $conceptoId,
                'monto' => $monto,
                'vigente_desde' => '2026-01-01',
                'created_by' => $usuarioId,
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ]);

            foreach ($viviendas as $codigo => $viviendaId) {
                foreach (self::MESES as $mes) {
                    $cargos[$codigo][$mes][] = [
                        'id' => (int) DB::table('cargos')->insertGetId([
                            'residencial_id' => $residencialId,
                            'vivienda_id' => $viviendaId,
                            'concepto_id' => $conceptoId,
                            'periodo' => $mes,
                            'monto' => $monto,
                            'fecha_vencimiento' => CarbonImmutable::parse($mes)->endOfMonth()->toDateString(),
                            'origen' => 'sistema',
                            'created_at' => $this->ahora,
                            'updated_at' => $this->ahora,
                        ]),
                        'monto' => $monto,
                    ];
                }
            }
        }

        return $cargos;
    }

    private function caja(int $residencialId, int $cobradorId, CarbonImmutable $abiertaEn): int
    {
        return (int) DB::table('cajas')->insertGetId([
            'residencial_id' => $residencialId,
            'cobrador_id' => $cobradorId,
            'estado' => 'abierta',
            'abierta_en' => $abiertaEn,
            'created_at' => $abiertaEn,
            'updated_at' => $abiertaEn,
        ]);
    }

    /**
     * Registra un cobro en efectivo que paga completos los meses indicados
     *
     * Si se indica la anulación, el pago queda anulado con su solicitud aprobada,
     * como lo deja la HU-23: un directivo la pide y otro la aprueba.
     *
     * @param  list<list<array{id: int, monto: string}>>  $meses
     * @param  array{solicitada_por: int, aprobada_por: int}|null  $anulacion
     */
    private function cobro(int $residencialId, int $cajaId, int $cobradorId, int $viviendaId, string $fecha, array $meses, ?array $anulacion = null): int
    {
        $cargos = array_merge(...$meses);
        $centavos = array_sum(array_map(fn (array $cargo): int => (int) round((float) $cargo['monto'] * 100), $cargos));
        $momento = CarbonImmutable::parse($fecha.' 16:00:00');

        $recibo = (int) DB::table('pagos')
            ->where('residencial_id', $residencialId)
            ->max(DB::raw('CAST(numero_recibo AS BIGINT)')) + 1;

        $pagoId = DB::table('pagos')->insertGetId([
            'residencial_id' => $residencialId,
            'vivienda_id' => $viviendaId,
            'forma_pago' => 'efectivo',
            'estado' => $anulacion === null ? 'confirmado' : 'anulado',
            'origen' => 'sistema',
            'monto' => number_format($centavos / 100, 2, '.', ''),
            'fecha_pago' => $fecha,
            'numero_recibo' => (string) $recibo,
            'caja_id' => $cajaId,
            'registrado_por' => $cobradorId,
            'anulado_por' => $anulacion['solicitada_por'] ?? null,
            'anulacion_aprobada_por' => $anulacion['aprobada_por'] ?? null,
            'anulado_en' => $anulacion === null ? null : $momento->addDay(),
            'motivo_anulacion' => $anulacion === null ? null : self::MOTIVO_ANULACION,
            'created_at' => $momento,
            'updated_at' => $momento,
        ]);

        if ($anulacion !== null) {
            DB::table('solicitudes_anulacion')->insert([
                'residencial_id' => $residencialId,
                'pago_id' => $pagoId,
                'solicitada_por' => $anulacion['solicitada_por'],
                'motivo' => self::MOTIVO_ANULACION,
                'estado' => 'aprobada',
                'resuelta_por' => $anulacion['aprobada_por'],
                'resuelta_en' => $momento->addDay(),
                'created_at' => $momento->addHours(2),
                'updated_at' => $momento->addDay(),
            ]);
        }

        // Las aplicaciones de un pago anulado se conservan como evidencia; solo cuentan las de pagos confirmados
        foreach ($cargos as $cargo) {
            DB::table('pago_aplicaciones')->insert([
                'residencial_id' => $residencialId,
                'pago_id' => $pagoId,
                'cargo_id' => $cargo['id'],
                'monto' => $cargo['monto'],
                'created_at' => $momento,
            ]);
        }

        return (int) $pagoId;
    }

    /**
     * Deja una solicitud de anulación esperando la aprobación de otro directivo
     */
    private function solicitarAnulacion(int $residencialId, int $pagoId, int $solicitadaPor, string $motivo): void
    {
        DB::table('solicitudes_anulacion')->insert([
            'residencial_id' => $residencialId,
            'pago_id' => $pagoId,
            'solicitada_por' => $solicitadaPor,
            'motivo' => $motivo,
            'estado' => 'pendiente',
            'created_at' => $this->ahora,
            'updated_at' => $this->ahora,
        ]);
    }

    /**
     * Registra una transferencia por verificar con un comprobante PDF
     *
     * No tiene recibo ni meses aplicados, así que no reduce el saldo. La registra
     * el tesorero o la reporta un vecino desde el portal.
     */
    private function transferencia(int $residencialId, int $registradoPor, int $viviendaId, string $monto, string $fecha, string $banco, string $referencia, ?string $cuenta, string $estado = 'pendiente_verificacion', string $origen = 'sistema'): void
    {
        $ruta = "transferencias/{$residencialId}/demostracion-{$referencia}.pdf";

        Storage::disk('local')->put($ruta, $this->pdfComprobante("Transferencia {$banco} ref. {$referencia} por L {$monto}"));

        DB::table('pagos')->insert([
            'residencial_id' => $residencialId,
            'vivienda_id' => $viviendaId,
            'forma_pago' => 'transferencia',
            'estado' => $estado,
            'origen' => $origen,
            'monto' => $monto,
            'fecha_pago' => $fecha,
            'registrado_por' => $registradoPor,
            'banco' => $banco,
            'numero_referencia' => $referencia,
            'cuenta_receptora' => $cuenta,
            'comprobante_path' => $ruta,
            'created_at' => $this->ahora,
            'updated_at' => $this->ahora,
        ]);
    }

    /**
     * PDF de una página con una línea de texto, para el comprobante de prueba
     */
    private function pdfComprobante(string $texto): string
    {
        $contenido = 'BT /F1 14 Tf 72 720 Td ('.$this->sinTildes($texto).') Tj ET';

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $posiciones = [];

        foreach ($objetos as $indice => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($indice + 1)." 0 obj\n{$objeto}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";

        foreach ($posiciones as $posicion) {
            $pdf .= sprintf("%010d 00000 n \n", $posicion);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function cerrar(int $cajaId, int $tesoreroId, string $entregado, string $justificacion): void
    {
        $total = DB::table('pagos')->where('caja_id', $cajaId)->where('estado', 'confirmado')->sum('monto');
        $cierre = CarbonImmutable::parse('2026-10-13 15:00:00');

        DB::table('cajas')->where('id', $cajaId)->update([
            'estado' => 'cerrada',
            'cerrada_en' => $cierre,
            'cerrada_por' => $tesoreroId,
            'total_registrado' => number_format((float) $total, 2, '.', ''),
            'monto_entregado' => $entregado,
            'justificacion' => $justificacion,
            'updated_at' => $cierre,
        ]);
    }

    private function sinTildes(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    }
}
