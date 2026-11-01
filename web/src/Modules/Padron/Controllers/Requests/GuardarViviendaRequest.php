<?php

namespace Patronato\Modules\Padron\Controllers\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarViviendaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'codigo' => [
                'required', 'string', 'max:30',
                Rule::unique('viviendas', 'codigo')
                    ->where('residencial_id', $this->user()->residencial_id)
                    ->ignore($this->route('vivienda')),
            ],
            'direccion' => ['required', 'string', 'max:60'],
            'tipo' => ['required', Rule::in(['casa', 'apartamento'])],
            'cuota' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
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
            'codigo.unique' => 'Ya existe una vivienda con ese código.',
        ];
    }
}
