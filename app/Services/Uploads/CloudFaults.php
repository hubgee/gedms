<?php

namespace App\Services\Uploads;

/**
 * The fault profile that is currently in force.
 *
 * Two sources, in order of precedence:
 *
 *  1. a profile pushed at runtime (the CLI harness pushes one for the length
 *     of a run);
 *  2. the "uploads.cloud_fault" configuration, which is what a running web
 *     server or queue worker reads.
 *
 * The configuration is the mechanism that works across processes: the HTTP
 * request and the queue worker are different processes, so a profile set in
 * one is not visible in the other unless it is in the environment.
 */
final class CloudFaults
{
    protected static ?CloudFaultProfile $profile = null;

    /**
     * The profile in force right now.
     */
    public static function current(): CloudFaultProfile
    {
        if (self::$profile !== null) {
            return self::$profile;
        }

        return self::fromConfig();
    }

    /**
     * The profile described by the configuration.
     */
    public static function fromConfig(): CloudFaultProfile
    {
        $config = config('uploads.cloud_fault');

        if (! is_array($config)) {
            return CloudFaultProfile::none();
        }

        return CloudFaultProfile::fromArray($config);
    }

    /**
     * Force a profile for the rest of this process.
     */
    public static function push(CloudFaultProfile $profile): void
    {
        self::$profile = $profile;
    }

    /**
     * Drop the runtime profile and fall back to the configuration.
     */
    public static function clear(): void
    {
        self::$profile = null;
    }

    /**
     * Build a profile from a preset name.
     */
    public static function preset(string $name): CloudFaultProfile
    {
        $presets = CloudFaultProfile::presets();

        if (! isset($presets[$name])) {
            throw new \InvalidArgumentException(
                'Unknown cloud fault preset ["'.$name.'"]. Known presets: '.implode(', ', array_keys($presets)).'.'
            );
        }

        return CloudFaultProfile::fromArray($presets[$name]);
    }
}
