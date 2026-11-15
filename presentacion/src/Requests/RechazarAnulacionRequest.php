<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\AnulacionPagoService;

class RechazarAnulacionRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:'.AnulacionPagoService::MOTIVO_MINIMO, 'max:1000'],
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
            'motivo.required' => 'Escribe el motivo del rechazo.',
            'motivo.min' => 'Explica el rechazo con al menos '.AnulacionPagoService::MOTIVO_MINIMO.' caracteres.',
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
