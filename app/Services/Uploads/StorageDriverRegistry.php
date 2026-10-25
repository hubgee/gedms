<?php

namespace App\Services\Uploads;

use App\Contracts\CloudStorageDriver;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * The set of storage providers the application knows about.
 *
 * The registry is populated from config/uploads.php ("drivers"). Adding a
 * provider is therefore a configuration change plus one class: no controller,
 * factory or Blade template has to be edited.
 */
class StorageDriverRegistry
{
    /** @var array<string, CloudStorageDriver>|null */
    protected ?array $resolved = null;

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * @return array<string, CloudStorageDriver>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = [];

        /** @var array<string, class-string<CloudStorageDriver>> $drivers */
        $drivers = (array) config('uploads.drivers', []);

        foreach ($drivers as $key => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $driver = $this->app->make($class);

            if ($driver instanceof CloudStorageDriver) {
                $this->resolved[$key] = $driver;
            }
        }

        return $this->resolved;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key): CloudStorageDriver
    {
        $driver = $this->all()[$key] ?? null;

        if (! $driver instanceof CloudStorageDriver) {
            throw new RuntimeException(
                'Unknown upload storage driver ["'.$key.'"]. Configure it under uploads.drivers.'
            );
        }

        return $driver;
    }

    /**
     * Keys suitable for a CSRF-protected route constraint.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
