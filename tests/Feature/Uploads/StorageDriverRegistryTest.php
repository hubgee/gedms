<?php

namespace Tests\Feature\Uploads;

use App\Contracts\CloudStorageDriver;
use App\Services\Uploads\StorageDriverRegistry;
use RuntimeException;
use Tests\TestCase;

/**
 * The registry is the seam that keeps provider-specific code out of the
 * controller and views. These tests pin the contract it offers to those
 * callers.
 */
class StorageDriverRegistryTest extends TestCase
{
    public function test_it_resolves_every_configured_driver(): void
    {
        $registry = $this->app->make(StorageDriverRegistry::class);

        $this->assertSame(
            ['local', 's3', 'dropbox', 'google', 'onedrive'],
            $registry->keys(),
        );
    }

    public function test_every_driver_uses_its_registry_key_as_its_own_key(): void
    {
        $registry = $this->app->make(StorageDriverRegistry::class);

        foreach ($registry->all() as $key => $driver) {
            $this->assertInstanceOf(CloudStorageDriver::class, $driver);
            $this->assertSame($key, $driver->key());
            $this->assertNotSame('', $driver->label());
            $this->assertNotSame('', $driver->description());
        }
    }

    public function test_every_field_describes_the_minimum_the_ui_needs(): void
    {
        $registry = $this->app->make(StorageDriverRegistry::class);

        foreach ($registry->all() as $key => $driver) {
            foreach ($driver->fields() as $field) {
                $this->assertArrayHasKey('key', $field, "The [{$key}] driver has a field without a key.");
                $this->assertArrayHasKey('label', $field, "The [{$key}] driver has a field without a label.");
                $this->assertArrayHasKey('type', $field, "The [{$key}] driver has a field without a type.");
                $this->assertContains(
                    $field['type'],
                    ['text', 'password', 'number', 'checkbox', 'select', 'url'],
                    "The [{$key}] driver uses an unknown field type.",
                );
            }
        }
    }

    public function test_only_the_oauth_drivers_expose_an_oauth_provider(): void
    {
        $registry = $this->app->make(StorageDriverRegistry::class);

        $this->assertNull($registry->get('local')->oauthProvider());
        $this->assertNull($registry->get('s3')->oauthProvider());

        foreach (['dropbox', 'google', 'onedrive'] as $key) {
            $this->assertNotNull($registry->get($key)->oauthProvider());
        }
    }

    public function test_get_throws_for_an_unknown_driver(): void
    {
        $registry = $this->app->make(StorageDriverRegistry::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown upload storage driver ["missing"]');

        $registry->get('missing');
    }

    public function test_a_driver_added_to_configuration_appears_without_code_changes(): void
    {
        config(['uploads.drivers' => ['local' => \App\Services\Uploads\Drivers\LocalDriver::class]]);

        $registry = new StorageDriverRegistry($this->app);

        $this->assertSame(['local'], $registry->keys());
        $this->assertTrue($registry->has('local'));
        $this->assertFalse($registry->has('s3'));
    }
}
