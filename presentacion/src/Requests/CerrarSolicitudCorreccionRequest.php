<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\SolicitudCorreccionService;

/**
 * Respuesta al resolver o rechazar una solicitud de corrección; el residente la recibe por correo (HU-54)
 */
class CerrarSolicitudCorreccionRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'respuesta' => ['required', 'string', 'min:'.SolicitudCorreccionService::RESPUESTA_MINIMA, 'max:1000'],
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
            'respuesta.required' => 'Escribe la respuesta para el residente.',
            'respuesta.min' => 'La respuesta debe tener al menos '.SolicitudCorreccionService::RESPUESTA_MINIMA.' caracteres.',
            'respuesta.max' => 'La respuesta admite hasta 1000 caracteres.',
        ];
    }

    /**
     * Quita los espacios de los extremos antes de validar el largo
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('respuesta'))) {
            $this->merge(['respuesta' => trim($this->input('respuesta'))]);
        }
    }
}
