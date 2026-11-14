<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipos que se crean en los residenciales que ya existen
     */
    private const PREDETERMINADOS = ['casa' => 'Casa', 'apartamento' => 'Apartamento'];

    /**
     * Cambia el tipo fijo de vivienda por un catálogo de cada residencial
     */
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        Schema::create('tipos_vivienda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->string('nombre', 60);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['residencial_id', 'nombre']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::table('viviendas', function (Blueprint $table) {
            $table->foreignId('tipo_vivienda_id')->nullable()->after('codigo');
            // Una habitación pertenece a un apartamento y paga su propia cuota
            $table->foreignId('vivienda_padre_id')->nullable()->after('tipo_vivienda_id');
        });

        Schema::table('tarifas', function (Blueprint $table) {
            $table->foreignId('tipo_vivienda_id')->nullable()->after('concepto_id');
        });

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (self::PREDETERMINADOS as $valor => $nombre) {
                $tipoId = DB::table('tipos_vivienda')->insertGetId([
                    'residencial_id' => $residencialId,
                    'nombre' => $nombre,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('viviendas')->where('residencial_id', $residencialId)->where('tipo', $valor)->update(['tipo_vivienda_id' => $tipoId]);
                DB::table('tarifas')->where('residencial_id', $residencialId)->where('tipo_vivienda', $valor)->update(['tipo_vivienda_id' => $tipoId]);
            }
        }

        // La cuota escrita en cada vivienda se reemplaza por la cuota de su tipo
        DB::table('tarifas')
            ->whereNotNull('vivienda_id')
            ->whereIn('concepto_id', DB::table('conceptos_cobro')->where('es_cuota_unificada', true)->select('id'))
            ->delete();

        Schema::table('viviendas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        Schema::table('tarifas', function (Blueprint $table) {
            $table->dropUnique('tarifas_vigencia_unique');
        });

        Schema::table('tarifas', function (Blueprint $table) use ($pgsql) {
            $table->dropColumn('tipo_vivienda');

            $unico = $table->unique(['concepto_id', 'tipo_vivienda_id', 'vivienda_id', 'vigente_desde'], 'tarifas_vigencia_unique');

            if ($pgsql) {
                $unico->nullsNotDistinct();
            }
        });

        if ($pgsql) {
            Schema::table('viviendas', function (Blueprint $table) {
                $table->foreignId('tipo_vivienda_id')->nullable(false)->change();
                $table->foreign(['residencial_id', 'tipo_vivienda_id'])->references(['residencial_id', 'id'])->on('tipos_vivienda');
                $table->foreign(['residencial_id', 'vivienda_padre_id'])->references(['residencial_id', 'id'])->on('viviendas');
            });

            Schema::table('tarifas', function (Blueprint $table) {
                $table->foreign(['residencial_id', 'tipo_vivienda_id'])->references(['residencial_id', 'id'])->on('tipos_vivienda');
            });

            DB::statement('ALTER TABLE tarifas ADD CONSTRAINT tarifas_alcance_check CHECK (tipo_vivienda_id IS NULL OR vivienda_id IS NULL)');
            DB::statement('ALTER TABLE viviendas ADD CONSTRAINT viviendas_padre_distinto_check CHECK (vivienda_padre_id IS NULL OR vivienda_padre_id <> id)');
        }
    }

    /**
     * Vuelve al tipo fijo; las cuotas borradas de cada vivienda no se recuperan
     */
    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($pgsql) {
            DB::statement('ALTER TABLE viviendas DROP CONSTRAINT IF EXISTS viviendas_padre_distinto_check');
            DB::statement('ALTER TABLE tarifas DROP CONSTRAINT IF EXISTS tarifas_alcance_check');

            Schema::table('tarifas', function (Blueprint $table) {
                $table->dropForeign(['residencial_id', 'tipo_vivienda_id']);
            });

            Schema::table('viviendas', function (Blueprint $table) {
                $table->dropForeign(['residencial_id', 'tipo_vivienda_id']);
                $table->dropForeign(['residencial_id', 'vivienda_padre_id']);
            });
        }

        Schema::table('viviendas', function (Blueprint $table) {
            $table->enum('tipo', ['casa', 'apartamento'])->default('casa')->after('codigo');
        });

        Schema::table('tarifas', function (Blueprint $table) {
            $table->dropUnique('tarifas_vigencia_unique');
            $table->enum('tipo_vivienda', ['casa', 'apartamento'])->nullable()->after('concepto_id');
        });

        // Los tipos que no son Casa ni Apartamento vuelven como casa
        DB::table('viviendas')
            ->whereIn('tipo_vivienda_id', DB::table('tipos_vivienda')->where('nombre', 'Apartamento')->select('id'))
            ->update(['tipo' => 'apartamento']);

        DB::table('tarifas')
            ->whereIn('tipo_vivienda_id', DB::table('tipos_vivienda')->where('nombre', 'Apartamento')->select('id'))
            ->update(['tipo_vivienda' => 'apartamento']);

        DB::table('tarifas')
            ->whereIn('tipo_vivienda_id', DB::table('tipos_vivienda')->where('nombre', '<>', 'Apartamento')->select('id'))
            ->update(['tipo_vivienda' => 'casa']);

        Schema::table('tarifas', function (Blueprint $table) use ($pgsql) {
            $table->dropColumn('tipo_vivienda_id');

            $unico = $table->unique(['concepto_id', 'tipo_vivienda', 'vivienda_id', 'vigente_desde'], 'tarifas_vigencia_unique');

            if ($pgsql) {
                $unico->nullsNotDistinct();
            }
        });

        Schema::table('viviendas', function (Blueprint $table) {
            $table->dropColumn(['tipo_vivienda_id', 'vivienda_padre_id']);
        });

        if ($pgsql) {
            DB::statement('ALTER TABLE tarifas ADD CONSTRAINT tarifas_alcance_check CHECK (tipo_vivienda IS NULL OR vivienda_id IS NULL)');
        }

        Schema::dropIfExists('tipos_vivienda');
    }
};
