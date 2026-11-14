<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CerrarCajaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * Si la justificación es obligatoria lo decide CierreCajaService, porque depende del total registrado.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'monto_entregado' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'justificacion' => ['nullable', 'string', 'max:1000'],
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
            'monto_entregado.required' => 'Escribe el monto entregado.',
            'monto_entregado.decimal' => 'El monto admite hasta dos decimales.',
            'monto_entregado.min' => 'El monto entregado no puede ser negativo.',
            'monto_entregado.max' => 'El monto es demasiado alto.',
            'justificacion.max' => 'La justificación admite hasta 1000 caracteres.',
        ];
    }
}
