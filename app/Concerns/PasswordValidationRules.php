<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Devuelve las reglas de validación usadas para contraseñas.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    /** Define las reglas de una nueva contraseña usadas por las páginas de seguridad. */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Devuelve las reglas para comprobar la contraseña actual.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    /** Define la regla de comprobación de la contraseña actual. */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
