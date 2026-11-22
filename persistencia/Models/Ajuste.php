<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ajuste autorizado al saldo de una vivienda
 *
 * Un monto negativo reduce la deuda y uno positivo la aumenta. Solo cambia el saldo
 * general: no toca cargos, pagos ni sus aplicaciones. Los ajustes nuevos son siempre
 * correcciones; los otros tipos solo pueden venir de la migración de datos.
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property string $tipo
 * @property string $monto
 * @property Carbon $fecha
 * @property string $motivo
 * @property int|null $autorizado_por
 * @property int|null $lote_migracion_id
 * @property-read User|null $autorizador
 */
#[Fillable(['residencial_id', 'vivienda_id', 'tipo', 'monto', 'fecha', 'motivo', 'autorizado_por'])]
class Ajuste extends Model
{
    use PerteneceAResidencial;

    /**
     * Único tipo que se registra en el sistema
     */
    public const CORRECCION = 'correccion';

    /**
     * Nombre visible de cada tipo de ajuste
     */
    public const TIPOS = [
        'descuento' => 'Descuento',
        'condonacion' => 'Condonación',
        'correccion' => 'Corrección',
    ];

    /**
     * @var string
     */
    protected $table = 'ajustes';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
        ];
    }

    /**
     * Directivo que autorizó el ajuste; vacío si vino de la migración de datos
     *
     * @return BelongsTo<User, $this>
     */
    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
