<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\IncidenciaService;
use Patronato\Negocio\Services\TurnoService;
use Patronato\Persistencia\Models\User;

class RegistrarIncidenciaRequest extends FormRequest
{
    /**
     * Solo una cuenta vinculada a un guardia activo registra incidencias
     */
    public function authorize(TurnoService $turnos): bool
    {
        return $this->user() instanceof User && $turnos->guardiaDe($this->user()) !== null;
    }

    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Un tipo activo del catálogo del mismo residencial
            'tipo_incidencia_id' => ['required', 'integer', Rule::exists('tipos_incidencia', 'id')
                ->where('residencial_id', $this->user()?->residencial_id)
                ->where('activo', true)],
            'descripcion' => ['required', 'string', 'max:2000'],
            // Fecha y hora de Honduras, como la manda el campo del formulario
            'ocurrida_en' => ['required', 'date_format:Y-m-d\TH:i'],
            'urgente' => ['nullable', 'boolean'],
            'foto' => [
                'nullable', 'file',
                'mimes:'.implode(',', IncidenciaService::EXTENSIONES),
                'max:'.IncidenciaService::TAMANO_MAXIMO_KB,
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
            'tipo_incidencia_id.required' => 'Elige el tipo de incidencia.',
            'tipo_incidencia_id.exists' => 'Elige un tipo de incidencia activo.',
            'descripcion.required' => 'Describe lo que pasó.',
            'descripcion.max' => 'La descripción no puede pasar de 2000 caracteres.',
            'ocurrida_en.required' => 'Escribe la fecha y la hora en que ocurrió.',
            'ocurrida_en.date_format' => 'Escribe la fecha y la hora en que ocurrió.',
            'foto.uploaded' => 'No se pudo subir la foto. Revisa que no pase de 5 MB.',
            'foto.mimes' => 'La foto debe ser una imagen JPG o PNG.',
            'foto.max' => 'La foto no puede pasar de 5 MB.',
        ];
    }
}
