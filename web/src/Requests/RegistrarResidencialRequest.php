<?php

namespace Patronato\Presentacion\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Patronato\Negocio\Services\ResidencialService;

class RegistrarResidencialRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, Closure $fail): void {
                if (app(ResidencialService::class)->nombreUsado((string) $value)) {
                    $fail('Ya existe un residencial con ese nombre.');
                }
            }],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'admin_nombre' => ['required', 'string', 'max:255'],
            'admin_correo' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
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
            'nombre.required' => 'El nombre del residencial es obligatorio.',
            'correo.email' => 'El correo del residencial no es válido.',
            'logo.image' => 'El logo debe ser una imagen PNG, JPG o WEBP.',
            'logo.mimes' => 'El logo debe ser una imagen PNG, JPG o WEBP.',
            'logo.max' => 'El logo no puede pesar más de 2 MB.',
            'admin_nombre.required' => 'El nombre del administrador técnico es obligatorio.',
            'admin_correo.required' => 'El correo del administrador técnico es obligatorio.',
            'admin_correo.email' => 'El correo del administrador técnico no es válido.',
            'admin_correo.unique' => 'Ya existe una cuenta con ese correo.',
        ];
    }

    /**
     * Prepara los datos antes de validar
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('admin_correo'))) {
            $this->merge(['admin_correo' => Str::lower($this->input('admin_correo'))]);
        }
    }
}
