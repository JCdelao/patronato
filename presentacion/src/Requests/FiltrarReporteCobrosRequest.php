<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FiltrarReporteCobrosRequest extends FormRequest
{
    /**
     * Reglas de validación de los filtros
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cobrador' => ['nullable', 'integer', Rule::exists('users', 'id')->where('residencial_id', $this->user()->residencial_id)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'estado' => ['nullable', Rule::in(['todas', 'abierta', 'cerrada'])],
            'agrupar' => ['nullable', Rule::in(['dia', 'mes'])],
        ];
    }

    /**
     * Mensajes de validación
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cobrador.exists' => 'Elige un cobrador del residencial.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'estado.in' => 'Elige un estado de caja válido.',
            'agrupar.in' => 'Elige agrupar por día o por mes.',
        ];
    }

    /**
     * Filtros listos para el servicio; las fechas vacías toman las predeterminadas
     *
     * @param  array{desde: string, hasta: string}  $predeterminadas
     * @return array{cobrador_id: int|null, desde: string|null, hasta: string|null, estado_caja: string|null, agrupacion: string}
     */
    public function filtros(array $predeterminadas): array
    {
        $estado = $this->string('estado')->toString();

        return [
            'cobrador_id' => $this->filled('cobrador') ? $this->integer('cobrador') : null,
            'desde' => $this->filled('desde') ? $this->string('desde')->toString() : $predeterminadas['desde'],
            'hasta' => $this->filled('hasta') ? $this->string('hasta')->toString() : $predeterminadas['hasta'],
            'estado_caja' => in_array($estado, ['abierta', 'cerrada'], true) ? $estado : null,
            'agrupacion' => $this->string('agrupar')->toString() === 'mes' ? 'mes' : 'dia',
        ];
    }
}
