<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Reportes\BitacoraTextos;
use Patronato\Persistencia\Models\Turno;

class FiltrarBitacoraAccesosRequest extends FormRequest
{
    /**
     * Reglas de validación de los filtros
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $delResidencial = fn (string $tabla) => Rule::exists($tabla, 'id')->where('residencial_id', $this->user()->residencial_id);

        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'tipo' => ['nullable', Rule::in(array_keys(BitacoraTextos::TIPOS))],
            'turno' => ['nullable', Rule::in(Turno::TIPOS)],
            'guardia' => ['nullable', 'integer', $delResidencial('guardias')],
            'vivienda' => ['nullable', 'integer', $delResidencial('viviendas')],
            'medio' => ['nullable', 'integer', $delResidencial('medios_entrada')],
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
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'tipo.in' => 'Elige entradas, salidas o incidencias.',
            'turno.in' => 'Elige un turno válido.',
            'guardia.exists' => 'Elige un guardia del residencial.',
            'vivienda.exists' => 'Elige una vivienda del residencial.',
            'medio.exists' => 'Elige un medio de entrada del residencial.',
        ];
    }

    /**
     * Filtros listos para el servicio; las fechas vacías toman las predeterminadas
     *
     * @param  array{desde: string, hasta: string}  $predeterminadas
     * @return array{desde: string, hasta: string, tipo: string|null, turno: string|null, guardia_id: int|null, vivienda_id: int|null, medio_entrada_id: int|null}
     */
    public function filtros(array $predeterminadas): array
    {
        $texto = fn (string $campo): ?string => $this->filled($campo) ? $this->string($campo)->toString() : null;
        $numero = fn (string $campo): ?int => $this->filled($campo) ? $this->integer($campo) : null;

        return [
            'desde' => $texto('desde') ?? $predeterminadas['desde'],
            'hasta' => $texto('hasta') ?? $predeterminadas['hasta'],
            'tipo' => $texto('tipo'),
            'turno' => $texto('turno'),
            'guardia_id' => $numero('guardia'),
            'vivienda_id' => $numero('vivienda'),
            'medio_entrada_id' => $numero('medio'),
        ];
    }
}
