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
     * Sin placa todavía, se pide el comprobante que valida la circulación del vehículo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actual = $this->vehiculo();

        $reglas = [
            'tipo' => ['required', Rule::in(Vehiculo::TIPOS)],
            'sin_placa' => ['nullable', 'boolean'],
            'marca' => ['nullable', 'string', 'max:60'],
            'modelo' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:30'],
            'conductores' => ['nullable', 'array'],
            'conductores.*' => ['integer', 'distinct'],
        ];

        if ($this->sinPlaca()) {
            $reglas['comprobante_tipo'] = ['required', Rule::in(array_keys(Vehiculo::TIPOS_COMPROBANTE))];
            $reglas['comprobante_descripcion'] = ['nullable', 'required_if:comprobante_tipo,otro', 'string', 'max:100'];
            $reglas['comprobante_numero'] = ['required', 'string', 'max:40', 'regex:/^[\pL\pN][\pL\pN \-\/]*$/u', $this->comprobanteLibre(...)];
            $reglas['comprobante_vence'] = ['required', 'date_format:Y-m-d', $this->venceVigente(...)];
            // Al editar, sin archivo nuevo se conserva el anterior
            $reglas['comprobante_archivo'] = [$actual === null ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        } else {
            $reglas['placa'] = ['required', 'string', 'max:20', 'regex:/^[\pL\pN][\pL\pN \-]*$/u', $this->placaLibre(...)];
        }

        if ($actual === null) {
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
            'comprobante_tipo.required' => 'Elige el tipo de comprobante.',
            'comprobante_tipo.in' => 'Elige un tipo de comprobante de la lista.',
            'comprobante_descripcion.required_if' => 'Describe el comprobante.',
            'comprobante_numero.required' => 'Escribe el número del comprobante.',
            'comprobante_numero.regex' => 'El número solo admite letras, números, espacios, guiones y barras.',
            'comprobante_vence.required' => 'Escribe hasta cuándo vale el comprobante.',
            'comprobante_archivo.required' => 'Adjunta la foto o el PDF del comprobante.',
            'comprobante_archivo.mimes' => 'El comprobante debe ser un PDF, JPG o PNG.',
            'comprobante_archivo.max' => 'El comprobante no puede pesar más de 5 MB.',
        ];
    }

    /**
     * Vehículo que se edita, o null al registrar
     */
    private function vehiculo(): ?Vehiculo
    {
        $vehiculo = $this->route('vehiculo');

        return $vehiculo instanceof Vehiculo ? $vehiculo : null;
    }

    /**
     * Sin placa todavía; un vehículo que ya tiene placa no vuelve a quedar sin ella
     */
    private function sinPlaca(): bool
    {
        return ($this->vehiculo()?->tienePlaca() ?? false) === false && $this->boolean('sin_placa');
    }

    /**
     * Rechaza un número de comprobante que ya usa otro vehículo activo
     */
    private function comprobanteLibre(string $atributo, mixed $valor, Closure $fallar): void
    {
        $motivo = app(VehiculoService::class)->motivoComprobanteNoDisponible((string) $valor, $this->vehiculo()?->id);

        if ($motivo !== null) {
            $fallar($motivo);
        }
    }

    /**
     * El comprobante no puede estar vencido, salvo al editar otros datos sin cambiar su fecha
     */
    private function venceVigente(string $atributo, mixed $valor, Closure $fallar): void
    {
        if ($this->vehiculo()?->comprobante_vence?->toDateString() === $valor) {
            return;
        }

        if ((string) $valor < app(VehiculoService::class)->hoy()) {
            $fallar('El comprobante ya venció; escribe una fecha de hoy en adelante.');
        }
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
