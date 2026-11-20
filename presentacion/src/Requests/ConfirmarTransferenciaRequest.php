<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Meses a los que se aplica una transferencia al confirmarla (HU-26)
 *
 * El monto no se pide: es el de la transferencia.
 */
class ConfirmarTransferenciaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            'cargos.required' => 'Elige al menos un mes.',
            'cargos.min' => 'Elige al menos un mes.',
        ];
    }
}
