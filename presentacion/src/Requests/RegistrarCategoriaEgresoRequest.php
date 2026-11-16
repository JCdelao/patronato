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
     * Tipo de las categorías entre las que no se puede repetir el nombre
     */
    protected function tipo(): string
    {
        return CategoriaFinanciera::EGRESO;
    }

    /**
     * Rechaza un nombre repetido entre las categorías del mismo tipo, sin importar mayúsculas
     */
    private function nombreLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $motivo = app(CategoriaFinancieraService::class)->motivoNombreNoDisponible($this->tipo(), (string) $valor);

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }
}
