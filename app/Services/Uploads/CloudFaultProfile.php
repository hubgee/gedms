<?php

namespace App\Services\Uploads;

/**
 * A description of the network conditions to simulate against a storage
 * backend.
 *
 * The profile is deliberately data, not code: the same set of knobs describes
 * "a phone on a bad 3G connection", "a cloud provider having a bad afternoon"
 * and "a bucket that is full", so one harness can drive all of them.
 *
 * Everything is off by default. A profile with no latency, no jitter, no
 * throttling and no failures behaves exactly like the real backend.
 */
final class CloudFaultProfile
{
    public function __construct(
        /** Added to every storage operation, in milliseconds. */
        public readonly int $latencyMs = 0,

        /** Random extra delay added to every operation, in milliseconds. */
        public readonly int $jitterMs = 0,

        /** Throughput ceiling in bytes per second. Null means unlimited. */
        public readonly ?int $bandwidthBytesPerSecond = null,

        /** Probability (0.0 - 1.0) that an operation fails transiently. */
        public readonly float $failureRate = 0.0,

        /**
         * Truncate a transfer after this many bytes, so the backend stores a
         * short chunk. Null disables the fault.
         */
        public readonly ?int $dropAfterBytes = null,

        /** The provider reports that the quota is exhausted. */
        public readonly bool $quotaExceeded = false,

        /**
         * Number of reads that still report "not there yet" after an object
         * was written. Models a provider that is not read-after-write
         * consistent.
         */
        public readonly int $eventualConsistencyReads = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Build a profile from a plain configuration array.
     */
    public static function fromArray(array $config): self
    {
        $bandwidth = $config['bandwidth_bps'] ?? null;

        return new self(
            latencyMs: max(0, (int) ($config['latency_ms'] ?? 0)),
            jitterMs: max(0, (int) ($config['jitter_ms'] ?? 0)),
            bandwidthBytesPerSecond: $bandwidth === null ? null : max(1, (int) $bandwidth),
            failureRate: min(1.0, max(0.0, (float) ($config['failure_rate'] ?? 0.0))),
            dropAfterBytes: ($config['drop_after_bytes'] ?? null) === null
                ? null
                : max(1, (int) $config['drop_after_bytes']),
            quotaExceeded: (bool) ($config['quota_exceeded'] ?? false),
            eventualConsistencyReads: max(0, (int) ($config['eventual_consistency_reads'] ?? 0)),
        );
    }

    public function toArray(): array
    {
        return [
            'latency_ms' => $this->latencyMs,
            'jitter_ms' => $this->jitterMs,
            'bandwidth_bps' => $this->bandwidthBytesPerSecond,
            'failure_rate' => $this->failureRate,
            'drop_after_bytes' => $this->dropAfterBytes,
            'quota_exceeded' => $this->quotaExceeded,
            'eventual_consistency_reads' => $this->eventualConsistencyReads,
        ];
    }

    /**
     * Whether this profile would change anything at all.
     */
    public function isActive(): bool
    {
        return $this->latencyMs > 0
            || $this->jitterMs > 0
            || $this->bandwidthBytesPerSecond !== null
            || $this->failureRate > 0.0
            || $this->dropAfterBytes !== null
            || $this->quotaExceeded
            || $this->eventualConsistencyReads > 0;
    }

    /**
     * Named profiles that cover the situations worth rehearsing.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            'clean' => [],

            // A phone on a congested mobile link: high latency, thin pipe.
            'mobile-3g' => [
                'latency_ms' => 300,
                'jitter_ms' => 200,
                'bandwidth_bps' => 400000,
            ],

            // A long distance link: slow but reliable.
            'satellite' => [
                'latency_ms' => 1200,
                'jitter_ms' => 300,
                'bandwidth_bps' => 2000000,
            ],

            // The provider is flaky: some calls fail, the rest are fine.
            'flaky' => [
                'latency_ms' => 50,
                'failure_rate' => 0.2,
            ],

            // The connection dies in the middle of a transfer.
            'drop-mid-transfer' => [
                'latency_ms' => 20,
                'drop_after_bytes' => 1048576,
            ],

            // The bucket is full.
            'quota-exhausted' => [
                'quota_exceeded' => true,
            ],

            // The provider accepted the write but cannot see it yet.
            'eventually-consistent' => [
                'latency_ms' => 20,
                'eventual_consistency_reads' => 2,
            ],
        ];
    }
}
