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
    private const EXPORT_ROOT = '/Library/Genealogy/FT4';

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

    public function test_lists_non_self_contained_media_paths_for_export_readiness(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $bindings): bool {
                return str_contains($sql, 'FROM genealogy_media')
                    && str_contains($sql, "nextcloud_path LIKE 'Z:%'")
                    && str_contains($sql, "nextcloud_path LIKE 'http://%'")
                    && str_contains($sql, 'nextcloud_path IS NULL')
                    && str_contains($sql, 'nextcloud_path NOT LIKE ?')
                    && ! str_contains($sql, "original_path LIKE 'Z:%'")
                    && $bindings === [4, self::EXPORT_ROOT.'/%', 10];
            })
            ->andReturn([
                (object) [
                    'media_id' => 10,
                    'title' => 'External record',
                    'nextcloud_path' => 'https://example.test/record.jpg',
                    'original_path' => null,
                ],
            ]);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn ($sql, $bindings): bool => str_contains($sql, 'COUNT(*) AS count')
                && str_contains($sql, 'FROM genealogy_media')
                && $bindings === [4, self::EXPORT_ROOT.'/%'])
            ->andReturn((object) ['count' => 1]);

        $service = new GedcomExportService(app(PrivacyService::class));
        $result = $service->listNonSelfContainedMediaPaths(4, self::EXPORT_ROOT, 10);

        $this->assertSame(4, $result['tree_id']);
        $this->assertSame(self::EXPORT_ROOT, $result['root']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(10, $result['rows'][0]->media_id);
        $this->assertSame('external_url', $result['rows'][0]->path_issue);
    }

    public function test_lists_missing_media_files_for_export_readiness(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $bindings): bool {
                return str_contains($sql, 'FROM genealogy_media')
                    && str_contains($sql, 'COALESCE(file_exists, 0) <> 1')
                    && $bindings === [4, 10];
            })
            ->andReturn([
                (object) [
                    'media_id' => 20,
                    'title' => 'Missing record',
                    'file_exists' => 0,
                ],
            ]);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn ($sql, $bindings): bool => str_contains($sql, 'COUNT(*) AS count')
                && str_contains($sql, 'FROM genealogy_media')
                && str_contains($sql, 'COALESCE(file_exists, 0) <> 1')
                && $bindings === [4])
            ->andReturn((object) ['count' => 1]);

        $service = new GedcomExportService(app(PrivacyService::class));
        $result = $service->listMissingMediaFilesForExport(4, 10);

        $this->assertSame(4, $result['tree_id']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(20, $result['rows'][0]->media_id);
    }

    public function test_export_path_policy_defines_self_contained_tree_rules(): void
    {
        $service = new GedcomExportService(app(PrivacyService::class));
        $policy = $service->exportPathPolicy(4, self::EXPORT_ROOT);

        $this->assertSame(self::EXPORT_ROOT, $policy['required_local_root']);
        $this->assertContains(self::EXPORT_ROOT.'/', $policy['allowed_media_path_prefixes']);
        $this->assertSame('GEDZip', $policy['portable_export']['format']);
        $this->assertTrue($policy['portable_export']['supporting_media_included_when_file_verified']);
    }

    public function test_export_readiness_helper_exists_for_gedzip_preflight(): void
    {
        $source = file_get_contents(app_path('Services/Genealogy/GedcomExportService.php'));

        $this->assertStringContainsString('function listNonSelfContainedMediaPaths', $source);
        $this->assertStringContainsString('function listMissingMediaFilesForExport', $source);
        $this->assertStringContainsString('function exportPathPolicy', $source);
        $this->assertStringContainsString('GenealogyTreeRootResolver::class', $source);
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

    public function test_gedzip_export_private_local_defaults_include_living(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for GEDZip export privacy coverage.');
        }

        DB::shouldReceive('selectOne')
            ->twice()
            ->withArgs(fn ($sql, $bindings): bool => str_contains($sql, 'FROM genealogy_trees')
                && $bindings === [22])
            ->andReturn((object) ['name' => 'Private Zip Tree']);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT p.*, t.living_years_threshold')
                    && str_contains($sql, 'FROM genealogy_persons p')
                    && str_contains($sql, 'tree_id = ?');
            })
            ->andReturn([
                (object) [
                    'id' => 10,
                    'given_name' => 'Living',
                    'surname' => 'Person',
                    'living' => 1,
                    'living_years_threshold' => 100,
                ],
            ]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT f.*')
                    && str_contains($sql, 'FROM genealogy_families f')
                    && str_contains($sql, 'tree_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT fc.family_id')
                    && str_contains($sql, 'FROM genealogy_children fc')
                    && str_contains($sql, 'WHERE fc.person_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT f.id')
                    && str_contains($sql, 'FROM genealogy_families f')
                    && str_contains($sql, 'WHERE f.husband_id = ? OR f.wife_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT c.*')
                    && str_contains($sql, 'FROM genealogy_citations c')
                    && str_contains($sql, 'WHERE c.person_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT * FROM genealogy_events')
                    && str_contains($sql, 'WHERE person_id = ?')
                    && str_contains($sql, 'ORDER BY event_date');
            })
            ->andReturn([]);

        $service = new GedcomExportService(app(PrivacyService::class));
        $path = $service->exportToGedZip(
            22,
            null,
            [
                'include_media' => false,
                'include_sources' => false,
                'privacy_context' => 'private_local',
            ]
        );

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $gedcom = (string) $zip->getFromName('Private_Zip_Tree.ged');
            $zip->close();

            $this->assertStringContainsString('0 @I10@ INDI', $gedcom);
            $this->assertStringContainsString('1 NAME Living /Person/', $gedcom);
            $this->assertStringNotContainsString('1 RESN privacy', $gedcom);
        } finally {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_gedzip_public_export_explicitly_controls_living_inclusion(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for GEDZip export privacy coverage.');
        }

        DB::shouldReceive('selectOne')
            ->times(4)
            ->withArgs(fn ($sql, $bindings): bool => str_contains($sql, 'FROM genealogy_trees')
                && $bindings === [33])
            ->andReturn((object) ['name' => 'Public Zip Tree']);

        DB::shouldReceive('select')
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT p.*, t.living_years_threshold')
                    && str_contains($sql, 'FROM genealogy_persons p')
                    && str_contains($sql, 'tree_id = ?');
            })
            ->andReturn([
                (object) [
                    'id' => 11,
                    'given_name' => 'Private',
                    'surname' => 'Export',
                    'living' => 1,
                    'living_years_threshold' => 100,
                ],
            ]);

        DB::shouldReceive('select')
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT f.*')
                    && str_contains($sql, 'FROM genealogy_families f')
                    && str_contains($sql, 'tree_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT fc.family_id')
                    && str_contains($sql, 'FROM genealogy_children fc')
                    && str_contains($sql, 'WHERE fc.person_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT f.id')
                    && str_contains($sql, 'FROM genealogy_families f')
                    && str_contains($sql, 'WHERE f.husband_id = ? OR f.wife_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT c.*')
                    && str_contains($sql, 'FROM genealogy_citations c')
                    && str_contains($sql, 'WHERE c.person_id = ?');
            })
            ->andReturn([]);

        DB::shouldReceive('select')
            ->withArgs(function ($sql): bool {
                return str_contains($sql, 'SELECT * FROM genealogy_events')
                    && str_contains($sql, 'WHERE person_id = ?')
                    && str_contains($sql, 'ORDER BY event_date');
            })
            ->andReturn([]);

        $service = new GedcomExportService(app(PrivacyService::class));

        $pathWithoutIncludeLiving = $service->exportToGedZip(
            33,
            null,
            [
                'include_media' => false,
                'include_sources' => false,
                'privacy_context' => 'public_export',
            ]
        );

        $pathWithIncludeLiving = $service->exportToGedZip(
            33,
            null,
            [
                'include_media' => false,
                'include_sources' => false,
                'privacy_context' => 'public_export',
                'include_living' => true,
            ]
        );

        $this->assertNotSame($pathWithoutIncludeLiving, $pathWithIncludeLiving);

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($pathWithoutIncludeLiving) === true);
            $redactedGedcom = (string) $zip->getFromName('Public_Zip_Tree.ged');
            $zip->close();

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($pathWithIncludeLiving) === true);
            $includedGedcom = (string) $zip->getFromName('Public_Zip_Tree.ged');
            $zip->close();

            $this->assertStringContainsString('1 RESN privacy', $redactedGedcom);
            $this->assertStringContainsString('1 NAME Private /Export/', $includedGedcom);
            $this->assertStringNotContainsString('1 RESN privacy', $includedGedcom);
        } finally {
            foreach ([$pathWithoutIncludeLiving, $pathWithIncludeLiving] as $path) {
                if (is_string($path) && file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_gedzip_uses_media_ids_to_avoid_duplicate_archive_names(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for GEDZip export media coverage.');
        }

        $root = sys_get_temp_dir().'/gedzip_media_'.uniqid();
        mkdir($root.'/one', 0777, true);
        mkdir($root.'/two', 0777, true);
        file_put_contents($root.'/one/same.jpg', 'first');
        file_put_contents($root.'/two/same.jpg', 'second');

        config()->set('services.nextcloud.data_path', $root);

        DB::shouldReceive('selectOne')
            ->twice()
            ->withArgs(fn ($sql, $bindings): bool => str_contains($sql, 'FROM genealogy_trees')
                && $bindings === [44])
            ->andReturn((object) ['name' => 'Duplicate Media Tree']);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($sql): bool => str_contains($sql, 'SELECT p.*, t.living_years_threshold')
                && str_contains($sql, 'FROM genealogy_persons p'))
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($sql): bool => str_contains($sql, 'SELECT f.*')
                && str_contains($sql, 'FROM genealogy_families f'))
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($sql): bool => str_contains($sql, 'SELECT * FROM genealogy_media')
                && str_contains($sql, 'ORDER BY id'))
            ->andReturn([
                (object) [
                    'id' => 101,
                    'nextcloud_path' => 'one/same.jpg',
                    'local_filename' => 'same.jpg',
                    'mime_type' => 'image/jpeg',
                    'title' => null,
                    'date_taken' => null,
                    'description' => null,
                ],
                (object) [
                    'id' => 102,
                    'nextcloud_path' => 'two/same.jpg',
                    'local_filename' => 'same.jpg',
                    'mime_type' => 'image/jpeg',
                    'title' => null,
                    'date_taken' => null,
                    'description' => null,
                ],
            ]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($sql): bool => str_contains($sql, 'SELECT gm.id, gm.nextcloud_path, gm.local_filename')
                && str_contains($sql, 'FROM genealogy_media gm'))
            ->andReturn([
                (object) ['id' => 101, 'nextcloud_path' => 'one/same.jpg', 'local_filename' => 'same.jpg'],
                (object) ['id' => 102, 'nextcloud_path' => 'two/same.jpg', 'local_filename' => 'same.jpg'],
            ]);

        $service = new GedcomExportService(app(PrivacyService::class));
        $path = $service->exportToGedZip(44, null, [
            'include_media' => true,
            'include_sources' => false,
        ]);

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $this->assertNotFalse($zip->locateName('media/M101-same.jpg'));
            $this->assertNotFalse($zip->locateName('media/M102-same.jpg'));
            $zip->close();
        } finally {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
            @unlink($root.'/one/same.jpg');
            @unlink($root.'/two/same.jpg');
            @rmdir($root.'/one');
            @rmdir($root.'/two');
            @rmdir($root);
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
