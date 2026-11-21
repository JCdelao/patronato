<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\TurnoService;
use Patronato\Persistencia\Models\Turno;

class ProgramarTurnosRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Un guardia activo del mismo residencial
            'guardia_id' => ['required', 'integer', Rule::exists('guardias', 'id')
                ->where('residencial_id', $this->user()?->residencial_id)
                ->where('activo', true)],
            'tipo' => ['required', Rule::in(Turno::TIPOS)],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'horas' => ['required', 'integer', 'between:1,'.Turno::MAXIMO_HORAS],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde', 'before_or_equal:'.$this->limite()],
            // Por días de la semana, o un día de por medio
            'repetir' => ['required', Rule::in(['semana', 'alternos'])],
            'dias' => ['exclude_unless:repetir,semana', 'required', 'array', 'min:1'],
            'dias.*' => ['integer', 'between:1,7', 'distinct'],
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
            'guardia_id.required' => 'Elige el guardia.',
            'guardia_id.exists' => 'Elige un guardia activo del residencial.',
            'tipo.required' => 'Elige el tipo de turno.',
            'tipo.in' => 'Elige día, tarde, noche o 24 horas.',
            'hora_inicio.date_format' => 'Escribe la hora como 06:00.',
            'horas.between' => 'Un turno dura de 1 a '.Turno::MAXIMO_HORAS.' horas.',
            'hasta.after_or_equal' => 'La fecha final no puede ser antes de la inicial.',
            'hasta.before_or_equal' => 'Se programan como máximo '.TurnoService::MAXIMO_DIAS.' días de una vez.',
            'dias.required' => 'Elige al menos un día de la semana.',
        ];
    }

    /**
     * Última fecha que se puede programar a partir de la fecha inicial
     */
    private function limite(): string
    {
        $desde = $this->string('desde')->toString();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1
            ? date('Y-m-d', (int) strtotime($desde.' +'.(TurnoService::MAXIMO_DIAS - 1).' days'))
            : $desde;
    }
}
