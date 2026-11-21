<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\TurnoService;
use Patronato\Persistencia\Models\User;

class EntregarTurnoRequest extends FormRequest
{
    /**
     * Solo una cuenta vinculada a un guardia activo entrega turnos
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
            // Opcional: un guardia activo del mismo residencial
            'guardia_recibe_id' => ['nullable', 'integer', Rule::exists('guardias', 'id')
                ->where('residencial_id', $this->user()?->residencial_id)
                ->where('activo', true)],
            'novedades' => ['nullable', 'string', 'max:2000'],
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
            'guardia_recibe_id.exists' => 'Elige un guardia activo del residencial.',
            'novedades.max' => 'Las novedades no pueden pasar de 2000 caracteres.',
        ];
    }
}
