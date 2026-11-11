<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Reglas\ProfileValidationRules;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
