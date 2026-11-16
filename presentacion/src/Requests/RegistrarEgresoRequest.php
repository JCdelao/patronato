<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Egreso;

class RegistrarEgresoRequest extends FormRequest
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
                // Solo una categoría de egreso activa del mismo residencial
                Rule::exists('categorias_financieras', 'id')
                    ->where('residencial_id', $this->user()?->residencial_id)
                    ->where('tipo', CategoriaFinanciera::EGRESO)
                    ->where('activa', true),
            ],
            'proveedor' => ['nullable', 'string', 'max:150'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'forma_pago' => ['nullable', Rule::in(Egreso::FORMAS_PAGO)],
            'numero_comprobante' => ['required', 'string', 'max:60'],
            'factura' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
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
            'fecha.required' => 'Escribe la fecha del egreso.',
            'fecha.before_or_equal' => 'La fecha no puede ser posterior a hoy.',
            'categoria_id.required' => 'Elige la categoría del egreso.',
            'categoria_id.exists' => 'Elige una categoría de egreso válida.',
            'monto.required' => 'Escribe el monto del egreso.',
            'monto.gt' => 'El monto debe ser mayor que cero.',
            'forma_pago.in' => 'Elige efectivo, transferencia o cheque.',
            'numero_comprobante.required' => 'Escribe el número de comprobante.',
            'factura.mimes' => 'La factura debe ser un PDF, JPG o PNG.',
            'factura.max' => 'La factura no puede pesar más de 5 MB.',
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
            'forma_pago' => 'forma de pago',
            'numero_comprobante' => 'número de comprobante',
        ];
    }
}
