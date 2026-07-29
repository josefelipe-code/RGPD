<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Devuelve las reglas de validación usadas por los perfiles de usuario.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    /** Reúne las reglas de nombre y correo usadas por la página de perfil. */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Devuelve las reglas de validación usadas para nombres de usuario.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    /** Define las reglas reutilizables para nombres de usuario. */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Devuelve las reglas de validación usadas para correos de usuario.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    /** Define las reglas de correo incluyendo la excepción del usuario actual. */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
