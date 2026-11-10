<?php

namespace Patronato\Modules\Finanzas\Controllers\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Patronato\Modules\Finanzas\Models\ConceptoCobro;
use Patronato\Modules\Finanzas\Services\TarifaService;

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
        $nombre = Str::lower(trim((string) $valor));

        if ($nombre === Str::lower(TarifaService::CONCEPTO_CUOTA)) {
            $fallar('Ese nombre está reservado para la cuota de cada vivienda.');

            return;
        }

        $actual = $this->route('concepto');

        $repetido = ConceptoCobro::query()
            ->whereRaw('lower(nombre) = ?', [$nombre])
            ->when($actual instanceof ConceptoCobro ? $actual->id : null, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($repetido) {
            $fallar('Ya existe un concepto con ese nombre.');
        }
    }
}
