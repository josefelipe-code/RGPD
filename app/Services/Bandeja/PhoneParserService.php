<?php

namespace App\Services\Bandeja;

/**
 * Parse and normalize phone numbers from email body text.
 *
 * Returns E.164 format or null if no phone found.
 * This is a minimal skeleton for PR1 — full E.164 normalization
 * will be implemented in PR2.
 */
class PhoneParserService
{
    /**
     * Extract and normalize a phone number from text.
     *
     * @param  string  $text  The email body text to scan
     * @return string|null E.164 formatted phone or null
     */
    /**
     * Extrae y normaliza un teléfono del texto consumido por la clasificación
     * de mensajes y la creación de expedientes.
     */
    public function parse(string $text): ?string
    {
        // Match international format: +XX XXX XXX XXX or +XXXXXXXXXXX
        if (preg_match('/\+[\d\s\-]{7,15}/', $text, $matches)) {
            $phone = preg_replace('/[\s\-]/', '', $matches[0]);

            // Basic validation: must be 8-15 digits after +
            if (preg_match('/^\+\d{8,15}$/', $phone)) {
                return $phone;
            }
        }

        // Match Spanish format: 6XX XXX XXX or 9XX XXX XXX
        if (preg_match('/\b[69]\d{2}[\s\-]?\d{3}[\s\-]?\d{3}\b/', $text, $matches)) {
            $phone = preg_replace('/[\s\-]/', '', $matches[0]);

            return '+34'.$phone;
        }

        return null;
    }
}
