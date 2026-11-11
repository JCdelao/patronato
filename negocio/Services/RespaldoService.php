<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Patronato\Negocio\Notificaciones\RespaldoFallido;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Repositorios\BaseDeDatosRepositorio;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;
use RuntimeException;

class RespaldoService
{
    public function __construct(
        private BaseDeDatosRepositorio $baseDeDatos,
        private UsuarioRepositorio $usuarios,
    ) {}

    /**
     * Genera un respaldo de toda la base y elimina los más antiguos
     *
     * @throws RuntimeException
     */
    public function crear(): string
    {
        File::ensureDirectoryExists($this->directorio());

        $archivo = $this->directorio().DIRECTORY_SEPARATOR.'patronato_'.now()->format('Ymd_His').'.dump';

        $resultado = $this->baseDeDatos->volcar($archivo);

        if ($resultado->failed() || ! File::exists($archivo) || File::size($archivo) === 0) {
            File::delete($archivo);

            throw new RuntimeException(trim($resultado->errorOutput()) ?: 'pg_dump no generó el archivo de respaldo.');
        }

        $this->eliminarAntiguos();

        return $archivo;
    }

    /**
     * Restaura un respaldo en la base indicada
     *
     * @throws RuntimeException
     */
    public function restaurar(string $nombre, string $base): void
    {
        $archivo = $this->directorio().DIRECTORY_SEPARATOR.basename($nombre);

        if (! File::exists($archivo)) {
            throw new RuntimeException("No existe el respaldo {$nombre}.");
        }

        $resultado = $this->baseDeDatos->restaurar($archivo, $base);

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
        return $this->baseDeDatos->nombre();
    }

    /**
     * Avisa que el respaldo falló
     */
    public function notificarFallo(string $error): void
    {
        $destinatarios = $this->usuarios->activasConRoles([Rol::ADMIN_TECNICO, Rol::ADMIN_PLATAFORMA]);

        Notification::send($destinatarios, new RespaldoFallido($error));
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
