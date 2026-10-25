<?php

namespace Tests\Feature\Uploads;

use App\Contracts\ReportsStorageQuota;
use App\Models\File;
use App\Models\StorageSpace;
use App\Services\Uploads\LocalUploadStorage;
use App\Services\Uploads\StorageUsageReporter;
use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The reporter sums the application's own file sizes and, only when asked,
 * probes the provider. Provider quotas are optional, which is why the contract
 * is separate from UploadStorage.
 */
class StorageUsageReporterTest extends TestCase
{
    use RefreshDatabase;

    private function space(?array $config = null): StorageSpace
    {
        $space = StorageSpace::create([
            'type' => StorageSpace::TYPE_PERSONAL,
            'name' => 'Usage Space',
            'storage_driver' => 'dropbox',
        ]);

        if ($config !== null) {
            $space->setStorageConfig($config);
            $space->save();
        }

        return $space;
    }

    private function addFiles(StorageSpace $space, int ...$sizes): void
    {
        foreach ($sizes as $size) {
            File::create([
                'storage_space_id' => $space->id,
                'name' => 'file-'.$size.'.bin',
                'size' => $size,
            ]);
        }
    }

    private function storageReporting(?array $usage): LocalUploadStorage
    {
        return new class($usage) extends LocalUploadStorage implements ReportsStorageQuota
        {
            public function __construct(private ?array $usage)
            {
                parent::__construct('edms');
            }

            public function healthCheck(): bool
            {
                return true;
            }

            public function spaceUsage(): ?array
            {
                return $this->usage;
            }
        };
    }

    public function test_it_uses_a_provider_quota_when_the_backend_reports_one(): void
    {
        Storage::fake('edms');

        $space = $this->space();
        $this->addFiles($space, 200, 100);

        $factory = Mockery::mock(UploadStorageFactory::class);
        $factory->shouldReceive('makeForSpace')
            ->once()
            ->with($space)
            ->andReturn($this->storageReporting([
                'used_bytes' => 12,
                'allocated_bytes' => 1000,
            ]));

        $usage = (new StorageUsageReporter($factory))->forSpace($space, true);

        $this->assertSame(2, $usage['files']);
        $this->assertSame(300, $usage['used_bytes']);
        $this->assertSame(1000, $usage['quota_bytes']);
        $this->assertSame(12, $usage['provider_used_bytes']);
        $this->assertSame(1000, $usage['provider_quota_bytes']);
        $this->assertSame(30.0, $usage['percent']);
        $this->assertTrue($usage['reachable']);
    }

    public function test_a_configured_quota_wins_over_the_provider_allocation(): void
    {
        Storage::fake('edms');

        $space = $this->space(['quota_bytes' => 500]);
        $this->addFiles($space, 300);

        $factory = Mockery::mock(UploadStorageFactory::class);
        $factory->shouldReceive('makeForSpace')
            ->once()
            ->andReturn($this->storageReporting([
                'used_bytes' => 12,
                'allocated_bytes' => 1000,
            ]));

        $usage = (new StorageUsageReporter($factory))->forSpace($space, true);

        $this->assertSame(500, $usage['quota_bytes']);
        $this->assertSame(60.0, $usage['percent']);
    }

    public function test_an_unconfigured_space_probes_the_global_driver_when_asked(): void
    {
        Storage::fake('edms');

        $space = StorageSpace::create([
            'type' => StorageSpace::TYPE_PERSONAL,
            'name' => 'Global Space',
        ]);

        $factory = Mockery::mock(UploadStorageFactory::class);
        $factory->shouldReceive('makeForSpace')
            ->once()
            ->with($space)
            ->andReturn($this->storageReporting([
                'used_bytes' => 12,
                'allocated_bytes' => 1000,
            ]));

        $usage = (new StorageUsageReporter($factory))->forSpace($space, true);

        $this->assertTrue($usage['reachable']);
        $this->assertSame(1000, $usage['quota_bytes']);
    }

    public function test_the_provider_is_not_contacted_unless_a_probe_is_requested(): void
    {
        $space = $this->space();
        $this->addFiles($space, 300);

        $factory = Mockery::mock(UploadStorageFactory::class);
        $factory->shouldNotReceive('makeForSpace');

        $usage = (new StorageUsageReporter($factory))->forSpace($space);

        $this->assertNull($usage['reachable']);
        $this->assertNull($usage['provider_used_bytes']);
        $this->assertNull($usage['quota_bytes']);
        $this->assertNull($usage['percent']);
    }

    public function test_a_backend_that_does_not_report_usage_still_produces_a_total(): void
    {
        Storage::fake('edms');

        $space = $this->space();
        $this->addFiles($space, 300);

        $factory = Mockery::mock(UploadStorageFactory::class);
        $factory->shouldReceive('makeForSpace')
            ->once()
            ->andReturn(new LocalUploadStorage('edms'));

        $usage = (new StorageUsageReporter($factory))->forSpace($space, true);

        $this->assertSame(300, $usage['used_bytes']);
        $this->assertTrue($usage['reachable']);
        $this->assertNull($usage['provider_used_bytes']);
        $this->assertNull($usage['provider_quota_bytes']);
    }
}
