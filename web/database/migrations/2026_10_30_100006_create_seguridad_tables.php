<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guardias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users');
            $table->string('nombre', 150);
            $table->string('identidad', 30);
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['residencial_id', 'identidad']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('guardia_id');
            $table->timestamp('inicio');
            $table->timestamp('fin');
            $table->timestamp('inicio_real')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'guardia_id'])->references(['residencial_id', 'id'])->on('guardias');
            $table->unique(['residencial_id', 'id']);
            $table->index(['residencial_id', 'inicio']);
        });

        Schema::create('entregas_turno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('turno_id')->unique();
            $table->foreignId('guardia_entrega_id');
            $table->foreignId('guardia_recibe_id');
            $table->timestamp('entregado_en');
            $table->text('novedades')->nullable();
            $table->timestamp('recibido_en')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'turno_id'])->references(['residencial_id', 'id'])->on('turnos');
            $table->foreign(['residencial_id', 'guardia_entrega_id'])->references(['residencial_id', 'id'])->on('guardias');
            $table->foreign(['residencial_id', 'guardia_recibe_id'])->references(['residencial_id', 'id'])->on('guardias');
        });

        Schema::create('tipos_incidencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->unique(['residencial_id', 'nombre']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('incidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('tipo_incidencia_id');
            $table->foreignId('guardia_id');
            $table->foreignId('turno_id')->nullable();
            $table->text('descripcion');
            $table->timestamp('ocurrida_en');
            $table->string('foto_path')->nullable();
            $table->boolean('urgente')->default(false);
            $table->timestamps();
            $table->foreign(['residencial_id', 'tipo_incidencia_id'])->references(['residencial_id', 'id'])->on('tipos_incidencia');
            $table->foreign(['residencial_id', 'guardia_id'])->references(['residencial_id', 'id'])->on('guardias');
            $table->foreign(['residencial_id', 'turno_id'])->references(['residencial_id', 'id'])->on('turnos');
            $table->index(['residencial_id', 'ocurrida_en']);
        });

        Schema::create('medios_entrada', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->string('nombre', 40);
            $table->boolean('requiere_placa')->default(false);
            $table->boolean('activo')->default(true);
            $table->unique(['residencial_id', 'nombre']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('preautorizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id');
            $table->foreignId('residente_id');
            $table->foreignId('medio_entrada_id')->nullable();
            $table->string('nombre_visitante', 150);
            $table->timestamp('inicio');
            $table->timestamp('fin');
            $table->uuid('qr_token')->nullable()->unique();
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->foreign(['residencial_id', 'residente_id'])->references(['residencial_id', 'id'])->on('residentes');
            $table->foreign(['residencial_id', 'medio_entrada_id'])->references(['residencial_id', 'id'])->on('medios_entrada');
            $table->unique(['residencial_id', 'id']);
            $table->index(['vivienda_id', 'inicio']);
        });

        Schema::create('autorizados_frecuentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->string('nombre', 150);
            $table->string('identidad', 30)->nullable();
            $table->string('tipo', 40);
            // Lista de días ISO 8601: 1 = lunes ... 7 = domingo.
            $table->jsonb('dias_semana');
            $table->time('hora_inicio', 0);
            $table->time('hora_fin', 0);
            $table->boolean('activo')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('autorizado_frecuente_viviendas', function (Blueprint $table) {
            $table->foreignId('residencial_id');
            $table->foreignId('autorizado_id');
            $table->foreignId('vivienda_id');
            $table->primary(['autorizado_id', 'vivienda_id']);
            $table->foreign(['residencial_id', 'autorizado_id'], 'autorizado_viviendas_autorizado_foreign')->references(['residencial_id', 'id'])->on('autorizados_frecuentes');
            $table->foreign(['residencial_id', 'vivienda_id'], 'autorizado_viviendas_vivienda_foreign')->references(['residencial_id', 'id'])->on('viviendas');
        });

        // identificador lo genera el dispositivo: evita duplicados al sincronizar sin conexión (HU-42, HU-51).
        Schema::create('visitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->uuid('identificador')->unique();
            $table->foreignId('vivienda_id')->index();
            $table->foreignId('medio_entrada_id');
            $table->string('nombre_visitante', 150);
            $table->string('identidad', 30)->nullable();
            $table->string('motivo')->nullable();
            $table->string('placa', 20)->nullable();
            $table->timestamp('entrada_en');
            $table->timestamp('salida_en')->nullable();
            $table->foreignId('guardia_entrada_id');
            $table->foreignId('guardia_salida_id')->nullable();
            $table->foreignId('preautorizacion_id')->nullable();
            $table->foreignId('autorizado_id')->nullable();
            $table->boolean('registrada_sin_conexion')->default(false);
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->foreign(['residencial_id', 'medio_entrada_id'])->references(['residencial_id', 'id'])->on('medios_entrada');
            $table->foreign(['residencial_id', 'guardia_entrada_id'])->references(['residencial_id', 'id'])->on('guardias');
            $table->foreign(['residencial_id', 'guardia_salida_id'])->references(['residencial_id', 'id'])->on('guardias');
            $table->foreign(['residencial_id', 'preautorizacion_id'])->references(['residencial_id', 'id'])->on('preautorizaciones');
            $table->foreign(['residencial_id', 'autorizado_id'])->references(['residencial_id', 'id'])->on('autorizados_frecuentes');
            $table->index(['residencial_id', 'entrada_en']);
        });

        DB::statement('CREATE INDEX visitas_dentro_index ON visitas (residencial_id) WHERE salida_en IS NULL');

        // El token no contiene datos personales ni financieros (HU-47).
        Schema::create('qr_residentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('residente_id');
            $table->uuid('token')->unique();
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('invalidado_en')->nullable();
            $table->foreign(['residencial_id', 'residente_id'])->references(['residencial_id', 'id'])->on('residentes');
            $table->unique(['residencial_id', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX qr_un_activo_por_residente_unique ON qr_residentes (residente_id) WHERE activo');

        Schema::create('escaneos_qr', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('qr_id')->nullable();
            $table->foreignId('preautorizacion_id')->nullable();
            $table->foreignId('guardia_id');
            $table->timestamp('escaneado_en');
            $table->enum('resultado', ['permitido', 'denegado', 'revisar']);
            $table->enum('decision_final', ['ingreso', 'no_ingreso'])->nullable();
            $table->timestamp('created_at')->nullable();
            $table->foreign(['residencial_id', 'qr_id'])->references(['residencial_id', 'id'])->on('qr_residentes');
            $table->foreign(['residencial_id', 'preautorizacion_id'])->references(['residencial_id', 'id'])->on('preautorizaciones');
            $table->foreign(['residencial_id', 'guardia_id'])->references(['residencial_id', 'id'])->on('guardias');
            $table->index(['residencial_id', 'escaneado_en']);
        });

        Schema::create('excepciones_acceso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id');
            $table->text('motivo');
            $table->timestamp('vence_en');
            $table->foreignId('autorizado_por')->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->index(['vivienda_id', 'vence_en']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                -- Permite impedir turnos superpuestos por guardia (HU-39).
                CREATE EXTENSION IF NOT EXISTS btree_gist;

                ALTER TABLE turnos
                    ADD CONSTRAINT turnos_rango_check CHECK (fin > inicio),
                    ADD CONSTRAINT turnos_sin_superposicion
                        EXCLUDE USING gist (guardia_id WITH =, tsrange(inicio, fin) WITH &&);

                ALTER TABLE preautorizaciones ADD CONSTRAINT preautorizaciones_rango_check CHECK (fin > inicio);

                ALTER TABLE autorizados_frecuentes ADD CONSTRAINT autorizados_dias_check
                    CHECK (dias_semana <@ '[1, 2, 3, 4, 5, 6, 7]'::jsonb);

                ALTER TABLE visitas ADD CONSTRAINT visitas_salida_check
                    CHECK (salida_en IS NULL OR salida_en >= entrada_en);
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excepciones_acceso');
        Schema::dropIfExists('escaneos_qr');
        Schema::dropIfExists('qr_residentes');
        Schema::dropIfExists('visitas');
        Schema::dropIfExists('autorizado_frecuente_viviendas');
        Schema::dropIfExists('autorizados_frecuentes');
        Schema::dropIfExists('preautorizaciones');
        Schema::dropIfExists('medios_entrada');
        Schema::dropIfExists('incidencias');
        Schema::dropIfExists('tipos_incidencia');
        Schema::dropIfExists('entregas_turno');
        Schema::dropIfExists('turnos');
        Schema::dropIfExists('guardias');
    }
};
