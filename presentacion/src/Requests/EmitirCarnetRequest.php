<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\PeriodoService;

class EmitirCarnetRequest extends FormRequest
{
    /**
     * Reglas de validación: la vigencia termina después de hoy y no pasa de cinco años
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hoy = app(PeriodoService::class)->hoy();

        return [
            'vigente_hasta' => ['required', 'date_format:Y-m-d', 'after:'.$hoy->toDateString(), 'before_or_equal:'.$hoy->addYears(5)->toDateString()],
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
            'vigente_hasta.required' => 'Elige hasta cuándo vale el carnet.',
            'vigente_hasta.after' => 'La vigencia debe terminar después de hoy.',
            'vigente_hasta.before_or_equal' => 'La vigencia no puede pasar de cinco años.',
        ];
    }
}
