<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\PeriodoService;

/**
 * Rango de fechas del estado de cuenta en PDF
 *
 * Sin límite de años; no admite fechas futuras según el día en Honduras.
 */
class GenerarEstadoCuentaPdfRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hoy = app(PeriodoService::class)->hoy()->toDateString();

        return [
            'desde' => ['required', 'date_format:Y-m-d', 'before_or_equal:hasta'],
            'hasta' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
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
            'desde.required' => 'Indica la fecha inicial.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'desde.before_or_equal' => 'La fecha inicial no puede ser posterior a la final.',
            'hasta.required' => 'Indica la fecha final.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.before_or_equal' => 'La fecha final no puede ser posterior a hoy.',
        ];
    }
}
