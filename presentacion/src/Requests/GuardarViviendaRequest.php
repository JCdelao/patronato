<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Persistencia\Models\Vivienda;

class GuardarViviendaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $residencialId = $this->user()->residencial_id;
        $vivienda = $this->route('vivienda');
        $tipoActual = $vivienda instanceof Vivienda ? $vivienda->tipo_vivienda_id : null;

        return [
            'codigo' => [
                'required', 'string', 'max:30',
                Rule::unique('viviendas', 'codigo')
                    ->where('residencial_id', $residencialId)
                    ->ignore($this->route('vivienda')),
            ],
            // Un tipo desactivado solo sirve para la vivienda que ya lo tenía
            'tipo_vivienda_id' => [
                'required', 'integer',
                Rule::exists('tipos_vivienda', 'id')
                    ->where('residencial_id', $residencialId)
                    ->where(fn (Builder $query) => $query->where('activo', true)->orWhere('id', $tipoActual)),
            ],
            'vivienda_padre_id' => [
                'nullable', 'integer',
                Rule::exists('viviendas', 'id')->where('residencial_id', $residencialId)->where('activa', true),
            ],
            'edificio_torre' => ['nullable', 'string', 'max:60'],
            'calle_bloque' => ['nullable', 'string', 'max:60'],
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
            'tipo_vivienda_id.required' => 'Elige el tipo de vivienda.',
            'tipo_vivienda_id.exists' => 'Elige un tipo de vivienda activo.',
            'vivienda_padre_id.exists' => 'Elige una vivienda activa.',
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
