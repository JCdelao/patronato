<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarMedioEntradaRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:40'],
            'requiere_placa' => ['required', 'boolean'],
            'activo' => ['required', 'boolean'],
        ];
    }
}
