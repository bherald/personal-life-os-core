<?php

use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// This entrypoint is reached through the operator's local account or existing
// SSH authentication. Arguments/approvals never appear on the command line.
ob_start();
try {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $result = (new CliFrameworkServiceWorker)->execute($request);
    $diagnostics = ob_get_clean();
    fwrite(STDOUT, json_encode(['schema' => 'plos.cli.framework-result.v1', 'result' => $result,
        'diagnostics_base64' => base64_encode($diagnostics)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} catch (Throwable) {
    $diagnostics = ob_get_clean();
    // Complete incidental output is private evidence, never a substitute result.
    fwrite(STDOUT, json_encode(['schema' => 'plos.cli.framework-result.v1',
        'diagnostics_base64' => base64_encode($diagnostics)], JSON_THROW_ON_ERROR));
    fwrite(STDERR, "Selected PLOS service did not return a verified result. Check its installation, current tables and selected target before retrying.\n");
    exit(1);
}
