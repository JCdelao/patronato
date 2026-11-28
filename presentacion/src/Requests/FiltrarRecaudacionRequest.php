<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\ReporteRecaudacionService;

class FiltrarRecaudacionRequest extends FormRequest
{
    /**
     * Reglas de validación de los filtros
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // El cobrador debe ser del residencial de quien consulta
            'cobrador' => ['nullable', 'integer', Rule::exists('users', 'id')->where('residencial_id', $this->user()->residencial_id)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'agrupar' => ['nullable', Rule::in(ReporteRecaudacionService::AGRUPACIONES)],
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
            'cobrador.integer' => 'Elige un cobrador del residencial.',
            'cobrador.exists' => 'Elige un cobrador del residencial.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'agrupar.in' => 'Elige agrupar por día, mes o año.',
        ];
    }

    /**
     * Filtros listos para el servicio; las fechas vacías toman las predeterminadas
     *
     * @param  array{desde: string, hasta: string}  $predeterminadas
     * @return array{desde: string, hasta: string, cobrador_id: int|null, agrupacion: string}
     */
    public function filtros(array $predeterminadas): array
    {
        $agrupacion = $this->string('agrupar')->toString();

        return [
            'desde' => $this->filled('desde') ? $this->string('desde')->toString() : $predeterminadas['desde'],
            'hasta' => $this->filled('hasta') ? $this->string('hasta')->toString() : $predeterminadas['hasta'],
            'cobrador_id' => $this->filled('cobrador') ? $this->integer('cobrador') : null,
            'agrupacion' => in_array($agrupacion, ReporteRecaudacionService::AGRUPACIONES, true) ? $agrupacion : ReporteRecaudacionService::POR_DIA,
        ];
    }
}
