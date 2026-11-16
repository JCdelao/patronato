<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Persistencia\Models\CategoriaFinanciera;

class RegistrarIngresoRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hoy = app(PeriodoService::class)->hoy()->toDateString();

        return [
            'fecha' => ['required', 'date_format:Y-m-d', "before_or_equal:{$hoy}"],
            'concepto' => ['required', 'string', 'max:255'],
            'categoria_id' => [
                'required',
                'integer',
                // Una categoría de ingreso activa del residencial; las cuotas entran solas desde los cobros
                Rule::exists('categorias_financieras', 'id')
                    ->where('residencial_id', $this->user()?->residencial_id)
                    ->where('tipo', CategoriaFinanciera::INGRESO)
                    ->where('activa', true)
                    ->whereNot('nombre', CategoriaFinancieraService::CUOTAS),
            ],
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'numero_comprobante' => ['nullable', 'string', 'max:60'],
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
            'fecha.required' => 'Escribe la fecha del ingreso.',
            'fecha.before_or_equal' => 'La fecha no puede ser posterior a hoy.',
            'categoria_id.required' => 'Elige la categoría del ingreso.',
            'categoria_id.exists' => 'Elige una categoría de ingreso válida. Las cuotas se registran solas al cobrar.',
            'monto.required' => 'Escribe el monto del ingreso.',
            'monto.gt' => 'El monto debe ser mayor que cero.',
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
            'categoria_id' => 'categoría',
            'numero_comprobante' => 'número de comprobante',
        ];
    }
}
