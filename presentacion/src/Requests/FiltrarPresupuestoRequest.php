<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\PresupuestoService;

class FiltrarPresupuestoRequest extends FormRequest
{
    /**
     * Reglas de validación del año
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $anios = app(PresupuestoService::class)->anios();

        return [
            'anio' => ['nullable', 'integer', "min:{$anios['minimo']}", "max:{$anios['maximo']}"],
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
            'anio.min' => 'Elige un año desde el 2000.',
            'anio.max' => 'Solo se puede planificar hasta el año siguiente.',
        ];
    }

    /**
     * Año pedido; sin él, el año en curso
     */
    public function anio(): int
    {
        return $this->filled('anio') ? $this->integer('anio') : app(PresupuestoService::class)->anios()['actual'];
    }
}
