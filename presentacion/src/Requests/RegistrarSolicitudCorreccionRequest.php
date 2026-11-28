<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\SolicitudCorreccionService;
use Patronato\Persistencia\Models\SolicitudCorreccion;

/**
 * Solicitud de corrección desde el portal (HU-54)
 *
 * Que la vivienda sea del residente lo revisa SolicitudCorreccionService::crear con el vínculo vigente.
 */
class RegistrarSolicitudCorreccionRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(array_keys(SolicitudCorreccion::TIPOS))],
            'vivienda_id' => ['required', 'integer'],
            'descripcion' => ['required', 'string', 'min:'.SolicitudCorreccionService::DESCRIPCION_MINIMA, 'max:2000'],
            'adjunto' => [
                'nullable', 'file',
                'mimes:'.implode(',', SolicitudCorreccionService::EXTENSIONES),
                'max:'.SolicitudCorreccionService::TAMANO_MAXIMO_KB,
            ],
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
            'tipo.required' => 'Elige qué quieres corregir.',
            'tipo.in' => 'Elige un tipo de la lista.',
            'vivienda_id.required' => 'Elige la vivienda.',
            'vivienda_id.integer' => 'Elige la vivienda.',
            'descripcion.required' => 'Describe qué hay que corregir.',
            'descripcion.min' => 'Explica la corrección con al menos '.SolicitudCorreccionService::DESCRIPCION_MINIMA.' caracteres.',
            'descripcion.max' => 'La descripción admite hasta 2000 caracteres.',
            'adjunto.file' => 'Adjunta un archivo válido.',
            'adjunto.uploaded' => 'No se pudo subir el archivo. Revisa que no pase de 5 MB.',
            'adjunto.mimes' => 'El archivo debe ser una imagen JPG o PNG, o un PDF.',
            'adjunto.max' => 'El archivo no puede pasar de 5 MB.',
        ];
    }

    /**
     * Quita los espacios de los extremos antes de validar el largo
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('descripcion'))) {
            $this->merge(['descripcion' => trim($this->input('descripcion'))]);
        }
    }
}
