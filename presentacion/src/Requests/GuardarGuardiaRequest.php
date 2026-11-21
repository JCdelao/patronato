<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Persistencia\Models\Guardia;

class GuardarGuardiaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'identidad' => [
                'required', 'string', 'max:30',
                Rule::unique('guardias', 'identidad')
                    ->where('residencial_id', $this->user()?->residencial_id)
                    ->ignore($this->route('guardia')),
            ],
            'telefono' => ['required', 'string', 'max:30'],
            'turno' => ['required', Rule::in(Guardia::TURNOS)],
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
            'nombre.required' => 'Escribe el nombre del guardia.',
            'identidad.required' => 'Escribe el número de identidad.',
            'identidad.unique' => 'Ya hay un guardia con esa identidad en el residencial.',
            'telefono.required' => 'Escribe el teléfono.',
            'turno.required' => 'Elige el turno del guardia.',
            'turno.in' => 'Elige día, tarde, noche, 24 horas o rotativo.',
        ];
    }
}
