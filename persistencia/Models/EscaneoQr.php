<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $qr_id
 * @property Carbon $escaneado_en
 * @property string $resultado
 * @property string|null $decision_final
 */
#[Fillable(['residencial_id', 'guardia_id', 'qr_id', 'escaneado_en', 'resultado', 'decision_final'])]
class EscaneoQr extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'escaneos_qr';

    protected function casts(): array
    {
        return ['escaneado_en' => 'datetime'];
    }
}
