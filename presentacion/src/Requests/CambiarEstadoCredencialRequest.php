<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Credenciales\EstadoCredencial;

/**
 * Suspender, reactivar o marcar como vencido un sticker o un carnet, con su motivo
 */
class CambiarEstadoCredencialRequest extends FormRequest
{
    public const MOTIVO_MINIMO = 10;

    /**
     * Reglas de validación; el estado actual de la credencial decide si admite la acción
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(EstadoCredencial::CAMBIOS_DE_ESTADO)],
            'motivo' => ['required', 'string', 'min:'.self::MOTIVO_MINIMO, 'max:1000'],
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
            'accion.in' => 'Elige suspender, reactivar o marcar como vencido.',
            'motivo.required' => 'Escribe el motivo del cambio.',
            'motivo.min' => 'Explica el motivo con al menos '.self::MOTIVO_MINIMO.' caracteres.',
        ];
    }

    /**
     * Quita los espacios de los extremos del motivo antes de validar el largo
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }
}
