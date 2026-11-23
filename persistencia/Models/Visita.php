<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nombre_visitante
 * @property string $identificador
 * @property Carbon $entrada_en
 * @property Carbon|null $salida_en
 * @property-read Vivienda $vivienda
 */
#[Fillable(['residencial_id', 'identificador', 'vivienda_id', 'medio_entrada_id', 'nombre_visitante', 'identidad', 'motivo', 'placa', 'entrada_en', 'guardia_entrada_id', 'turno_entrada_id'])]
class Visita extends Model
{
    use PerteneceAResidencial;

    protected $table = 'visitas';

    protected function casts(): array
    {
        return ['entrada_en' => 'datetime', 'salida_en' => 'datetime'];
    }

    /** @return BelongsTo<Vivienda, $this> */
    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }
}
