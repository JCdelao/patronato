<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\AjusteService;
use Patronato\Persistencia\Models\User;

/**
 * Corrección al saldo de una vivienda
 *
 * El tipo, la fecha y quién autoriza los pone el sistema, no el formulario.
 */
class RegistrarAjusteRequest extends FormRequest
{
    /**
     * Solo el presidente y el tesorero con el permiso de ajustes; se revisa antes de validar los campos
     */
    public function authorize(AjusteService $ajustes): bool
    {
        $usuario = $this->user();

        return $usuario instanceof User && $ajustes->puedeRegistrar($usuario);
    }

    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Activas e inactivas, solo del residencial del usuario
            'vivienda_id' => [
                'required', 'integer',
                Rule::exists('viviendas', 'id')->where('residencial_id', $this->user()->residencial_id),
            ],
            'sentido' => ['required', Rule::in([AjusteService::AUMENTA, AjusteService::DISMINUYE])],
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'motivo' => ['required', 'string', 'min:'.AjusteService::MOTIVO_MINIMO, 'max:1000'],
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
            'sentido.required' => 'Indica si la corrección aumenta o disminuye el saldo.',
            'sentido.in' => 'Indica si la corrección aumenta o disminuye el saldo.',
            'monto.required' => 'Escribe el monto de la corrección.',
            'monto.decimal' => 'El monto admite hasta dos decimales.',
            'monto.gt' => 'El monto debe ser mayor que cero.',
            'monto.max' => 'El monto es demasiado alto.',
            'motivo.required' => 'Escribe el motivo de la corrección.',
            'motivo.min' => 'Escribe un motivo de al menos '.AjusteService::MOTIVO_MINIMO.' caracteres.',
            'motivo.max' => 'El motivo no puede pasar de 1000 caracteres.',
        ];
    }
}
