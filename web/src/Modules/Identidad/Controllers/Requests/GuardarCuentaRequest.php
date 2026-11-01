<?php

namespace Patronato\Modules\Identidad\Controllers\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Patronato\Modules\Identidad\Models\Rol;

class GuardarCuentaRequest extends FormRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $cuenta = $this->route('cuenta');
        $cuentaId = $cuenta instanceof User ? $cuenta->id : null;

        $esResidente = Rol::query()->whereKey($this->input('rol_id'))->value('codigo') === Rol::RESIDENTE;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($cuentaId)],
            'rol_id' => [
                'required', 'integer',
                Rule::exists('roles', 'id')->whereNotIn('codigo', [Rol::ADMIN_PLATAFORMA, Rol::ADMIN_TECNICO]),
            ],
            'residente_id' => [
                Rule::requiredIf($esResidente), 'nullable', 'integer',
                Rule::exists('residentes', 'id')
                    ->where('residencial_id', $this->user()->residencial_id)
                    ->where('activo', true)
                    ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', $cuentaId)),
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
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'rol_id.required' => 'Selecciona un rol.',
            'rol_id.exists' => 'Selecciona un rol válido.',
            'residente_id.required' => 'Selecciona el residente de esta cuenta.',
            'residente_id.exists' => 'Ese residente no está disponible.',
        ];
    }

    /**
     * Prepara los datos antes de validar
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }
}
