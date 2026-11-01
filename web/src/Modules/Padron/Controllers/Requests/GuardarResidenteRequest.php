<?php

namespace Patronato\Modules\Padron\Controllers\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarResidenteRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $residencialId = $this->user()->residencial_id;

        return [
            'vivienda_id' => [
                'required', 'integer',
                Rule::exists('viviendas', 'id')->where('residencial_id', $residencialId)->where('activa', true),
            ],
            'tipo' => ['required', Rule::in(['propietario', 'inquilino', 'familiar'])],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'identidad' => [
                'nullable', 'string', 'max:30',
                Rule::unique('residentes', 'identidad')
                    ->where('residencial_id', $residencialId)
                    ->ignore($this->route('residente')),
            ],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'es_responsable_pago' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vivienda_id.required' => 'Selecciona una vivienda.',
            'vivienda_id.exists' => 'Selecciona una vivienda válida.',
            'identidad.unique' => 'Ya existe un residente con esa identidad.',
        ];
    }
}
