<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Motivo para anular un egreso o un ingreso; ninguno de los dos se borra
 */
class AnularMovimientoRequest extends FormRequest
{
    public const MOTIVO_MINIMO = 10;

    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:'.self::MOTIVO_MINIMO, 'max:1000'],
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
            'motivo.min' => 'Explica el motivo con al menos '.self::MOTIVO_MINIMO.' caracteres.',
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
