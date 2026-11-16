<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Persistencia\Models\CategoriaFinanciera;

class RegistrarCategoriaEgresoRequest extends FormRequest
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
            'nombre.required' => 'Escribe el nombre de la categoría.',
        ];
    }

    /**
     * Rechaza un nombre repetido entre las categorías de egreso, sin importar mayúsculas
     */
    private function nombreLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $motivo = app(CategoriaFinancieraService::class)->motivoNombreNoDisponible(CategoriaFinanciera::EGRESO, (string) $valor);

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }
}
