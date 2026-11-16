<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Patronato\Negocio\Reportes\ConstructorInformeFinanciero;
use Patronato\Negocio\Services\PeriodoService;

class FiltrarInformeFinancieroRequest extends FormRequest
{
    /**
     * Reglas de validación del período
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hoy = app(PeriodoService::class)->hoy();

        return [
            'tipo' => ['nullable', Rule::in([ConstructorInformeFinanciero::MENSUAL, ConstructorInformeFinanciero::ANUAL])],
            'anio' => ['nullable', 'integer', 'min:2000', "max:{$hoy->year}"],
            'mes' => ['nullable', 'integer', 'min:1', 'max:12', $this->mesNoFuturo(...)],
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
            'tipo.in' => 'Elige un informe mensual o anual.',
            'anio.min' => 'Elige un año desde el 2000.',
            'anio.max' => 'El año no puede ser posterior al actual.',
            'mes.min' => 'Elige un mes válido.',
            'mes.max' => 'Elige un mes válido.',
        ];
    }

    /**
     * Período listo para el servicio; lo que falte toma el mes en curso
     *
     * @param  array{tipo: string, anio: int, mes: int}  $predeterminado
     * @return array{tipo: string, anio: int, mes: int}
     */
    public function periodo(array $predeterminado): array
    {
        return [
            'tipo' => $this->string('tipo')->toString() === ConstructorInformeFinanciero::ANUAL
                ? ConstructorInformeFinanciero::ANUAL
                : ConstructorInformeFinanciero::MENSUAL,
            'anio' => $this->filled('anio') ? $this->integer('anio') : $predeterminado['anio'],
            'mes' => $this->filled('mes') ? $this->integer('mes') : $predeterminado['mes'],
        ];
    }

    /**
     * Un mes del año en curso que todavía no llega no tiene informe
     */
    private function mesNoFuturo(string $atributo, mixed $valor, Closure $fallar): void
    {
        $hoy = app(PeriodoService::class)->hoy();

        if ($this->integer('anio', $hoy->year) === $hoy->year && (int) $valor > $hoy->month) {
            $fallar('El mes no puede ser posterior al actual.');
        }
    }
}
