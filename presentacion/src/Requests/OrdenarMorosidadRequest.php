<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\ReporteMorosidadService;

/**
 * Orden del reporte de morosidad: por monto o por antigüedad de la deuda
 */
class OrdenarMorosidadRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'orden' => ['nullable', Rule::in(ReporteMorosidadService::ORDENES)],
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
            'orden.in' => 'Ordena por monto o por antigüedad.',
        ];
    }

    /**
     * Orden elegido; por monto si no se indica
     */
    public function orden(): string
    {
        return $this->string('orden')->toString() ?: ReporteMorosidadService::POR_MONTO;
    }
}
