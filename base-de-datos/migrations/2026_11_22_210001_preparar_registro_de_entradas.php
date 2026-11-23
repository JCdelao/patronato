<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Patronato\Negocio\Services\MedioEntradaService;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            // Las entradas anteriores conservan su historial aunque no tengan turno asociado.
            $table->foreignId('turno_entrada_id')->nullable();
            $table->foreign(['residencial_id', 'turno_entrada_id'])
                ->references(['residencial_id', 'id'])->on('turnos');
        });

        $this->restaurarIndiceParcial();

        $roles = DB::table('roles')->whereIn('codigo', ['guardia', 'presidente'])->pluck('id', 'codigo');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (MedioEntradaService::PREDETERMINADOS as $nombre => $requierePlaca) {
                DB::table('medios_entrada')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'nombre' => $nombre,
                    'requiere_placa' => $requierePlaca,
                    'activo' => true,
                ]);
            }

            foreach (['guardia' => 'visitas', 'presidente' => 'medios_entrada'] as $rol => $modulo) {
                if (isset($roles[$rol])) {
                    DB::table('rol_permisos')->insertOrIgnore([
                        'residencial_id' => $residencialId,
                        'rol_id' => $roles[$rol],
                        'modulo' => $modulo,
                        'accion' => 'registrar',
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('rol_permisos')->whereIn('modulo', ['visitas', 'medios_entrada'])->delete();

        Schema::table('visitas', function (Blueprint $table) {
            $table->dropForeign(['residencial_id', 'turno_entrada_id']);
            $table->dropColumn('turno_entrada_id');
        });
        $this->restaurarIndiceParcial();
        // El catálogo pertenece al esquema anterior y puede tener referencias.
    }

    private function restaurarIndiceParcial(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // SQLite reconstruye la tabla y Laravel recrea sus índices sin el predicado parcial.
        DB::transaction(function (): void {
            DB::statement('DROP INDEX IF EXISTS visitas_dentro_index');
            DB::statement('CREATE INDEX visitas_dentro_index ON visitas (residencial_id) WHERE salida_en IS NULL');
        });
    }
};
