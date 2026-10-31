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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 80);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('residencial_id')->nullable()->index()->constrained('residenciales');
            $table->foreignId('rol_id')->nullable()->constrained('roles');
            $table->boolean('activo')->default(true);
            $table->boolean('debe_cambiar_password')->default(false);
        });

        // Matriz de permisos de cada residencial (HU-07).
        Schema::create('rol_permisos', function (Blueprint $table) {
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->foreignId('rol_id')->constrained('roles');
            $table->string('modulo', 40);
            $table->enum('accion', ['ver', 'registrar', 'verificar', 'aprobar', 'anular']);
            $table->primary(['residencial_id', 'rol_id', 'modulo', 'accion']);
        });

        // Bitácora de auditoría (HU-08). También cubre el historial de cambios (HU-13),
        // suspensiones de residenciales (HU-03) e intentos de acceso denegado (HU-02).
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->nullable()->constrained('residenciales');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('modulo', 40);
            $table->string('accion', 40);
            $table->string('entidad', 60)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->jsonb('valor_anterior')->nullable();
            $table->jsonb('valor_nuevo')->nullable();
            $table->text('motivo')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['residencial_id', 'created_at'], 'auditoria_filtros_index');
            $table->index(['entidad', 'entidad_id'], 'auditoria_entidad_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE auditoria ALTER COLUMN created_at SET DEFAULT (now() AT TIME ZONE 'utc')");

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION auditoria_inmutable() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'La bitácora de auditoría no se puede modificar ni eliminar';
                END;
                $$;

                CREATE TRIGGER auditoria_sin_cambios
                    BEFORE UPDATE OR DELETE ON auditoria
                    FOR EACH ROW EXECUTE FUNCTION auditoria_inmutable();

                CREATE TRIGGER auditoria_sin_truncate
                    BEFORE TRUNCATE ON auditoria
                    FOR EACH STATEMENT EXECUTE FUNCTION auditoria_inmutable();
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditoria');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS auditoria_inmutable()');
        }

        Schema::dropIfExists('rol_permisos');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['residencial_id']);
            $table->dropConstrainedForeignId('residencial_id');
            $table->dropConstrainedForeignId('rol_id');
            $table->dropColumn(['activo', 'debe_cambiar_password']);
        });

        Schema::dropIfExists('roles');
    }
};
