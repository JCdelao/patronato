<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Vehículo de una vivienda; la placa no se repite entre los activos del residencial
 *
 * Si todavía no tiene placa (en trámite), lo identifica un comprobante con vencimiento.
 * Cuando llega la placa, el comprobante se conserva como historial.
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property string $tipo
 * @property string|null $placa
 * @property string|null $comprobante_tipo
 * @property string|null $comprobante_descripcion
 * @property string|null $comprobante_numero
 * @property Carbon|null $comprobante_vence
 * @property string|null $comprobante_path
 * @property string|null $marca
 * @property string|null $modelo
 * @property string|null $color
 * @property bool $activo
 * @property-read Vivienda $vivienda
 * @property-read Collection<int, VehiculoConductor> $conductores
 * @property-read Collection<int, Sticker> $stickers
 */
#[Fillable(['residencial_id', 'vivienda_id', 'tipo', 'placa', 'comprobante_tipo', 'comprobante_descripcion', 'comprobante_numero', 'comprobante_vence', 'comprobante_path', 'marca', 'modelo', 'color', 'activo'])]
class Vehiculo extends Model
{
    use PerteneceAResidencial;

    public const TIPOS = ['carro', 'moto', 'otro'];

    /**
     * Documentos que validan la circulación de un vehículo sin placa
     */
    public const TIPOS_COMPROBANTE = [
        'permiso_provisional' => 'Permiso provisional de circulación',
        'constancia_tramite' => 'Constancia de trámite de placa',
        'otro' => 'Otro',
    ];

    /**
     * @var string
     */
    protected $table = 'vehiculos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'comprobante_vence' => 'date',
        ];
    }

    public function tienePlaca(): bool
    {
        return $this->placa !== null;
    }

    /**
     * Un vehículo sin placa con el comprobante vencido; uno con placa nunca lo está
     *
     * @param  string  $hoy  fecha Y-m-d en la hora local
     */
    public function comprobanteVencido(string $hoy): bool
    {
        return ! $this->tienePlaca()
            && ($this->comprobante_vence === null || $this->comprobante_vence->toDateString() < $hoy);
    }

    /**
     * Activo y con placa, o con un comprobante vigente; lo usará el control de acceso
     *
     * @param  string  $hoy  fecha Y-m-d en la hora local
     */
    public function autorizado(string $hoy): bool
    {
        return $this->activo && ! $this->comprobanteVencido($hoy);
    }

    /**
     * Cómo se nombra el vehículo en mensajes: la placa o, si no tiene, el número de comprobante
     */
    public function identificador(): string
    {
        return $this->placa ?? 'comprobante '.$this->comprobante_numero;
    }

    /**
     * @return BelongsTo<Vivienda, $this>
     */
    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }

    /**
     * Personas autorizadas a conducirlo
     *
     * @return HasMany<VehiculoConductor, $this>
     */
    public function conductores(): HasMany
    {
        return $this->hasMany(VehiculoConductor::class);
    }

    /**
     * Stickers del vehículo, del más reciente al más antiguo
     *
     * @return HasMany<Sticker, $this>
     */
    public function stickers(): HasMany
    {
        return $this->hasMany(Sticker::class)->orderByDesc('id');
    }
}
