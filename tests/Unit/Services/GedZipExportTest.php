<?php

namespace Tests\Unit\Services;

use App\Services\Genealogy\GedcomExportService;
use App\Services\Genealogy\PrivacyService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Tests for N76: GEDZip (.gdz) Export
 *
 * Validates the GEDZip export method exists and follows
 * GEDCOM 7.0 spec for portable bundled export.
 */
class GedZipExportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_export_method_exists(): void
    {
        $source = file_get_contents(app_path('Services/Genealogy/GedcomExportService.php'));

        $this->assertStringContainsString(
            'function exportToGedZip',
            $source,
            'GedcomExportService must have exportToGedZip method'
        );
    }

    public function test_creates_zip_with_gedcom_and_media(): void
    {
        $source = file_get_contents(app_path('Services/Genealogy/GedcomExportService.php'));

        // Must use ZipArchive
        $this->assertStringContainsString('ZipArchive', $source);
        // Must add GEDCOM content
        $this->assertStringContainsString('addFromString', $source);
        // Must add media files
        $this->assertStringContainsString('addFile', $source);
        // Must produce .gdz extension
        $this->assertStringContainsString('.gdz', $source);
    }

    public function test_queries_media_from_correct_table(): void
    {
        $source = file_get_contents(app_path('Services/Genealogy/GedcomExportService.php'));

        $this->assertStringContainsString(
            'genealogy_media',
            $source,
            'Must query genealogy_media for tree media files'
        );
        $this->assertStringContainsString(
            'tree_id = ?',
            $source,
            'Must filter media by tree_id'
        );
    }

    public function test_gedzip_respects_include_media_false_for_binary_bundle(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for GEDZip export regression coverage.');
        }

        DB::shouldReceive('selectOne')
            ->once()
            ->with('SELECT name FROM genealogy_trees WHERE id = ?', [99])
            ->andReturn((object) ['name' => 'No Media Tree']);
        DB::shouldReceive('select')->never();

        /** @var GedcomExportService&\Mockery\MockInterface $service */
        $service = Mockery::mock(GedcomExportService::class, [app(PrivacyService::class)])
            ->makePartial();
        $service->shouldReceive('exportTree')
            ->once()
            ->with(99, 123, ['include_media' => false])
            ->andReturn("0 HEAD\r\n0 TRLR\r\n");

        $path = null;
        try {
            $path = $service->exportToGedZip(99, 123, ['include_media' => false]);

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true, 'Generated GEDZip should be readable.');
            $this->assertNotFalse($zip->locateName('No_Media_Tree.ged'));

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $this->assertIsString($name);
                $this->assertStringStartsNotWith('media/', $name);
            }

            $zip->close();
        } finally {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_api_route_exists(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString(
            'export/gedzip',
            $routes,
            'API route for GEDZip export must exist'
        );
    }

    public function test_controller_method_exists(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/GenealogyController.php'));

        $this->assertStringContainsString(
            'function exportGedZip',
            $source,
            'Controller must have exportGedZip method'
        );
        $this->assertStringContainsString(
            'deleteFileAfterSend',
            $source,
            'Must clean up temp file after download'
        );
    }
}
