<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Services\VehiculoService;
use Patronato\Persistencia\Models\Vehiculo;

class GuardarVehiculoRequest extends FormRequest
{
    /**
     * Reglas de validación; la vivienda solo se elige al registrar
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reglas = [
            'tipo' => ['required', Rule::in(Vehiculo::TIPOS)],
            'placa' => ['required', 'string', 'max:20', 'regex:/^[\pL\pN][\pL\pN \-]*$/u', $this->placaLibre(...)],
            'marca' => ['nullable', 'string', 'max:60'],
            'modelo' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:30'],
            'conductores' => ['nullable', 'array'],
            'conductores.*' => ['integer', 'distinct'],
        ];

        if (! $this->route('vehiculo') instanceof Vehiculo) {
            // Una vivienda activa del mismo residencial
            $reglas['vivienda_id'] = ['required', 'integer', Rule::exists('viviendas', 'id')
                ->where('residencial_id', $this->user()?->residencial_id)
                ->where('activa', true)];
        }

        return $reglas;
    }

    /**
     * Mensajes de validación
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vivienda_id.required' => 'Elige la vivienda del vehículo.',
            'vivienda_id.exists' => 'Elige una vivienda activa del residencial.',
            'tipo.required' => 'Elige si es carro, moto u otro.',
            'tipo.in' => 'Elige si es carro, moto u otro.',
            'placa.required' => 'Escribe la placa.',
            'placa.regex' => 'La placa solo admite letras, números, espacios y guiones.',
        ];
    }

    /**
     * Rechaza una placa que ya usa otro vehículo activo, sin importar mayúsculas
     */
    private function placaLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $actual = $this->route('vehiculo');

        $motivo = app(VehiculoService::class)->motivoPlacaNoDisponible(
            (string) $valor,
            $actual instanceof Vehiculo ? $actual->id : null,
        );

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }
}
