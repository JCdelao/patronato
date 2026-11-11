<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarCobroRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * La fecha, la hora y el cobrador los pone el sistema, no el formulario.
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
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'cargos' => ['required', 'array', 'min:1'],
            'cargos.*' => ['integer', 'distinct'],
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
            'vivienda_id.required' => 'Selecciona una vivienda.',
            'vivienda_id.exists' => 'Selecciona una vivienda válida.',
            'monto.required' => 'Escribe el monto recibido.',
            'monto.decimal' => 'El monto admite hasta dos decimales.',
            'monto.gt' => 'El monto debe ser mayor que cero.',
            'monto.max' => 'El monto es demasiado alto.',
            'cargos.required' => 'Elige al menos un mes.',
            'cargos.min' => 'Elige al menos un mes.',
        ];
    }
}
