<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\StickerService;

class AsignarStickerRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'numero' => ['required', 'string', 'max:30', 'regex:/^\s*[\pL\pN][\pL\pN\-]*\s*$/u', $this->numeroLibre(...)],
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
            'numero.required' => 'Escribe el número del sticker.',
            'numero.regex' => 'El número solo admite letras, números y guiones, sin espacios.',
        ];
    }

    /**
     * Rechaza un número que ya tiene otro sticker activo del residencial
     */
    private function numeroLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $motivo = app(StickerService::class)->motivoNumeroNoDisponible((string) $valor);

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }
}
