<?php

namespace App\Support;

use Throwable;

/**
 * A last line of defense for contexts that enter logs or immutable audit metadata.
 * Request bodies and headers are deliberately never supplied to operational logging.
 */
final class SensitiveData
{
    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'authorization',
        'cookie',
        'csrf',
        'xsrf',
        'token',
        'secret',
        'credential',
        'signature',
        'aws_access_key',
        'aws_secret',
    ];

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;
            $redacted[$key] = self::isSensitiveKey($key) ? '[REDACTED]' : self::redactValue($value);
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, str_replace('_', '', $fragment))) {
                return true;
            }
        }

        return false;
    }

    private static function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::redact($value);
        }

        // Exception messages and arbitrary objects can embed SQL bindings, source
        // rows, paths, or credentials. Keep the useful type but never serialize them.
        if ($value instanceof Throwable) {
            return ['exception_class' => $value::class];
        }

        if (is_object($value)) {
            return ['object_class' => $value::class];
        }

        return $value;
    }
}
