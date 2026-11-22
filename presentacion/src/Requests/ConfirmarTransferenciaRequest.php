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
            // Sin meses, la transferencia se aplica a los pendientes más antiguos
            'cargos' => ['nullable', 'array'],
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
            'cargos.array' => 'Los meses elegidos no son válidos.',
        ];
    }
}
