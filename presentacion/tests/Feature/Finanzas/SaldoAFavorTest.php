<?php

/*
 * Saldo a favor: el excedente de un pago sin meses y su aplicación a los cargos siguientes
 *
 * Usan los servicios reales: el tesorero define la cuota, se generan los cargos del mes,
 * se cobra y la generación del mes siguiente aplica el saldo a favor.
 *
 * Comprobación pendiente en PostgreSQL (SQLite no permite dos transacciones a la vez):
 * un cobro sin meses que llega mientras se generan los cargos espera a que termine la
 * generación y cubre el mes nuevo como pago, no como saldo a favor.
 */

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Services\AnulacionPagoService;
use Patronato\Negocio\Services\CobroService;
use Patronato\Negocio\Services\ComprobanteService;
use Patronato\Negocio\Services\EstadoCuentaService;
use Patronato\Negocio\Services\GeneracionCargosService;
use Patronato\Negocio\Services\MoraService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\GeneracionCargos;
use Patronato\Persistencia\Models\Ingreso;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\PagoRepositorio;

beforeEach(function () {
    Storage::fake(TransferenciaService::DISCO);
    Notification::fake();

    $this->seed(RolesSeeder::class);

    Carbon::setTestNow('2026-11-15 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->usuario = fn (string $codigo, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $codigo)->value('id'),
    ]);

    $this->tesorero = ($this->usuario)('tesorero');
    $this->cobrador = ($this->usuario)('cobrador');
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE);

    // Cuota de L 500 para las casas desde noviembre
    $casa = TipoVivienda::query()->where('residencial_id', $this->residencial->id)->where('nombre', 'Casa')->sole();

    $this->actingAs($this->tesorero)
        ->put(route('finanzas.tipos-vivienda.update', $casa), ['nombre' => 'Casa', 'monto' => '500', 'vigente_desde' => '2026-11'])
        ->assertSessionHasNoErrors();

    $this->casa = Vivienda::query()->create([
        'residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => $casa->id, 'activa' => true,
    ]);

    $this->configurar = fn (array $valores, ?Residencial $residencial = null) => DB::table('configuracion_residencial')
        ->updateOrInsert(['residencial_id' => ($residencial ?? $this->residencial)->id], [...$valores, 'updated_at' => now()]);

    $this->permitirSaldo = fn (bool $permite = true) => ($this->configurar)(['permite_saldo_favor' => $permite]);

    // Genera los cargos del mes de la fecha indicada, a pedido del tesorero
    $this->generar = function (?string $fecha = null): int {
        if ($fecha !== null) {
            Carbon::setTestNow($fecha);
        }

        return app(GeneracionCargosService::class)->generarAhora($this->tesorero);
    };

    $this->cargo = fn (string $periodo, ?Vivienda $vivienda = null): int => (int) Cargo::query()
        ->withoutGlobalScopes()
        ->where('vivienda_id', ($vivienda ?? $this->casa)->id)
        ->whereDate('periodo', $periodo)
        ->value('id');

    // Cobro en efectivo con el servicio, para no depender de la sesión al cambiar de fecha
    $this->cobrar = fn (string $monto, array $cargos = []): Pago => app(CobroService::class)
        ->registrarEfectivo($this->cobrador, $this->casa->id, $monto, $cargos);

    $this->anular = function (Pago $pago): void {
        $anulaciones = app(AnulacionPagoService::class);
        $solicitud = $anulaciones->solicitar($this->tesorero, $pago, 'Se registró en la vivienda equivocada.');
        $anulaciones->aprobar($this->presidente, $solicitud);
    };

    // Aplicaciones del pago en el orden en que se guardaron: [cargo, monto, origen]
    $this->aplicaciones = fn (Pago $pago): array => DB::table('pago_aplicaciones')
        ->where('pago_id', $pago->id)
        ->orderBy('id')
        ->get()
        ->map(fn ($fila): array => [(int) $fila->cargo_id, number_format((float) $fila->monto, 2, '.', ''), $fila->origen])
        ->all();

    // Lo aplicado a un cargo por pagos confirmados, de cualquier origen
    $this->aplicadoA = fn (int $cargoId): string => number_format((float) DB::table('pago_aplicaciones')
        ->join('pagos', 'pagos.id', '=', 'pago_aplicaciones.pago_id')
        ->where('pagos.estado', Pago::CONFIRMADO)
        ->where('pago_aplicaciones.cargo_id', $cargoId)
        ->sum('pago_aplicaciones.monto'), 2, '.', '');

    $this->aplicadoDe = fn (Pago $pago): string => number_format((float) DB::table('pago_aplicaciones')->where('pago_id', $pago->id)->sum('monto'), 2, '.', '');

    $this->pendiente = fn (int $cargoId): string => app(CargosPendientes::class)->pendientesDeVivienda($this->casa->id)
        ->firstWhere('cargo_id', $cargoId)['pendiente'] ?? '0.00';

    $this->saldo = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', '');

    // Movimiento del pago en el estado de cuenta de la vivienda
    $this->movimiento = fn (Pago $pago): array => collect(app(EstadoCuentaService::class)->deVivienda($this->casa->fresh())['movimientos'])
        ->first(fn (array $movimiento): bool => $movimiento['clase'] === 'pago' && $movimiento['id'] === $pago->id);

    $this->registrarTransferencia = function (string $monto, string $referencia = 'REF-3001'): Pago {
        $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), [
            'vivienda_id' => $this->casa->id,
            'monto' => $monto,
            'fecha_pago' => TransferenciaService::hoy(),
            'banco' => 'BAC Credomatic',
            'numero_referencia' => $referencia,
            'cuenta_receptora' => '730-123456-7',
            'comprobante' => UploadedFile::fake()->createWithContent('comprobante.pdf', "%PDF-1.4\n%comprobante de prueba\n"),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Pago::query()->withoutGlobalScopes()->where('forma_pago', Pago::TRANSFERENCIA)->latest('id')->firstOrFail();
    };

    $this->confirmar = fn (Pago $pago, array $cargos = []) => $this->actingAs($this->presidente)
        ->post(route('finanzas.transferencias.verificar.confirmar', $pago), ['cargos' => $cargos]);

    ($this->generar)();
    $this->noviembre = ($this->cargo)('2026-11-01');
});

afterEach(fn () => Carbon::setTestNow());

// Excedente al cobrar

test('a cash payment without months leaves the excess as credit when the residencial allows it', function () {
    ($this->permitirSaldo)();

    $pago = ($this->cobrar)('800');

    expect(($this->aplicaciones)($pago))->toBe([[$this->noviembre, '500.00', PagoAplicacion::PAGO]])
        ->and(($this->saldo)())->toBe('-300.00')
        ->and(($this->pendiente)($this->noviembre))->toBe('0.00');

    $auditoria = Auditoria::query()->where('accion', 'pago_registrado')->sole()->valor_nuevo;

    expect($auditoria['saldo_a_favor'])->toBe('300.00')
        ->and($auditoria['abono_parcial'])->toBeFalse()
        ->and($auditoria['meses'])->toBe([$this->noviembre]);

    // El ingreso es el monto completo, excedente incluido
    expect(Ingreso::query()->withoutGlobalScopes()->where('pago_id', $pago->id)->sole()->monto)->toBe('800.00');
});

test('the excess is rejected when the residencial does not allow credit', function () {
    ($this->permitirSaldo)(false);

    expect(fn () => ($this->cobrar)('800'))->toThrow(ValidationException::class, 'El monto supera lo pendiente de la vivienda.');

    expect(Pago::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('pago_aplicaciones')->count())->toBe(0);
});

test('with chosen months the excess is still rejected even when credit is allowed', function () {
    ($this->permitirSaldo)();

    expect(fn () => ($this->cobrar)('800', [$this->noviembre]))->toThrow(ValidationException::class, 'El monto supera lo pendiente de los meses elegidos.');

    expect(Pago::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a transfer confirmed without months leaves the excess as credit', function () {
    ($this->permitirSaldo)();

    $pago = ($this->registrarTransferencia)('700');

    ($this->confirmar)($pago)->assertSessionHasNoErrors()->assertRedirect();

    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->aplicaciones)($pago))->toBe([[$this->noviembre, '500.00', PagoAplicacion::PAGO]])
        ->and(($this->saldo)())->toBe('-200.00')
        ->and(Auditoria::query()->where('accion', 'transferencia_confirmada')->sole()->valor_nuevo['saldo_a_favor'])->toBe('200.00');
});

test('a transfer with excess stays pending when the residencial does not allow credit', function () {
    $pago = ($this->registrarTransferencia)('700');

    ($this->confirmar)($pago)->assertSessionHasErrors(['cargos' => 'El monto de la transferencia supera lo pendiente de la vivienda.']);

    expect($pago->refresh()->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($pago->numero_recibo)->toBeNull()
        ->and(DB::table('pago_aplicaciones')->count())->toBe(0);
});

// Vivienda sin pendientes

test('a housing unit with nothing pending can pay in advance when credit is allowed', function () {
    ($this->permitirSaldo)();
    ($this->cobrar)('500');

    $this->actingAs($this->cobrador)
        ->get(route('finanzas.cobros.create', ['vivienda' => $this->casa->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pendientes', [])->where('permiteSaldoFavor', true));

    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500'])
        ->assertSessionHasNoErrors();

    $adelanto = Pago::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

    // Todo queda como saldo a favor; el comprobante no tiene períodos
    expect(($this->aplicaciones)($adelanto))->toBe([])
        ->and($adelanto->numero_recibo)->not->toBeNull()
        ->and(app(ComprobanteService::class)->datos($adelanto)['periodos'])->toBe([])
        ->and(($this->saldo)())->toBe('-500.00');
});

test('a transfer for a housing unit with nothing pending becomes credit when allowed', function () {
    ($this->permitirSaldo)();
    ($this->cobrar)('500');

    $pago = ($this->registrarTransferencia)('500');

    $this->actingAs($this->presidente)
        ->get(route('finanzas.transferencias.verificar.show', $pago))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('transferencia.pendientes', [])
            ->where('transferencia.permite_saldo_favor', true));

    ($this->confirmar)($pago)->assertSessionHasNoErrors();

    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->aplicaciones)($pago))->toBe([])
        ->and(($this->saldo)())->toBe('-500.00');
});

test('no months are suggested when the transfer exceeds everything pending and credit is allowed', function () {
    $pago = ($this->registrarTransferencia)('700');

    $sugeridos = fn () => $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.show', $pago));

    // Sin saldo a favor se sugieren los meses como hasta ahora
    $sugeridos()->assertInertia(fn (AssertableInertia $page) => $page->where('transferencia.sugeridos', [$this->noviembre]));

    ($this->permitirSaldo)();

    $sugeridos()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('transferencia.sugeridos', [])
        ->where('transferencia.permite_saldo_favor', true));
});

// Aplicación al generar los cargos

test('the next charge generation applies the credit and clears the overdue months', function () {
    ($this->permitirSaldo)();

    $pago = ($this->cobrar)('1200');

    ($this->generar)('2026-12-15 15:00:00');

    $diciembre = ($this->cargo)('2026-12-01');

    expect(($this->aplicaciones)($pago))->toBe([
        [$this->noviembre, '500.00', PagoAplicacion::PAGO],
        [$diciembre, '500.00', PagoAplicacion::SALDO_A_FAVOR],
    ])
        ->and(($this->saldo)())->toBe('-200.00')
        ->and(app(MoraService::class)->estadoDe($this->residencial->id, $this->casa->id)['meses_vencidos'])->toBe(0);

    $auditoria = Auditoria::query()->where('accion', 'saldo_favor_aplicado')->sole();

    expect($auditoria->entidad_id)->toBe($pago->id)
        ->and($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->valor_nuevo)->toBe(['cargos' => [$diciembre], 'monto' => '500.00', 'disponible_restante' => '200.00']);
});

test('the scheduled generation applies the credit as the system', function () {
    ($this->permitirSaldo)();
    ($this->cobrar)('1000');

    Carbon::setTestNow('2026-12-15 15:00:00');
    $this->artisan('cargos:generar')->assertSuccessful();

    expect(Auditoria::query()->where('accion', 'saldo_favor_aplicado')->sole()->user_id)->toBeNull()
        ->and(($this->pendiente)(($this->cargo)('2026-12-01')))->toBe('0.00');
});

test('existing credit keeps applying after the residencial stops accepting new credit', function () {
    ($this->permitirSaldo)();
    $pago = ($this->cobrar)('1000');

    ($this->permitirSaldo)(false);

    ($this->generar)('2026-12-15 15:00:00');

    $diciembre = ($this->cargo)('2026-12-01');

    expect(($this->aplicaciones)($pago)[1])->toBe([$diciembre, '500.00', PagoAplicacion::SALDO_A_FAVOR])
        ->and(($this->pendiente)($diciembre))->toBe('0.00');

    // Un excedente nuevo ya no se acepta
    expect(fn () => ($this->cobrar)('100'))->toThrow(ValidationException::class, 'El monto supera lo pendiente de la vivienda.');
});

test('credit smaller than the new charge leaves it partly paid and the payment that completes it is not a partial payment', function () {
    ($this->permitirSaldo)();
    $conSaldo = ($this->cobrar)('700');

    ($this->generar)('2026-12-15 15:00:00');

    $diciembre = ($this->cargo)('2026-12-01');

    expect(($this->pendiente)($diciembre))->toBe('300.00');

    $completa = ($this->cobrar)('300', [$diciembre]);

    expect(($this->movimiento)($conSaldo)['tipo'])->toBe('pago')
        ->and(($this->movimiento)($completa)['tipo'])->toBe('pago')
        ->and(($this->pendiente)($diciembre))->toBe('0.00');
});

test('credit is used from the oldest payment by date and then by number', function () {
    ($this->permitirSaldo)();
    ($this->cobrar)('500');

    $primero = ($this->cobrar)('300');
    $segundo = ($this->cobrar)('300');

    // El segundo tiene número mayor pero fecha anterior: se usa primero
    DB::table('pagos')->where('id', $segundo->id)->update(['fecha_pago' => '2026-11-10']);

    ($this->generar)('2026-12-15 15:00:00');

    $diciembre = ($this->cargo)('2026-12-01');

    expect(($this->aplicaciones)($segundo))->toBe([[$diciembre, '300.00', PagoAplicacion::SALDO_A_FAVOR]])
        ->and(($this->aplicaciones)($primero))->toBe([[$diciembre, '200.00', PagoAplicacion::SALDO_A_FAVOR]]);
});

test('migrated payments do not count as credit', function () {
    // Un pago migrado sin aplicaciones (pagos.origen = 'migracion')
    $migrado = Pago::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'forma_pago' => Pago::EFECTIVO,
        'estado' => Pago::CONFIRMADO,
        'origen' => 'migracion',
        'monto' => '1000.00',
        'fecha_pago' => '2026-10-01',
    ]);

    ($this->generar)('2026-12-15 15:00:00');

    expect(($this->aplicaciones)($migrado))->toBe([])
        ->and(($this->pendiente)($this->noviembre))->toBe('500.00')
        ->and(($this->pendiente)(($this->cargo)('2026-12-01')))->toBe('500.00');
});

test('credit of another residencial is not used', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro residencial']);
    $vivienda = Vivienda::query()->create([
        'residencial_id' => $otro->id, 'codigo' => 'B-01', 'tipo_vivienda_id' => tipoVivienda($otro->id), 'activa' => true,
    ]);

    $ajeno = Pago::query()->create([
        'residencial_id' => $otro->id,
        'vivienda_id' => $vivienda->id,
        'forma_pago' => Pago::EFECTIVO,
        'estado' => Pago::CONFIRMADO,
        'origen' => 'sistema',
        'monto' => '1000.00',
        'fecha_pago' => '2026-11-01',
    ]);

    ($this->generar)('2026-12-15 15:00:00');

    expect(($this->aplicaciones)($ajeno))->toBe([])
        ->and(($this->pendiente)(($this->cargo)('2026-12-01')))->toBe('500.00');
});

// Comprobante y estado de cuenta

test('applying the credit later does not change the receipt nor the payment in the account statement', function () {
    ($this->permitirSaldo)();
    $pago = ($this->cobrar)('1200');

    $comprobante = app(ComprobanteService::class)->datos($pago);
    $movimiento = ($this->movimiento)($pago);

    ($this->generar)('2026-12-15 15:00:00');

    expect(app(ComprobanteService::class)->datos($pago)['periodos'])->toBe($comprobante['periodos'])
        ->and(($this->movimiento)($pago)['meses'])->toBe($movimiento['meses'])
        ->and(($this->movimiento)($pago)['tipo'])->toBe($movimiento['tipo'])
        ->and($movimiento['meses'])->toHaveCount(1);
});

// Anulaciones

test('voiding another payment leaves the credit waiting until the next generation, even without new charges', function () {
    ($this->configurar)(['permite_saldo_favor' => true, 'permite_abono_parcial' => true]);

    $abono = ($this->cobrar)('300', [$this->noviembre]);
    $conSaldo = ($this->cobrar)('1000');

    ($this->anular)($abono);

    // Noviembre vuelve a deber lo que cubría el pago anulado y el saldo no se aplica todavía
    expect(($this->pendiente)($this->noviembre))->toBe('300.00')
        ->and(($this->aplicadoDe)($conSaldo))->toBe('200.00');

    // Generar otra vez el mismo mes no crea cargos, pero aplica el saldo
    expect(($this->generar)())->toBe(0)
        ->and(($this->pendiente)($this->noviembre))->toBe('0.00')
        ->and(($this->aplicaciones)($conSaldo))->toBe([
            [$this->noviembre, '200.00', PagoAplicacion::PAGO],
            [$this->noviembre, '300.00', PagoAplicacion::SALDO_A_FAVOR],
        ]);
});

test('case A: the same payment covers a charge at collection and later with its credit', function () {
    ($this->configurar)(['permite_saldo_favor' => true, 'permite_abono_parcial' => true]);

    $abono = ($this->cobrar)('300', [$this->noviembre]);
    $conSaldo = ($this->cobrar)('1000');

    ($this->anular)($abono);
    ($this->generar)('2026-12-15 15:00:00');

    $diciembre = ($this->cargo)('2026-12-01');

    expect(($this->aplicaciones)($conSaldo))->toBe([
        [$this->noviembre, '200.00', PagoAplicacion::PAGO],
        [$this->noviembre, '300.00', PagoAplicacion::SALDO_A_FAVOR],
        [$diciembre, '500.00', PagoAplicacion::SALDO_A_FAVOR],
    ])
        ->and(($this->aplicadoA)($this->noviembre))->toBe('500.00')
        ->and(($this->aplicadoA)($diciembre))->toBe('500.00')
        ->and(($this->aplicadoDe)($conSaldo))->toBe('1000.00');

    // El comprobante sigue mostrando solo lo aplicado al cobrar
    expect(app(ComprobanteService::class)->datos($conSaldo)['periodos'])->toHaveCount(1);
});

test('case B: the same payment covers a charge twice with its credit', function () {
    ($this->permitirSaldo)();

    $antiguo = ($this->cobrar)('600');
    $conSaldo = ($this->cobrar)('1000');

    ($this->generar)('2026-12-15 15:00:00');

    $diciembre = ($this->cargo)('2026-12-01');

    ($this->anular)($antiguo);
    ($this->generar)('2027-01-15 15:00:00');

    $enero = ($this->cargo)('2027-01-01');

    expect(($this->aplicaciones)($conSaldo))->toBe([
        [$diciembre, '400.00', PagoAplicacion::SALDO_A_FAVOR],
        [$this->noviembre, '500.00', PagoAplicacion::SALDO_A_FAVOR],
        [$diciembre, '100.00', PagoAplicacion::SALDO_A_FAVOR],
    ])
        ->and(($this->aplicadoA)($diciembre))->toBe('500.00')
        ->and(($this->aplicadoA)($this->noviembre))->toBe('500.00')
        ->and(($this->aplicadoDe)($conSaldo))->toBe('1000.00')
        ->and(($this->pendiente)($enero))->toBe('500.00');
});

test('voiding the payment that had credit reopens the charges it covered', function () {
    ($this->permitirSaldo)();
    $pago = ($this->cobrar)('1000');

    ($this->generar)('2026-12-15 15:00:00');
    ($this->anular)($pago);

    expect(($this->pendiente)($this->noviembre))->toBe('500.00')
        ->and(($this->pendiente)(($this->cargo)('2026-12-01')))->toBe('500.00')
        ->and(($this->saldo)())->toBe('1000.00');
});

test('a voided payment does not apply its credit in a later generation', function () {
    ($this->permitirSaldo)();
    $anulado = ($this->cobrar)('1000');

    ($this->anular)($anulado);
    ($this->generar)('2026-12-15 15:00:00');

    expect(DB::table('pago_aplicaciones')->where('pago_id', $anulado->id)->where('origen', PagoAplicacion::SALDO_A_FAVOR)->exists())->toBeFalse()
        ->and(Auditoria::query()->where('accion', 'saldo_favor_aplicado')->exists())->toBeFalse()
        ->and(($this->pendiente)(($this->cargo)('2026-12-01')))->toBe('500.00');
});

test('the payments with credit are locked until the end of the transaction', function () {
    // Con FOR UPDATE, si una anulación de esos pagos está en curso, la lectura espera a que
    // termine y ya no devuelve el pago anulado. SQLite no bloquea filas, así que se revisa la
    // consulta que se enviaría a PostgreSQL sin ejecutarla.
    $conexion = DB::connection();
    $gramatica = $conexion->getQueryGrammar();
    $conexion->setQueryGrammar(new PostgresGrammar($conexion));

    try {
        $consultas = $conexion->pretend(fn () => app(PagoRepositorio::class)->conSaldoDisponible($this->residencial->id));
    } finally {
        $conexion->setQueryGrammar($gramatica);
    }

    expect($consultas)->toHaveCount(1)
        ->and($consultas[0]['query'])->toEndWith('for update');
});

// Integridad

test('if applying the credit fails the whole generation is undone', function () {
    ($this->permitirSaldo)();
    ($this->cobrar)('1000');

    $this->app->instance(CargosPendientes::class, new class implements CargosPendientes
    {
        public function pendientesDeVivienda(int $viviendaId): Collection
        {
            throw new RuntimeException('Falla al leer los pendientes');
        }
    });

    expect(fn () => ($this->generar)('2026-12-15 15:00:00'))->toThrow(RuntimeException::class, 'Falla al leer los pendientes');

    expect(Cargo::query()->withoutGlobalScopes()->whereDate('periodo', '2026-12-01')->exists())->toBeFalse()
        ->and(GeneracionCargos::query()->withoutGlobalScopes()->whereDate('periodo', '2026-12-01')->exists())->toBeFalse()
        ->and(DB::table('pago_aplicaciones')->where('origen', PagoAplicacion::SALDO_A_FAVOR)->exists())->toBeFalse();
});

test('the generation takes the residencial lock before creating charges', function () {
    $orden = new ArrayObject;

    $this->app->instance(PagoRepositorio::class, new class($orden) extends PagoRepositorio
    {
        public function __construct(private ArrayObject $orden) {}

        public function bloquear(int $residencialId): void
        {
            $this->orden[] = 'bloquear';
        }
    });

    $this->app->instance(CargoRepositorio::class, new class($orden) extends CargoRepositorio
    {
        public function __construct(private ArrayObject $orden) {}

        public function crearSiNoExiste(array $datos): bool
        {
            $this->orden[] = 'crear';

            return parent::crearSiNoExiste($datos);
        }
    });

    ($this->generar)('2026-12-15 15:00:00');

    expect($orden->getArrayCopy()[0])->toBe('bloquear')
        ->and($orden->getArrayCopy())->toContain('crear');
});

// Migración

test('the migration cannot be reverted once credit has been applied', function () {
    $migracion = require database_path('migrations/2026_11_21_100001_agregar_origen_a_pago_aplicaciones.php');

    ($this->permitirSaldo)();
    ($this->cobrar)('1000');
    ($this->generar)('2026-12-15 15:00:00');

    $consultas = [];
    DB::listen(function ($consulta) use (&$consultas) {
        $consultas[] = strtolower($consulta->sql);
    });

    expect(fn () => $migracion->down())->toThrow(RuntimeException::class, 'No se puede revertir: existen 1 aplicaciones de saldo a favor.');

    // En PostgreSQL la tabla se bloquea antes de revisar; en SQLite no se envía el bloqueo
    if (DB::getDriverName() === 'pgsql') {
        expect($consultas[0])->toBe('lock table pago_aplicaciones in share row exclusive mode');
    } else {
        expect(collect($consultas)->filter(fn (string $sql): bool => str_contains($sql, 'lock table')))->toBeEmpty();
    }

    // Nada cambió: la columna y los índices siguen
    expect(DB::table('pago_aplicaciones')->where('origen', PagoAplicacion::SALDO_A_FAVOR)->count())->toBe(1)
        ->and(array_column(Schema::getIndexes('pago_aplicaciones'), 'name'))
        ->toContain('pago_aplicaciones_pago_cargo_pago_unique')
        ->toContain('pago_aplicaciones_pago_id_index');
});

test('the migration keeps an index on the payment of each application', function () {
    $indice = collect(Schema::getIndexes('pago_aplicaciones'))->firstWhere('name', 'pago_aplicaciones_pago_id_index');

    expect($indice)->not->toBeNull()
        ->and($indice['columns'])->toBe(['pago_id'])
        ->and($indice['unique'])->toBeFalse();
});

test('the migration reverts cleanly when there is no credit applied', function () {
    $migracion = require database_path('migrations/2026_11_21_100001_agregar_origen_a_pago_aplicaciones.php');

    $pago = ($this->cobrar)('500');

    $migracion->down();

    $indices = array_column(Schema::getIndexes('pago_aplicaciones'), 'name');

    expect(Schema::hasColumn('pago_aplicaciones', 'origen'))->toBeFalse()
        ->and($indices)->toContain('pago_aplicaciones_pago_id_cargo_id_unique')
        ->and($indices)->not->toContain('pago_aplicaciones_pago_id_index')
        ->and($indices)->not->toContain('pago_aplicaciones_pago_cargo_pago_unique');

    // El índice único original vuelve a impedir aplicar dos veces el pago al mismo cargo;
    // la transacción interna deja a PostgreSQL seguir después del error
    expect(fn () => DB::transaction(fn () => DB::table('pago_aplicaciones')->insert([
        'residencial_id' => $this->residencial->id,
        'pago_id' => $pago->id,
        'cargo_id' => $this->noviembre,
        'monto' => '1.00',
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    $migracion->up();

    expect(Schema::hasColumn('pago_aplicaciones', 'origen'))->toBeTrue()
        ->and(array_column(Schema::getIndexes('pago_aplicaciones'), 'name'))
        ->toContain('pago_aplicaciones_pago_id_index')
        ->toContain('pago_aplicaciones_pago_cargo_pago_unique')
        ->not->toContain('pago_aplicaciones_pago_id_cargo_id_unique');
});
