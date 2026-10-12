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

    public function test_face_writeback_sql_requires_trusted_visible_ft_link(): void
    {
        $sql = app(ExifWritebackService::class)->faceWritebackEligibilitySql('frf', 'fip');

        $this->assertStringContainsString('frf.genealogy_person_id IS NOT NULL', $sql);
        $this->assertStringContainsString('COALESCE(frf.hidden, 0) = 0', $sql);
        $this->assertStringContainsString('COALESCE(frf.verified, 0) = 1', $sql);
        $this->assertStringContainsString('fip.status IN', $sql);
        $this->assertStringContainsString('export_variant_supported', $sql);
    }

    public function test_face_writeback_preserves_existing_person_metadata_before_merge(): void
    {
        $source = file_get_contents(app_path('Services/ExifWritebackService.php'));

        $this->assertStringNotContainsString("'-XMP-iptcExt:PersonInImage='", $source);
        $this->assertStringNotContainsString("'-XMP-mwg-rs:all='", $source);
        $this->assertStringContainsString('facesForWriteback', $source);
    }

    public function test_location_writeback_uses_valid_iptc_country_tag(): void
    {
        $source = file_get_contents(app_path('Services/ExifWritebackService.php'));

        $this->assertStringContainsString('IPTC:Country-PrimaryLocationName', $source);
        $this->assertStringNotContainsString('IPTC:Country-PrimaryLocation=', $source);
    }

    public function test_write_all_skips_low_confidence_dates(): void
    {
        $source = file_get_contents(app_path('Services/ExifWritebackService.php'));

        $this->assertStringContainsString('(float) ($file->date_taken_confidence ?? 0) >= 0.3', $source);
    }

    public function test_scan_writeback_treats_unix_epoch_as_an_unknown_digitization_date(): void
    {
        $source = file_get_contents(app_path('Services/ExifWritebackService.php'));

        $this->assertStringContainsString("! str_starts_with(\$scanDate, '1970-01-01')", $source);
    }
}
