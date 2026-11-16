<?php

namespace Patronato\Presentacion\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reporte de una transferencia desde el portal del residente (HU-25)
 *
 * Usa las mismas reglas que el registro del tesorero, salvo la cuenta receptora,
 * que el vecino puede no conocer. Que la vivienda sea suya lo revisa
 * TransferenciaService::reportar con el vínculo vigente.
 */
class ReportarTransferenciaRequest extends RegistrarTransferenciaRequest
{
    /**
     * Reglas de validación
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'cuenta_receptora' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
