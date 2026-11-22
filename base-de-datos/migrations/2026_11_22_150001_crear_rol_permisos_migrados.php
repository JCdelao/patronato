<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anota qué permisos de rol insertó cada migración de datos
     *
     * Así su reversión quita solo lo que insertó. Si el permiso se borra o se vuelve a
     * guardar desde la pantalla de permisos, la anotación desaparece en cascada y el
     * permiso pasa a ser manual.
     */
    public function up(): void
    {
        Schema::create('rol_permisos_migrados', function (Blueprint $table) {
            $table->foreignId('residencial_id');
            $table->foreignId('rol_id');
            $table->string('modulo', 40);
            $table->string('accion');
            $table->string('migracion', 150);
            $table->timestamp('created_at')->nullable();
            $table->primary(['residencial_id', 'rol_id', 'modulo', 'accion', 'migracion']);
            $table->foreign(['residencial_id', 'rol_id', 'modulo', 'accion'])
                ->references(['residencial_id', 'rol_id', 'modulo', 'accion'])
                ->on('rol_permisos')
                ->cascadeOnDelete();
        });
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('rol_permisos_migrados');
    }
};
