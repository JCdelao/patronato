<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Patronato\Negocio\Services\CarnetService;

/**
 * Vigencia del carnet nuevo y motivo de la renovación
 */
class RenovarCarnetRequest extends EmitirCarnetRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'motivo' => ['required', 'string', 'min:'.CarnetService::MOTIVO_MINIMO, 'max:1000'],
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
            ...parent::messages(),
            'motivo.required' => 'Escribe el motivo de la renovación.',
            'motivo.min' => 'Explica el motivo con al menos '.CarnetService::MOTIVO_MINIMO.' caracteres.',
        ];
    }

    /**
     * Quita los espacios de los extremos del motivo antes de validar el largo
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }
}
