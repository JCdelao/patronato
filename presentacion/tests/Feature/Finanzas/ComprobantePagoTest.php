<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Notificaciones\ComprobantePago;
use Patronato\Negocio\Services\ComprobanteService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas', 'direccion' => 'Tegucigalpa']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
        ...$datos,
    ]);

    $this->cobrador = ($this->usuario)('cobrador', null, ['name' => 'Marta Cobradora']);
    $this->tesorero = ($this->usuario)('tesorero');

    $this->vivienda = fn (Residencial $residencial, string $codigo): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)($this->residencial, 'A-01');

    $this->cargo = function (Vivienda $vivienda, string $periodo, string $monto = '500.00'): int {
        $concepto = DB::table('conceptos_cobro')->where('residencial_id', $vivienda->residencial_id)->value('id')
            ?? DB::table('conceptos_cobro')->insertGetId([
                'residencial_id' => $vivienda->residencial_id,
                'nombre' => 'Cuota mensual',
                'tipo' => 'recurrente',
                'es_cuota_unificada' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return DB::table('cargos')->insertGetId([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'concepto_id' => $concepto,
            'periodo' => $periodo,
            'monto' => $monto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // Pago en efectivo con sus meses aplicados, como lo deja la HU-19
    $this->pago = function (User $cobrador, Vivienda $vivienda, array $meses, ?string $recibo = '1') {
        $pago = Pago::query()->create([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'forma_pago' => Pago::EFECTIVO,
            'estado' => Pago::CONFIRMADO,
            'origen' => 'sistema',
            'monto' => number_format(array_sum($meses), 2, '.', ''),
            'fecha_pago' => '2026-11-08',
            'numero_recibo' => $recibo,
            'registrado_por' => $cobrador->id,
        ]);

        foreach ($meses as $cargoId => $monto) {
            PagoAplicacion::query()->create([
                'residencial_id' => $vivienda->residencial_id,
                'pago_id' => $pago->id,
                'cargo_id' => $cargoId,
                'monto' => $monto,
            ]);
        }

        return $pago;
    };

    $this->travelTo('2026-11-08 16:30:00');

    $this->enero = ($this->cargo)($this->casa, '2026-01-01');
    $this->febrero = ($this->cargo)($this->casa, '2026-02-01');

    // Febrero se aplica primero para comprobar que los períodos salen ordenados
    $this->registrado = ($this->pago)($this->cobrador, $this->casa, [$this->febrero => '200.00', $this->enero => '500.00'], '15');

    // Logo PNG de 1x1 escrito con sus bytes, sin depender de la extensión GD
    $this->subirLogo = function (Residencial $residencial): string {
        $ruta = UploadedFile::fake()
            ->createWithContent('logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='))
            ->store('logos', 'public');

        $residencial->forceFill(['logo_path' => $ruta])->save();

        return (string) $ruta;
    };

    $this->residente = function (Vivienda $vivienda, ?string $correo, array $datos = [], bool $responsable = false): Residente {
        $residente = Residente::query()->create([
            'residencial_id' => $vivienda->residencial_id,
            'nombres' => 'Ana',
            'apellidos' => 'López',
            'correo' => $correo,
            'activo' => true,
            ...$datos,
        ]);

        ViviendaResidente::query()->create([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'residente_id' => $residente->id,
            'tipo' => 'propietario',
            'es_responsable_pago' => $responsable,
        ]);

        return $residente;
    };
});

test('registering a cash payment opens its receipt', function () {
    Caja::query()->create([
        'residencial_id' => $this->residencial->id,
        'cobrador_id' => $this->cobrador->id,
        'estado' => 'abierta',
        'abierta_en' => now(),
    ]);

    $marzo = ($this->cargo)($this->casa, '2026-03-01');

    $this->app->instance(CargosPendientes::class, new class($marzo) implements CargosPendientes
    {
        public function __construct(private int $marzo) {}

        public function pendientesDeVivienda(int $viviendaId): Collection
        {
            return collect([['cargo_id' => $this->marzo, 'periodo' => '2026-03-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00', 'pendiente' => '500.00']]);
        }
    });

    $respuesta = $this->actingAs($this->cobrador)->post(route('finanzas.cobros.store'), [
        'vivienda_id' => $this->casa->id,
        'monto' => '500',
        'cargos' => [$marzo],
    ]);

    $pago = Pago::query()->latest('id')->firstOrFail();

    $respuesta->assertSessionHasNoErrors()->assertRedirect(route('finanzas.comprobantes.show', $pago));
});

test('the receipt shows the number, date, home, amount, paid periods and collector', function () {
    $this->actingAs($this->cobrador)
        ->get(route('finanzas.comprobantes.show', $this->registrado))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/comprobante')
            ->where('comprobante.numero_recibo', '15')
            ->where('comprobante.fecha', '2026-11-08')
            ->where('comprobante.hora', '10:30')
            ->where('comprobante.vivienda', 'A-01')
            ->where('comprobante.monto', '700.00')
            ->where('comprobante.cobrador', 'Marta Cobradora')
            ->where('comprobante.residencial', 'Las Palmas')
            ->has('comprobante.periodos', 2)
            ->where('comprobante.periodos.0', ['periodo' => '2026-01-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00'])
            ->where('comprobante.periodos.1', ['periodo' => '2026-02-01', 'concepto' => 'Cuota mensual', 'monto' => '200.00'])
            ->where('puedeCobrar', true));
});

test('the pdf template shows the receipt data and the logo of the residential', function () {
    Storage::fake('public');
    $logo = ($this->subirLogo)($this->residencial);

    $html = view('pdf.comprobante', [
        'comprobante' => app(ComprobanteService::class)->datos($this->registrado),
        'logo' => 'data:image/png;base64,'.base64_encode((string) Storage::disk('public')->get($logo)),
    ])->render();

    expect($html)->toContain('Recibo N.º 15')
        ->toContain('8 de noviembre de 2026, 10:30')
        ->toContain('A-01')
        ->toContain('L 700.00')
        ->toContain('Enero de 2026')
        ->toContain('Febrero de 2026')
        ->toContain('Marta Cobradora')
        ->toContain('Las Palmas')
        ->toContain('data:image/png;base64,');
});

test('the receipt can be downloaded as a pdf from the payment record', function () {
    Storage::fake('public');
    ($this->subirLogo)($this->residencial);

    $respuesta = $this->actingAs($this->cobrador)->get(route('finanzas.comprobantes.pdf', $this->registrado));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="comprobante-15.pdf"');

    expect(substr((string) $respuesta->getContent(), 0, 5))->toBe('%PDF-');
});

test('the email field is filled with the current residents of the home', function () {
    ($this->residente)($this->casa, 'ana@correo.hn', [], true);
    ($this->residente)($this->casa, 'luis@correo.hn', ['nombres' => 'Luis', 'identidad' => '0801']);
    ($this->residente)($this->casa, null, ['nombres' => 'Sin correo', 'identidad' => '0802']);
    ($this->residente)($this->casa, 'inactivo@correo.hn', ['nombres' => 'Inactivo', 'identidad' => '0803', 'activo' => false]);

    $exResidente = ($this->residente)($this->casa, 'antes@correo.hn', ['nombres' => 'Antes', 'identidad' => '0804']);
    ViviendaResidente::query()->where('residente_id', $exResidente->id)->update(['fecha_fin' => '2026-06-30']);

    $otraCasa = ($this->vivienda)($this->residencial, 'A-02');
    ($this->residente)($otraCasa, 'vecino@correo.hn', ['nombres' => 'Vecino', 'identidad' => '0805']);

    $this->actingAs($this->cobrador)
        ->get(route('finanzas.comprobantes.show', $this->registrado))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('correos', ['ana@correo.hn', 'luis@correo.hn']));
});

test('the receipt is sent by email to the addresses the collector chooses', function () {
    Notification::fake();
    ($this->residente)($this->casa, 'ana@correo.hn');

    $this->actingAs($this->cobrador)
        ->from(route('finanzas.comprobantes.show', $this->registrado))
        ->post(route('finanzas.comprobantes.enviar', $this->registrado), ['correos' => 'otro@correo.hn, ana@correo.hn'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.comprobantes.show', $this->registrado));

    Notification::assertSentOnDemand(ComprobantePago::class, function (ComprobantePago $aviso, array $canales, AnonymousNotifiable $destino) {
        $correo = $aviso->toMail($destino);

        return $destino->routes['mail'] === ['otro@correo.hn', 'ana@correo.hn']
            && $aviso->numeroRecibo === '15'
            && str_starts_with($aviso->pdf, '%PDF-')
            && $correo->rawAttachments[0]['name'] === 'comprobante-15.pdf'
            && $correo->rawAttachments[0]['options']['mime'] === 'application/pdf';
    });

    $registro = Auditoria::query()->where('accion', 'comprobante_enviado')->sole();

    expect($registro->modulo)->toBe('cobros')
        ->and($registro->user_id)->toBe($this->cobrador->id)
        ->and($registro->entidad_id)->toBe($this->registrado->id)
        ->and($registro->valor_nuevo['correos'])->toBe(['otro@correo.hn', 'ana@correo.hn']);
});

test('the email addresses are validated before sending', function (string $correos) {
    Notification::fake();

    $this->actingAs($this->cobrador)
        ->post(route('finanzas.comprobantes.enviar', $this->registrado), ['correos' => $correos])
        ->assertSessionHasErrors('correos');

    Notification::assertNothingSent();
})->with([
    'empty' => [''],
    'invalid' => ['ana@correo.hn, no-es-correo'],
    'too many' => ['a@x.hn, b@x.hn, c@x.hn, d@x.hn, e@x.hn, f@x.hn'],
]);

test('the share link opens the pdf without logging in and expires in 30 days', function () {
    $enlace = null;

    $this->actingAs($this->cobrador)
        ->get(route('finanzas.comprobantes.show', $this->registrado))
        ->assertInertia(function (AssertableInertia $page) use (&$enlace) {
            $enlace = $page->toArray()['props']['enlace'];

            return $page->where('diasEnlace', 30);
        });

    auth()->logout();

    $this->get($enlace)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="comprobante-15.pdf"');

    // Cambiar el pago del enlace invalida la firma
    $this->get(str_replace("comprobantes/{$this->registrado->id}?", 'comprobantes/999?', $enlace))->assertForbidden();

    $this->travel(31)->days();
    $this->get($enlace)->assertForbidden();
});

test('the share link opens even if a user of another residential is logged in', function () {
    $enlace = URL::temporarySignedRoute('comprobantes.publico', now()->addDays(30), ['pago' => $this->registrado->id]);

    $this->actingAs(($this->usuario)('tesorero', $this->otro))->get($enlace)->assertOk();

    // El PDF lleva los períodos aunque la sesión sea de otro residencial
    expect(app(ComprobanteService::class)->datos(Pago::query()->withoutGlobalScopes()->findOrFail($this->registrado->id))['periodos'])->toHaveCount(2);
});

test('only the collector of the payment and users with the view payments permission see the receipt', function () {
    $rutas = [
        fn () => $this->get(route('finanzas.comprobantes.show', $this->registrado)),
        fn () => $this->get(route('finanzas.comprobantes.pdf', $this->registrado)),
        fn () => $this->post(route('finanzas.comprobantes.enviar', $this->registrado), ['correos' => 'ana@correo.hn']),
    ];

    Notification::fake();

    foreach ($rutas as $ruta) {
        $ruta()->assertRedirect(route('login'));
    }

    $otroCobrador = ($this->usuario)('cobrador');
    $vocal = ($this->usuario)('vocal');

    foreach ([$otroCobrador, $vocal] as $usuario) {
        $this->actingAs($usuario);

        foreach ($rutas as $ruta) {
            $ruta()->assertForbidden();
        }
    }

    foreach ([$this->cobrador, $this->tesorero] as $usuario) {
        $this->actingAs($usuario)->get(route('finanzas.comprobantes.show', $this->registrado))->assertOk();
        $this->actingAs($usuario)->get(route('finanzas.comprobantes.pdf', $this->registrado))->assertOk();
    }

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.comprobantes.show', $this->registrado))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeCobrar', false));

    Notification::assertCount(0);
});

test('existing residentials give the treasurer the view payments permission', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'cobros')->where('accion', 'ver')->delete();

    $tesorero = ($this->usuario)('tesorero', $antiguo);
    $casa = ($this->vivienda)($antiguo, 'B-01');
    $pago = ($this->pago)(($this->usuario)('cobrador', $antiguo), $casa, [($this->cargo)($casa, '2026-01-01') => '500.00']);

    $this->actingAs($tesorero)->get(route('finanzas.comprobantes.show', $pago))->assertForbidden();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_13_100001_asignar_cobros_ver_al_tesorero.php');
    $migracion->up();
    $migracion->up();

    expect(DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('rol_id', ($this->rol)('tesorero'))->where('modulo', 'cobros')->where('accion', 'ver')->count())->toBe(1);

    $this->actingAs($tesorero)->get(route('finanzas.comprobantes.show', $pago))->assertOk();
});

test('a receipt from another residential is denied and audited', function () {
    $tesoreroAjeno = ($this->usuario)('tesorero', $this->otro);

    $this->actingAs($tesoreroAjeno)->get(route('finanzas.comprobantes.show', $this->registrado))->assertForbidden();
    $this->actingAs($tesoreroAjeno)->get(route('finanzas.comprobantes.pdf', $this->registrado))->assertForbidden();

    $registro = Auditoria::query()->withoutGlobalScopes()->where('accion', 'acceso_denegado')->latest('id')->firstOrFail();

    expect($registro->user_id)->toBe($tesoreroAjeno->id)
        ->and($registro->entidad)->toBe('pagos')
        ->and($registro->entidad_id)->toBe($this->registrado->id);
});

test('payments without a receipt number have no receipt', function () {
    $sinRecibo = ($this->pago)($this->cobrador, $this->casa, [], null);

    $this->actingAs($this->cobrador)->get(route('finanzas.comprobantes.show', $sinRecibo))->assertNotFound();

    $enlace = URL::temporarySignedRoute('comprobantes.publico', now()->addDays(30), ['pago' => $sinRecibo->id]);
    auth()->logout();
    $this->get($enlace)->assertNotFound();
});
