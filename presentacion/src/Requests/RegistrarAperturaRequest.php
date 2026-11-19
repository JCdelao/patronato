<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\PeriodoService;

class RegistrarAperturaRequest extends FormRequest
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
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'fecha' => ['required', 'date_format:Y-m-d', "before_or_equal:{$hoy}"],
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
            'monto.required' => 'Escribe el saldo con el que el patronato empezó a registrar.',
            'monto.min' => 'El saldo de apertura no puede ser negativo.',
            'fecha.required' => 'Escribe la fecha del saldo de apertura.',
            'fecha.before_or_equal' => 'La fecha no puede ser posterior a hoy.',
        ];
    }
}
