<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Patronato\Negocio\Services\VisitaService;
use Patronato\Persistencia\Models\User;

class RegistrarVisitaRequest extends FormRequest
{
    public function authorize(VisitaService $visitas): bool
    {
        $usuario = $this->user();
        if (! $usuario instanceof User) {
            return false;
        }

        $visitas->guardia($usuario);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'nombre_visitante' => ['required', 'string', 'max:150'],
            'identidad' => ['required', 'string', 'max:30'],
            'vivienda_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'medio_entrada_id' => ['required', 'integer'],
            'placa' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nombre_visitante' => 'nombre del visitante',
            'vivienda_id' => 'vivienda',
            'medio_entrada_id' => 'medio de entrada',
        ];
    }
}
