<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aplica la migración
     */
    public function up(): void
    {
        // La modalidad vigente de un período es la de mayor vigente_desde <= período
        Schema::create('modalidades_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->enum('modalidad', ['unificada', 'diversificada']);
            $table->date('vigente_desde');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->unique(['residencial_id', 'vigente_desde']);
        });

        // En modalidad unificada solo se cobra el concepto con es_cuota_unificada
        Schema::create('conceptos_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->string('nombre', 100);
            $table->enum('tipo', ['recurrente', 'unico']);
            $table->boolean('es_cuota_unificada')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['residencial_id', 'nombre']);
            $table->unique(['residencial_id', 'id']);
        });

        // La tarifa de una vivienda prevalece sobre la de su tipo
        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('concepto_id');
            $table->enum('tipo_vivienda', ['casa', 'apartamento'])->nullable();
            $table->foreignId('vivienda_id')->nullable();
            $table->decimal('monto', 12, 2);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->text('motivo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['residencial_id', 'concepto_id'])->references(['residencial_id', 'id'])->on('conceptos_cobro');
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->unique(['concepto_id', 'tipo_vivienda', 'vivienda_id', 'vigente_desde'], 'tarifas_vigencia_unique')->nullsNotDistinct();
        });

        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id');
            $table->foreignId('concepto_id');
            $table->date('periodo');
            $table->decimal('monto', 12, 2);
            $table->date('fecha_vencimiento')->nullable();
            $table->enum('origen', ['sistema', 'manual', 'migracion'])->default('sistema');
            $table->foreignId('lote_migracion_id')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->foreign(['residencial_id', 'concepto_id'])->references(['residencial_id', 'id'])->on('conceptos_cobro');
            $table->foreign(['residencial_id', 'lote_migracion_id'])->references(['residencial_id', 'id'])->on('lotes_migracion');
            $table->unique(['vivienda_id', 'concepto_id', 'periodo']);
            $table->unique(['residencial_id', 'id']);
            $table->index(['residencial_id', 'periodo']);
        });

        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->foreignId('cobrador_id')->constrained('users');
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->timestamp('abierta_en');
            $table->timestamp('cerrada_en')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users');
            $table->decimal('total_registrado', 12, 2)->nullable();
            $table->decimal('monto_entregado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable()->storedAs('monto_entregado - total_registrado');
            $table->text('justificacion')->nullable();
            $table->timestamps();
            $table->unique(['residencial_id', 'id']);
        });

        DB::statement("CREATE UNIQUE INDEX cajas_una_abierta_por_cobrador_unique ON cajas (cobrador_id) WHERE estado = 'abierta'");

        // Solo el estado 'confirmado' reduce el saldo de la vivienda
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id');
            $table->enum('forma_pago', ['efectivo', 'transferencia']);
            $table->enum('estado', ['confirmado', 'pendiente_verificacion', 'reportado_por_vecino', 'rechazado', 'anulado']);
            $table->enum('origen', ['sistema', 'portal', 'migracion'])->default('sistema');
            $table->decimal('monto', 12, 2);
            $table->date('fecha_pago');
            $table->string('numero_recibo', 30)->nullable();
            $table->foreignId('caja_id')->nullable()->index();
            $table->foreignId('registrado_por')->nullable()->constrained('users');
            $table->string('banco', 100)->nullable();
            $table->string('numero_referencia', 60)->nullable();
            $table->string('cuenta_receptora', 60)->nullable();
            $table->string('comprobante_path')->nullable();
            $table->foreignId('verificado_por')->nullable()->constrained('users');
            $table->timestamp('verificado_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users');
            $table->foreignId('anulacion_aprobada_por')->nullable()->constrained('users');
            $table->timestamp('anulado_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('lote_migracion_id')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->foreign(['residencial_id', 'caja_id'])->references(['residencial_id', 'id'])->on('cajas');
            $table->foreign(['residencial_id', 'lote_migracion_id'])->references(['residencial_id', 'id'])->on('lotes_migracion');
            $table->unique(['residencial_id', 'numero_recibo']);
            $table->unique(['residencial_id', 'id']);
            $table->index(['vivienda_id', 'estado']);
            $table->index(['residencial_id', 'fecha_pago']);
        });

        DB::statement("CREATE UNIQUE INDEX pagos_referencia_banco_unique ON pagos (residencial_id, lower(banco), numero_referencia) WHERE numero_referencia IS NOT NULL AND estado NOT IN ('rechazado', 'anulado')");

        // Qué parte de cada pago cubre cada cargo
        Schema::create('pago_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('pago_id');
            $table->foreignId('cargo_id')->index();
            $table->decimal('monto', 12, 2);
            $table->timestamp('created_at')->nullable();
            $table->foreign(['residencial_id', 'pago_id'])->references(['residencial_id', 'id'])->on('pagos');
            $table->foreign(['residencial_id', 'cargo_id'])->references(['residencial_id', 'id'])->on('cargos');
            $table->unique(['pago_id', 'cargo_id']);
        });

        // monto negativo reduce la deuda; positivo la aumenta
        Schema::create('ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id')->index();
            $table->enum('tipo', ['descuento', 'condonacion', 'correccion']);
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->text('motivo');
            $table->foreignId('autorizado_por')->nullable()->constrained('users');
            $table->foreignId('lote_migracion_id')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->foreign(['residencial_id', 'lote_migracion_id'])->references(['residencial_id', 'id'])->on('lotes_migracion');
        });

        // Saldo = cargos - pagos confirmados + ajustes
        DB::statement(<<<'SQL'
            CREATE VIEW v_saldos_vivienda AS
            SELECT
                s.residencial_id,
                s.vivienda_id,
                s.codigo,
                s.total_cargos,
                s.total_pagos_confirmados,
                s.total_ajustes,
                s.total_cargos - s.total_pagos_confirmados + s.total_ajustes AS saldo,
                s.total_por_verificar
            FROM (
                SELECT
                    v.residencial_id,
                    v.id AS vivienda_id,
                    v.codigo,
                    (SELECT COALESCE(SUM(c.monto), 0) FROM cargos c WHERE c.vivienda_id = v.id) AS total_cargos,
                    (SELECT COALESCE(SUM(p.monto), 0) FROM pagos p
                     WHERE p.vivienda_id = v.id AND p.estado = 'confirmado') AS total_pagos_confirmados,
                    (SELECT COALESCE(SUM(a.monto), 0) FROM ajustes a WHERE a.vivienda_id = v.id) AS total_ajustes,
                    (SELECT COALESCE(SUM(p.monto), 0) FROM pagos p
                     WHERE p.vivienda_id = v.id AND p.estado IN ('pendiente_verificacion', 'reportado_por_vecino')) AS total_por_verificar
                FROM viviendas v
            ) s
            SQL);

        Schema::create('categorias_financieras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->enum('tipo', ['ingreso', 'egreso']);
            $table->string('nombre', 100);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['residencial_id', 'tipo', 'nombre']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('egresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('categoria_id');
            $table->date('fecha');
            $table->string('concepto');
            $table->string('proveedor', 150)->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('forma_pago', 30)->nullable();
            $table->string('numero_comprobante', 60);
            $table->string('factura_path')->nullable();
            $table->enum('estado', ['vigente', 'anulado'])->default('vigente');
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['residencial_id', 'categoria_id'])->references(['residencial_id', 'id'])->on('categorias_financieras');
            $table->index(['residencial_id', 'fecha']);
        });

        Schema::create('ingresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('categoria_id');
            $table->foreignId('pago_id')->nullable()->unique();
            $table->date('fecha');
            $table->string('concepto');
            $table->decimal('monto', 12, 2);
            $table->string('numero_comprobante', 60)->nullable();
            $table->enum('estado', ['vigente', 'anulado'])->default('vigente');
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['residencial_id', 'categoria_id'])->references(['residencial_id', 'id'])->on('categorias_financieras');
            $table->foreign(['residencial_id', 'pago_id'])->references(['residencial_id', 'id'])->on('pagos');
            $table->index(['residencial_id', 'fecha']);
        });

        Schema::create('presupuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('categoria_id');
            $table->smallInteger('anio');
            $table->decimal('monto', 12, 2);
            $table->timestamps();
            $table->foreign(['residencial_id', 'categoria_id'])->references(['residencial_id', 'id'])->on('categorias_financieras');
            $table->unique(['categoria_id', 'anio']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE modalidades_cobro ADD CONSTRAINT modalidades_dia_uno_check
                    CHECK (EXTRACT(DAY FROM vigente_desde) = 1);

                ALTER TABLE tarifas
                    ADD CONSTRAINT tarifas_monto_check CHECK (monto >= 0),
                    ADD CONSTRAINT tarifas_alcance_check CHECK (tipo_vivienda IS NULL OR vivienda_id IS NULL),
                    ADD CONSTRAINT tarifas_especial_motivo_check CHECK (vivienda_id IS NULL OR motivo IS NOT NULL),
                    ADD CONSTRAINT tarifas_vigencia_check CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde);

                ALTER TABLE cargos
                    ADD CONSTRAINT cargos_periodo_check CHECK (EXTRACT(DAY FROM periodo) = 1),
                    ADD CONSTRAINT cargos_monto_check CHECK (monto >= 0);

                ALTER TABLE cajas
                    ADD CONSTRAINT cajas_cierre_completo_check
                        CHECK (estado = 'abierta'
                               OR (cerrada_en IS NOT NULL AND cerrada_por IS NOT NULL
                                   AND total_registrado IS NOT NULL AND monto_entregado IS NOT NULL)),
                    -- Toda diferencia en el cierre debe justificarse.
                    ADD CONSTRAINT cajas_diferencia_justificada_check
                        CHECK (estado = 'abierta' OR monto_entregado = total_registrado OR justificacion IS NOT NULL);

                ALTER TABLE pagos
                    ADD CONSTRAINT pagos_monto_check CHECK (monto > 0),
                    ADD CONSTRAINT pagos_transferencia_datos_check
                        CHECK (forma_pago <> 'transferencia' OR origen = 'migracion'
                               OR (banco IS NOT NULL AND numero_referencia IS NOT NULL)),
                    ADD CONSTRAINT pagos_rechazo_motivo_check CHECK (estado <> 'rechazado' OR motivo_rechazo IS NOT NULL),
                    ADD CONSTRAINT pagos_anulacion_datos_check
                        CHECK (estado <> 'anulado' OR (anulado_por IS NOT NULL AND anulado_en IS NOT NULL
                                                       AND motivo_anulacion IS NOT NULL)),
                    -- Quien aprueba una anulación debe ser otro directivo.
                    ADD CONSTRAINT pagos_aprobador_distinto_check
                        CHECK (anulacion_aprobada_por IS NULL OR anulacion_aprobada_por <> anulado_por);

                ALTER TABLE pago_aplicaciones ADD CONSTRAINT pago_aplicaciones_monto_check CHECK (monto > 0);

                ALTER TABLE ajustes
                    ADD CONSTRAINT ajustes_monto_check CHECK (monto <> 0),
                    -- Solo los datos migrados pueden no tener un usuario que autorice.
                    ADD CONSTRAINT ajustes_autorizado_check CHECK (autorizado_por IS NOT NULL OR lote_migracion_id IS NOT NULL);

                ALTER TABLE egresos
                    ADD CONSTRAINT egresos_monto_check CHECK (monto > 0),
                    ADD CONSTRAINT egresos_anulacion_check CHECK (estado <> 'anulado' OR motivo_anulacion IS NOT NULL);

                ALTER TABLE ingresos
                    ADD CONSTRAINT ingresos_monto_check CHECK (monto > 0),
                    ADD CONSTRAINT ingresos_anulacion_check CHECK (estado <> 'anulado' OR motivo_anulacion IS NOT NULL);

                ALTER TABLE presupuestos ADD CONSTRAINT presupuestos_monto_check CHECK (monto >= 0);
                SQL);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('presupuestos');
        Schema::dropIfExists('ingresos');
        Schema::dropIfExists('egresos');
        Schema::dropIfExists('categorias_financieras');
        DB::statement('DROP VIEW IF EXISTS v_saldos_vivienda');
        Schema::dropIfExists('ajustes');
        Schema::dropIfExists('pago_aplicaciones');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('cajas');
        Schema::dropIfExists('cargos');
        Schema::dropIfExists('tarifas');
        Schema::dropIfExists('conceptos_cobro');
        Schema::dropIfExists('modalidades_cobro');
    }
};
