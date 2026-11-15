<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Patronato\Persistencia\Excepciones\CobroDeCajaCerrada;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property string $forma_pago
 * @property string $estado
 * @property string $origen
 * @property string $monto
 * @property Carbon $fecha_pago
 * @property string|null $numero_recibo
 * @property int|null $caja_id
 * @property int|null $registrado_por
 * @property int|null $anulado_por
 * @property int|null $anulacion_aprobada_por
 * @property Carbon|null $anulado_en
 * @property string|null $motivo_anulacion
 * @property Carbon $created_at
 * @property-read Residencial $residencial
 * @property-read Vivienda $vivienda
 * @property-read User|null $cobrador
 * @property-read Collection<int, PagoAplicacion> $aplicaciones
 * @property-read Caja|null $caja
 * @property-read User|null $anuladoPor
 * @property-read User|null $aprobadorAnulacion
 * @property-read Collection<int, SolicitudAnulacion> $solicitudesAnulacion
 */
#[Fillable(['residencial_id', 'vivienda_id', 'forma_pago', 'estado', 'origen', 'monto', 'fecha_pago', 'numero_recibo', 'caja_id', 'registrado_por', 'anulado_por', 'anulacion_aprobada_por', 'anulado_en', 'motivo_anulacion'])]
class Pago extends Model
{
    use PerteneceAResidencial;

    public const EFECTIVO = 'efectivo';

    public const CONFIRMADO = 'confirmado';

    public const ANULADO = 'anulado';

    /**
     * Campos que puede cambiar una anulación en un cobro de caja cerrada
     */
    private const CAMPOS_ANULACION = ['estado', 'anulado_por', 'anulacion_aprobada_por', 'anulado_en', 'motivo_anulacion', 'updated_at'];

    /**
     * @var string
     */
    protected $table = 'pagos';

    /**
     * Los cobros de una caja cerrada no se editan ni se borran; solo se anulan
     */
    protected static function booted(): void
    {
        static::updating(function (Pago $pago): void {
            $cambios = array_diff(array_keys($pago->getDirty()), self::CAMPOS_ANULACION);
            $esAnulacion = ! $pago->isDirty('estado') || $pago->estado === self::ANULADO;

            if (($cambios !== [] || ! $esAnulacion) && $pago->esDeCajaCerrada()) {
                throw new CobroDeCajaCerrada;
            }
        });

        static::deleting(function (Pago $pago): void {
            if ($pago->esDeCajaCerrada()) {
                throw new CobroDeCajaCerrada;
            }
        });
    }

    /**
     * Indica si el cobro, tal como está guardado, pertenece a una caja cerrada
     */
    public function esDeCajaCerrada(): bool
    {
        $cajaId = $this->getOriginal('caja_id');

        return $cajaId !== null && Caja::query()
            ->withoutGlobalScope('residencial')
            ->whereKey($cajaId)
            ->where('estado', Caja::CERRADA)
            ->exists();
    }

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_pago' => 'date',
            'anulado_en' => 'datetime',
        ];
    }

    /**
     * Partes del pago aplicadas a cada cargo
     *
     * @return HasMany<PagoAplicacion, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(PagoAplicacion::class);
    }

    /**
     * @return BelongsTo<Residencial, $this>
     */
    public function residencial(): BelongsTo
    {
        return $this->belongsTo(Residencial::class);
    }

    /**
     * @return BelongsTo<Vivienda, $this>
     */
    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }

    /**
     * Usuario que registró el pago
     *
     * @return BelongsTo<User, $this>
     */
    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /**
     * Caja del cobrador en la que entró el pago en efectivo
     *
     * @return BelongsTo<Caja, $this>
     */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    /**
     * Directivo que solicitó la anulación
     *
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /**
     * Directivo que aprobó la anulación
     *
     * @return BelongsTo<User, $this>
     */
    public function aprobadorAnulacion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulacion_aprobada_por');
    }

    /**
     * Solicitudes de anulación del pago, de la más reciente a la más antigua
     *
     * @return HasMany<SolicitudAnulacion, $this>
     */
    public function solicitudesAnulacion(): HasMany
    {
        return $this->hasMany(SolicitudAnulacion::class)->latest()->latest('id');
    }
}
