<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\ConceptoService;
use Patronato\Persistencia\Models\ConceptoCobro;

class GuardarConceptoRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100', $this->nombreLibre(...)],
            'tipo' => ['required', Rule::in([ConceptoCobro::RECURRENTE, ConceptoCobro::UNICO])],
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
            'tipo.in' => 'Elige si el concepto es recurrente o único.',
            'monto.min' => 'El monto no puede ser negativo.',
            'vigente_desde.date_format' => 'Elige el mes desde el que rige el monto.',
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
            'vigente_desde' => 'mes de inicio',
        ];
    }

    /**
     * Rechaza un nombre repetido, sin importar mayúsculas, o el de la cuota mensual
     */
    private function nombreLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $actual = $this->route('concepto');

        $motivo = app(ConceptoService::class)->motivoNombreNoDisponible(
            (string) $valor,
            $actual instanceof ConceptoCobro ? $actual->id : null,
        );

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }
}
