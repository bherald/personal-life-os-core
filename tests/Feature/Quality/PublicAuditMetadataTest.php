<?php

namespace Tests\Feature\Quality;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublicAuditMetadataTest extends TestCase
{
    public function test_risk_classification_is_not_a_secret_but_real_secret_assignments_still_hold(): void
    {
        $root = sys_get_temp_dir().'/plos-audit-fixture-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($root.'/scripts/guards');
        File::ensureDirectoryExists($root.'/config');
        File::copy(base_path('scripts/guards/public-release-audit.sh'), $root.'/scripts/guards/public-release-audit.sh');
        File::copy(base_path('LICENSE'), $root.'/LICENSE');
        File::ensureDirectoryExists($root.'/database/schema');
        foreach (['mysql-schema.sql', 'pgsql-schema.sql'] as $dump) {
            File::put($root.'/database/schema/'.$dump, '-- isolated audit fixture');
        }
        if (is_file(base_path('scripts/guards/public-audit-filter.py'))) {
            File::copy(base_path('scripts/guards/public-audit-filter.py'), $root.'/scripts/guards/public-audit-filter.py');
            File::put($root.'/scripts/guards/public-audit-reviews.json', json_encode(['version' => 1, 'reviews' => []]));
        }
        try {
            (new Process(['git', 'init', '-q', $root]))->mustRun();
            foreach ([null, 'api_key', 'secret_surface_risk'] as $credential) {
                $data = ['secret_surface_risk' => 'private_credentials'];
                if ($credential !== null) {
                    $data[$credential] = str_repeat('s', 24);
                }
                File::put($root.'/config/mcp.php', '<?php return '.var_export($data, true).';');
                (new Process(['git', 'add', '.'], $root))->mustRun();
                $audit = new Process(['bash', 'scripts/guards/public-release-audit.sh'], $root);
                $audit->setTimeout(30);
                $audit->run();
                $this->assertSame($credential !== null ? 1 : 0, $audit->getExitCode(), $audit->getOutput().$audit->getErrorOutput());
                if ($credential) {
                    $this->assertStringContainsString('Real secret assignments', $audit->getOutput());
                }
            }
        } finally {
            File::deleteDirectory($root);
        }
    }
}
