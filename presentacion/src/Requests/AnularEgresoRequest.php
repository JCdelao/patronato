<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\EgresoService;

class AnularEgresoRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:'.EgresoService::MOTIVO_MINIMO, 'max:1000'],
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
            'motivo.required' => 'Escribe el motivo de la anulación.',
            'motivo.min' => 'Explica el motivo con al menos '.EgresoService::MOTIVO_MINIMO.' caracteres.',
            'motivo.max' => 'El motivo admite hasta 1000 caracteres.',
        ];
    }

    /**
     * Quita los espacios de los extremos antes de validar el largo
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }
}
