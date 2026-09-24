<?php

namespace App\Agent;

/**
 * Makes agent run data safe to store and show: secrets redacted, encrypted blobs dropped, frame images replaced.
 */
class AuditSanitizer
{
    public const FramePlaceholder = '[frame stored on device]';

    public static function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_starts_with($value, 'data:image/') ? self::FramePlaceholder : $value;
        }

        if (! is_array($value)) {
            return is_object($value) ? self::sanitize(json_decode((string) json_encode($value), true)) : $value;
        }

        $sanitized = [];

        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && in_array($key, ['encrypted_content', 'rawContent'], true)) {
                continue;
            }

            $sanitized[$key] = is_string($key) && preg_match('/api[_-]?key|authorization/i', $key) === 1
                ? '[redacted]'
                : self::sanitize($nestedValue);
        }

        return $sanitized;
    }
}
