<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $nombre
 * @property bool $requiere_placa
 * @property bool $activo
 */
#[Fillable(['residencial_id', 'nombre', 'requiere_placa', 'activo'])]
class MedioEntrada extends Model
{
    use PerteneceAResidencial;

    public $timestamps = false;

    protected $table = 'medios_entrada';

    protected function casts(): array
    {
        return ['requiere_placa' => 'boolean', 'activo' => 'boolean'];
    }

    public function exigePlaca(): bool
    {
        return $this->requiere_placa || self::esMedioMotorizado($this->nombre);
    }

    public static function esMedioMotorizado(string $nombre): bool
    {
        return in_array(strtolower(trim($nombre)), ['moto', 'carro'], true);
    }
}
