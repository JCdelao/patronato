<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\PresupuestoService;
use Patronato\Persistencia\Models\CategoriaFinanciera;

class GuardarPresupuestoRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $anios = app(PresupuestoService::class)->anios();

        return [
            'anio' => ['required', 'integer', "min:{$anios['minimo']}", "max:{$anios['maximo']}"],
            'montos' => ['required', 'array'],
            'montos.*.categoria_id' => [
                'required',
                'integer',
                'distinct',
                // Solo categorías de egreso del mismo residencial
                Rule::exists('categorias_financieras', 'id')
                    ->where('residencial_id', $this->user()?->residencial_id)
                    ->where('tipo', CategoriaFinanciera::EGRESO),
            ],
            'montos.*.monto' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
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
            'montos.*.categoria_id.exists' => 'Una de las categorías no es un egreso de este residencial.',
            'montos.*.monto.numeric' => 'Escribe el monto como un número.',
            'montos.*.monto.min' => 'El presupuesto no puede ser negativo.',
            'montos.*.monto.decimal' => 'El presupuesto admite hasta dos decimales.',
        ];
    }

    /**
     * Montos listos para el servicio
     *
     * @return list<array{categoria_id: int|string, monto: string|null}>
     */
    public function montos(): array
    {
        /** @var array<int, array{categoria_id: int|string, monto?: string|int|float|null}> $filas */
        $filas = $this->validated('montos');

        return array_values(array_map(fn (array $fila): array => [
            'categoria_id' => $fila['categoria_id'],
            'monto' => isset($fila['monto']) ? (string) $fila['monto'] : null,
        ], $filas));
    }
}
