<?php

namespace Tests\Unit\Services;

use App\Services\ExifWritebackService;
use App\Services\FaceRegionService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MetadataWritebackSafetyTest extends TestCase
{
    public function test_metadata_writeback_defaults_to_disabled(): void
    {
        $this->assertFalse(config('metadata_writeback.enabled'));
        $this->assertFalse(config('metadata_writeback.in_place_enabled'));
    }

    public function test_exif_writeback_refuses_when_global_gate_is_disabled(): void
    {
        config()->set('metadata_writeback.enabled', false);
        config()->set('metadata_writeback.in_place_enabled', false);
        Process::fake();

        $path = tempnam(sys_get_temp_dir(), 'plos_writeback_');
        file_put_contents($path, 'test');

        try {
            $result = app(ExifWritebackService::class)->writeTags($path, ['family']);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['success']);
        $this->assertSame(-3, $result['code']);
        $this->assertStringContainsString('Metadata writeback disabled', $result['error']);
        Process::assertNothingRan();
    }

    public function test_face_region_writeback_refuses_when_global_gate_is_disabled(): void
    {
        config()->set('metadata_writeback.enabled', false);
        config()->set('metadata_writeback.in_place_enabled', false);
        Process::fake();

        $path = tempnam(sys_get_temp_dir(), 'plos_face_regions_');
        file_put_contents($path, 'test');

        try {
            $result = app(FaceRegionService::class)->writeFaceRegions($path, [
                ['name' => 'Test Person', 'x' => 0.5, 'y' => 0.5, 'w' => 0.1, 'h' => 0.1],
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result);
        Process::assertNothingRan();
    }
}
