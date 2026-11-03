<?php

namespace Patronato\Modules\Padron\Controllers\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgregarViviendaRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(['propietario', 'inquilino', 'familiar'])],
            'es_responsable_pago' => ['nullable', 'boolean'],
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
        ];
    }
}
