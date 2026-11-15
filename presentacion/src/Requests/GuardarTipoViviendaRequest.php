<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\TipoViviendaService;
use Patronato\Persistencia\Models\TipoVivienda;

class GuardarTipoViviendaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:60', $this->nombreLibre(...)],
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'vigente_desde' => ['required', 'date_format:Y-m'],
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
            'monto.min' => 'La cuota no puede ser negativa.',
            'vigente_desde.date_format' => 'Elige el mes desde el que rige la cuota.',
        ];
    }

    /**
     * Nombres de los campos en los mensajes
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monto' => 'cuota mensual',
            'vigente_desde' => 'mes de inicio',
        ];
    }

    /**
     * Rechaza un nombre que otro tipo del residencial ya usa
     */
    private function nombreLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $actual = $this->route('tipo');

        $motivo = app(TipoViviendaService::class)->motivoNombreNoDisponible(
            (int) $this->user()->residencial_id,
            (string) $valor,
            $actual instanceof TipoVivienda ? $actual->id : null,
        );

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }
}
