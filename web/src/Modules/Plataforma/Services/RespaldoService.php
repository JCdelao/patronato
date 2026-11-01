<?php

namespace Patronato\Modules\Plataforma\Services;

use App\Models\User;
use App\Notifications\RespaldoFallido;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Patronato\Modules\Identidad\Models\Rol;
use RuntimeException;

class RespaldoService
{
    /**
     * Genera un respaldo de toda la base y elimina los más antiguos (HU-09).
     *
     * @throws RuntimeException si el respaldo no se pudo generar
     */
    public function crear(): string
    {
        File::ensureDirectoryExists($this->directorio());

        $archivo = $this->directorio().DIRECTORY_SEPARATOR.'patronato_'.now()->format('Ymd_His').'.dump';

        $resultado = $this->ejecutar('pg_dump', ['--format=custom', '--no-owner', "--file={$archivo}", $this->base()]);

        if ($resultado->failed() || ! File::exists($archivo) || File::size($archivo) === 0) {
            File::delete($archivo);

            throw new RuntimeException(trim($resultado->errorOutput()) ?: 'pg_dump no generó el archivo de respaldo.');
        }

        $this->eliminarAntiguos();

        return $archivo;
    }

    /**
     * Restaura un respaldo en la base indicada, reemplazando su contenido.
     *
     * @throws RuntimeException si el archivo no existe o la restauración falla
     */
    public function restaurar(string $nombre, string $base): void
    {
        $archivo = $this->directorio().DIRECTORY_SEPARATOR.basename($nombre);

        if (! File::exists($archivo)) {
            throw new RuntimeException("No existe el respaldo {$nombre}.");
        }

        $resultado = $this->ejecutar('pg_restore', ['--clean', '--if-exists', '--no-owner', '--exit-on-error', "--dbname={$base}", $archivo]);

        if ($resultado->failed()) {
            throw new RuntimeException(trim($resultado->errorOutput()) ?: 'pg_restore falló.');
        }
    }

    /**
     * @return array<int, string>
     */
    public function listar(): array
    {
        $archivos = File::glob($this->directorio().DIRECTORY_SEPARATOR.'patronato_*.dump') ?: [];

        rsort($archivos);

        return array_map(basename(...), $archivos);
    }

    public function base(): string
    {
        return (string) DB::connection('pgsql')->getConfig('database');
    }

    /**
     * Avisa a quienes administran el sistema que el respaldo falló (HU-09).
     */
    public function notificarFallo(string $error): void
    {
        $destinatarios = User::query()
            ->where('activo', true)
            ->whereIn('rol_id', Rol::query()->whereIn('codigo', [Rol::ADMIN_TECNICO, Rol::ADMIN_PLATAFORMA])->select('id'))
            ->get();

        Notification::send($destinatarios, new RespaldoFallido($error));
    }

    /**
     * @param  array<int, string>  $argumentos
     */
    private function ejecutar(string $programa, array $argumentos): ProcessResult
    {
        $conexion = DB::connection('pgsql');
        $carpeta = rtrim((string) config('respaldos.bin'), '\\/');

        // La contraseña va en el entorno para que no aparezca en la lista de procesos.
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

    private function eliminarAntiguos(): void
    {
        foreach (array_slice($this->listar(), max(1, (int) config('respaldos.conservar'))) as $nombre) {
            File::delete($this->directorio().DIRECTORY_SEPARATOR.$nombre);
        }
    }

    private function directorio(): string
    {
        return (string) config('respaldos.directorio');
    }
}
