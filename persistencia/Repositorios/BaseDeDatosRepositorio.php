<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Copia y restauración de la base de PostgreSQL con sus herramientas
 */
class BaseDeDatosRepositorio
{
    /**
     * Nombre de la base en uso
     */
    public function nombre(): string
    {
        return (string) DB::connection('pgsql')->getConfig('database');
    }

    /**
     * Guarda toda la base en un archivo
     */
    public function volcar(string $archivo): ProcessResult
    {
        return $this->ejecutar('pg_dump', ['--format=custom', '--no-owner', "--file={$archivo}", $this->nombre()]);
    }

    /**
     * Restaura un archivo en la base indicada
     */
    public function restaurar(string $archivo, string $base): ProcessResult
    {
        return $this->ejecutar('pg_restore', ['--clean', '--if-exists', '--no-owner', '--exit-on-error', "--dbname={$base}", $archivo]);
    }

    /**
     * @param  array<int, string>  $argumentos
     */
    private function ejecutar(string $programa, array $argumentos): ProcessResult
    {
        $conexion = DB::connection('pgsql');
        $carpeta = rtrim((string) config('respaldos.bin'), '\\/');

        // Pasa la contraseña por el entorno
        return Process::timeout(1800)
            ->env(['PGPASSWORD' => (string) $conexion->getConfig('password')])
            ->run([
                $carpeta === '' ? $programa : $carpeta.DIRECTORY_SEPARATOR.$programa,
                '--host='.$conexion->getConfig('host'),
                '--port='.$conexion->getConfig('port'),
                '--username='.$conexion->getConfig('username'),
                ...$argumentos,
            ]);
    }
}
