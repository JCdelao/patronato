<?php

namespace Patronato\Persistencia\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $nombre_visitante
 * @property CarbonImmutable $inicio
 * @property CarbonImmutable $fin
 * @property bool|null $vinculo_vigente
 * @property-read MedioEntrada|null $medio
 */
#[Fillable(['residencial_id', 'residente_id', 'vivienda_id', 'medio_entrada_id', 'nombre_visitante', 'inicio', 'fin'])]
class Preautorizacion extends Model
{
    use PerteneceAResidencial;

    protected $table = 'preautorizaciones';

    protected function casts(): array
    {
        return ['inicio' => 'immutable_datetime', 'fin' => 'immutable_datetime', 'vinculo_vigente' => 'boolean'];
    }

    /** @return BelongsTo<MedioEntrada, $this> */
    public function medio(): BelongsTo
    {
        return $this->belongsTo(MedioEntrada::class, 'medio_entrada_id');
    }
}
