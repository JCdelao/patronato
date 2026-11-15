<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\TransferenciaService;

class RegistrarTransferenciaRequest extends FormRequest
{
    /**
     * Quita los espacios sobrantes de los textos antes de validar
     */
    protected function prepareForValidation(): void
    {
        $textos = [];

        foreach (['banco', 'numero_referencia', 'cuenta_receptora'] as $campo) {
            if (is_string($this->input($campo))) {
                $textos[$campo] = TransferenciaService::normalizar($this->input($campo));
            }
        }

        $this->merge($textos);
    }

    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vivienda_id' => [
                'required', 'integer',
                Rule::exists('viviendas', 'id')->where('residencial_id', $this->user()->residencial_id)->where('activa', true),
            ],
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.TransferenciaService::hoy()],
            'banco' => ['required', 'string', 'max:100'],
            'numero_referencia' => ['required', 'string', 'max:60'],
            'cuenta_receptora' => ['required', 'string', 'max:60'],
            'comprobante' => [
                'required', 'file',
                'mimes:'.implode(',', TransferenciaService::EXTENSIONES),
                'max:'.TransferenciaService::TAMANO_MAXIMO_KB,
            ],
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
            'vivienda_id.required' => 'Selecciona una vivienda.',
            'vivienda_id.exists' => 'Selecciona una vivienda válida.',
            'monto.required' => 'Escribe el monto transferido.',
            'monto.gt' => 'El monto debe ser mayor que cero.',
            'monto.decimal' => 'El monto admite hasta dos decimales.',
            'fecha_pago.required' => 'Indica la fecha de la transferencia.',
            'fecha_pago.date_format' => 'Indica una fecha válida.',
            'fecha_pago.before_or_equal' => 'La fecha de la transferencia no puede ser posterior a hoy.',
            'banco.required' => 'Escribe el banco de la transferencia.',
            'numero_referencia.required' => 'Escribe el número de referencia.',
            'cuenta_receptora.required' => 'Escribe la cuenta que recibió la transferencia.',
            'comprobante.required' => 'Adjunta el comprobante de la transferencia.',
            'comprobante.file' => 'Adjunta el comprobante de la transferencia.',
            'comprobante.uploaded' => 'No se pudo subir el comprobante. Revisa que no pase de 5 MB.',
            'comprobante.mimes' => 'El comprobante debe ser una imagen JPG o PNG, o un PDF.',
            'comprobante.max' => 'El comprobante no puede pasar de 5 MB.',
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
            'numero_referencia' => 'número de referencia',
            'cuenta_receptora' => 'cuenta receptora',
        ];
    }
}
