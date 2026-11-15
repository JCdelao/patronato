<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarTarifaEspecialRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vivienda_id' => [
                'required', 'integer',
                Rule::exists('viviendas', 'id')->where('residencial_id', $this->user()->residencial_id)->where('activa', true),
            ],
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'motivo' => ['required', 'string', 'max:500'],
            'vigente_desde' => ['required', 'date_format:Y-m'],
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
            'vivienda_id.required' => 'Elige la vivienda.',
            'vivienda_id.exists' => 'Elige una vivienda activa.',
            'monto.min' => 'La cuota no puede ser negativa.',
            'motivo.required' => 'Escribe el motivo de la cuota especial.',
            'vigente_desde.date_format' => 'Elige el mes desde el que rige la cuota.',
        ];
    }

    /**
     * Nombres de los campos en los mensajes
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monto' => 'cuota especial',
            'vigente_desde' => 'mes de inicio',
        ];
    }
}
