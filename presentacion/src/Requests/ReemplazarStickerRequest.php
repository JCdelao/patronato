<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\StickerService;

class ReemplazarStickerRequest extends FormRequest
{
    /**
     * Reglas de validación; el servicio comprueba que el número nuevo esté libre
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'numero' => ['required', 'string', 'max:30', 'regex:/^\s*[\pL\pN][\pL\pN\-]*\s*$/u'],
            'motivo' => ['required', 'string', 'min:'.StickerService::MOTIVO_MINIMO, 'max:1000'],
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
            'numero.required' => 'Escribe el número del sticker nuevo.',
            'numero.regex' => 'El número solo admite letras, números y guiones, sin espacios.',
            'motivo.required' => 'Escribe el motivo del reemplazo.',
            'motivo.min' => 'Explica el motivo con al menos '.StickerService::MOTIVO_MINIMO.' caracteres.',
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
