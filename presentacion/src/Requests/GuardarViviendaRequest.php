<?php

namespace Patronato\Presentacion\Requests;

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
            'tipo' => ['required', Rule::in(['casa', 'apartamento'])],
            'edificio_torre' => ['nullable', 'required_if:tipo,apartamento', 'string', 'max:60'],
            'calle_bloque' => ['nullable', 'required_if:tipo,casa', 'string', 'max:60'],
            'numero' => ['required', 'string', 'max:20'],
            'estado_ocupacion' => ['required', Rule::in(['ocupada', 'desocupada'])],
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
            'edificio_torre.required_if' => 'Indica el edificio o la torre.',
            'calle_bloque.required_if' => 'Indica la calle o el bloque.',
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
            'numero' => 'número',
            'estado_ocupacion' => 'ocupación',
        ];
    }
}
