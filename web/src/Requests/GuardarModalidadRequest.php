<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Persistencia\Models\ModalidadCobro;

class GuardarModalidadRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'modalidad' => ['required', Rule::in([ModalidadCobro::UNIFICADA, ModalidadCobro::DIVERSIFICADA])],
            'desglose' => ['nullable', 'array', 'max:10'],
            'desglose.*.concepto' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'desglose.*.monto' => ['required', 'numeric', 'min:0', 'max:999999'],
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
            'modalidad.in' => 'Elige la modalidad unificada o la diversificada.',
            'desglose.*.concepto.required' => 'Escribe el nombre del concepto.',
            'desglose.*.concepto.distinct' => 'Ese concepto ya está en el desglose.',
            'desglose.*.monto.required' => 'Escribe el monto.',
            'desglose.*.monto.min' => 'El monto no puede ser negativo.',
        ];
    }
}
