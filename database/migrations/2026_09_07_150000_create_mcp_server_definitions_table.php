<?php

use App\Services\UnifiedCli\CliMcpServerDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve/validate metadata before creating anything. Environment values
        // are excluded; the resulting rows contain local binding references only.
        $definitions = (new CliMcpServerDefinitions)->fromConfiguration(config('mcp.servers', []), base_path());
        Schema::create('mcp_server_definitions', function (Blueprint $table): void {
            $table->string('server_name', 191)->primary();
            $table->boolean('enabled')->default(false);
            $table->json('definition');
            $table->string('source', 191);
            $table->timestamps();
        });
        foreach ($definitions as $definition) {
            $definition['definition'] = json_encode($definition['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            DB::table('mcp_server_definitions')->insert($definition + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_server_definitions');
    }
};
