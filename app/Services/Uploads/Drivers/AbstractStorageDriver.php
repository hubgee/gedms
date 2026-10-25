<?php

namespace App\Services\Uploads\Drivers;

use App\Contracts\CloudOAuthProvider;
use App\Contracts\CloudStorageDriver;

/**
 * Shared plumbing for the driver descriptors.
 */
abstract class AbstractStorageDriver implements CloudStorageDriver
{
    public function oauthProvider(): ?CloudOAuthProvider
    {
        return null;
    }

    public function validate(array $config): array
    {
        $errors = [];

        foreach ($this->fields() as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }

            $value = $config[$field['key']] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $errors[] = $field['label'].' is required.';
            }
        }

        return $errors;
    }

    /**
     * A configuration value, falling back to a default when it is absent or
     * blank.
     */
    protected function value(array $config, string $key, mixed $default = null): mixed
    {
        $value = $config[$key] ?? null;

        if (is_string($value)) {
            return trim($value) === '' ? $default : trim($value);
        }

        return $value ?? $default;
    }

    /**
     * The first non-blank string among the arguments.
     */
    protected function firstFilled(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
