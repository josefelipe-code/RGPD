<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Validates and normalizes mail encryption values.
 *
 * Accepted: 'ssl', 'tls', 'starttls', 'none', null, ''
 * Normalized on read: 'starttls' → 'tls', 'none'/''/null → null
 * Stored as-is: 'ssl', 'tls', 'none' (no migration needed for existing NOT NULL columns).
 */
class EncryptionCast implements CastsAttributes
{
    private const ALLOWED = ['ssl', 'tls', 'starttls', 'none', null, ''];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '' || $value === 'none') {
            return null;
        }

        $normalized = strtolower((string) $value);

        if (! in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidArgumentException(
                "Invalid encryption value [{$value}] for [{$key}]. Allowed: ssl, tls, starttls, none."
            );
        }

        return $normalized === 'starttls' ? 'tls' : $normalized;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '' || $value === 'none') {
            return 'none';
        }

        $normalized = strtolower((string) $value);

        if (! in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidArgumentException(
                "Invalid encryption value [{$value}] for [{$key}]. Allowed: ssl, tls, starttls, none."
            );
        }

        return $normalized === 'starttls' ? 'tls' : $normalized;
    }
}
