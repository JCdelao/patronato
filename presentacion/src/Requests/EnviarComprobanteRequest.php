<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnviarComprobanteRequest extends FormRequest
{
    /**
     * Máximo de correos por envío
     */
    private const MAXIMO_CORREOS = 5;

    /**
     * Reglas de validación
     *
     * Se aceptan varios correos separados por coma.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'correos' => [
                'required', 'string', 'max:500',
                function (string $atributo, mixed $valor, Closure $fallar): void {
                    $correos = self::separar((string) $valor);

                    if ($correos === []) {
                        $fallar('Escribe al menos un correo.');
                    } elseif (count($correos) > self::MAXIMO_CORREOS) {
                        $fallar('Escribe como máximo '.self::MAXIMO_CORREOS.' correos.');
                    } else {
                        foreach ($correos as $correo) {
                            if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
                                $fallar("«{$correo}» no es un correo válido.");

                                return;
                            }
                        }
                    }
                },
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
            'correos.required' => 'Escribe al menos un correo.',
            'correos.max' => 'La lista de correos es demasiado larga.',
        ];
    }

    /**
     * Correos validados, sin espacios ni repetidos
     *
     * @return list<string>
     */
    public function correos(): array
    {
        return self::separar($this->string('correos')->toString());
    }

    /**
     * @return list<string>
     */
    private static function separar(string $valor): array
    {
        $partes = array_map(trim(...), preg_split('/[,;\s]+/', $valor) ?: []);

        return array_values(array_unique(array_filter($partes, fn (string $parte) => $parte !== '')));
    }
}
