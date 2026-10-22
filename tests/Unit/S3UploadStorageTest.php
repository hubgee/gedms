<?php

namespace Tests\Unit;

use App\Services\Uploads\S3UploadStorage;
use PHPUnit\Framework\TestCase;

/**
 * The multipart-copy decision is the only part of the S3 backend that can be
 * tested without a bucket: it is pure arithmetic over object sizes.
 *
 * The sizes are supplied by an anonymous subclass, so no disk, no client and
 * no network is involved.
 */
class S3UploadStorageTest extends TestCase
{
    private const MIB = 1048576;

    private const GIB = 1073741824;

    /**
     * Build a storage whose objects have the given sizes.
     *
     * @param  array<string, int|null>  $sizes
     */
    private function storageWithSizes(array $sizes): S3UploadStorage
    {
        return new class($sizes) extends S3UploadStorage
        {
            /** @param array<string, int|null> $sizes */
            public function __construct(private array $sizes)
            {
                parent::__construct('edms');
            }

            public function size(string $path): ?int
            {
                return $this->sizes[$path] ?? null;
            }

            /** @param array<int, string> $paths */
            public function canCopy(array $paths): bool
            {
                return $this->canMultipartCopy($paths);
            }
        };
    }

    public function test_parts_of_five_mib_or_more_can_be_copied(): void
    {
        $storage = $this->storageWithSizes([
            'chunk-0' => 5 * self::MIB,
            'chunk-1' => 5 * self::MIB,
        ]);

        $this->assertTrue($storage->canCopy(['chunk-0', 'chunk-1']));
    }

    public function test_the_last_part_may_be_smaller_than_five_mib(): void
    {
        $storage = $this->storageWithSizes([
            'chunk-0' => 8 * self::MIB,
            'chunk-1' => 8 * self::MIB,
            'chunk-2' => 4,
        ]);

        $this->assertTrue($storage->canCopy(['chunk-0', 'chunk-1', 'chunk-2']));
    }

    public function test_a_non_final_part_below_five_mib_falls_back_to_streaming(): void
    {
        // This is the case the test suite itself hits: 8 byte chunks can never
        // be copied server-side.
        $storage = $this->storageWithSizes([
            'chunk-0' => 8,
            'chunk-1' => 8,
        ]);

        $this->assertFalse($storage->canCopy(['chunk-0', 'chunk-1']));
    }

    public function test_a_small_part_in_the_middle_forces_the_streaming_fallback(): void
    {
        $storage = $this->storageWithSizes([
            'chunk-0' => 8 * self::MIB,
            'chunk-1' => self::MIB,
            'chunk-2' => 8 * self::MIB,
        ]);

        $this->assertFalse($storage->canCopy(['chunk-0', 'chunk-1', 'chunk-2']));
    }

    public function test_a_zero_byte_non_final_part_is_not_copied(): void
    {
        $storage = $this->storageWithSizes([
            'chunk-0' => 0,
            'chunk-1' => 8 * self::MIB,
        ]);

        $this->assertFalse($storage->canCopy(['chunk-0', 'chunk-1']));
    }

    public function test_a_part_whose_size_cannot_be_read_blocks_the_copy(): void
    {
        // A read-after-write lag makes size() answer null. Copying would then
        // risk publishing a short file, so the streaming path is used instead.
        $storage = $this->storageWithSizes([
            'chunk-0' => 8 * self::MIB,
            'chunk-1' => null,
        ]);

        $this->assertFalse($storage->canCopy(['chunk-0', 'chunk-1']));
    }

    public function test_the_ten_thousand_part_limit_is_respected(): void
    {
        $sizes = [];

        for ($index = 0; $index <= 10000; $index++) {
            $sizes['chunk-'.$index] = 5 * self::MIB;
        }

        $storage = $this->storageWithSizes($sizes);

        $atLimit = array_slice(array_keys($sizes), 0, 10000);

        $this->assertTrue($storage->canCopy($atLimit));

        $overLimit = array_keys($sizes);

        $this->assertFalse($storage->canCopy($overLimit));
    }

    public function test_a_part_above_the_five_gib_copy_limit_falls_back(): void
    {
        $storage = $this->storageWithSizes([
            'chunk-0' => 5 * self::GIB + 1,
            'chunk-1' => self::MIB,
        ]);

        $this->assertFalse($storage->canCopy(['chunk-0', 'chunk-1']));
    }

    public function test_a_part_of_exactly_five_gib_can_be_copied(): void
    {
        $storage = $this->storageWithSizes([
            'chunk-0' => 5 * self::GIB,
            'chunk-1' => self::MIB,
        ]);

        $this->assertTrue($storage->canCopy(['chunk-0', 'chunk-1']));
    }
}
