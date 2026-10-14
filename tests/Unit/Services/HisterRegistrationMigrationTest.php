<?php

namespace Tests\Unit\Services;

use App\Services\SkillLoaderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HisterRegistrationMigrationTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        if ($root = getenv('PLOS_HISTER_TEST_ROOT')) {
            app()->setBasePath($root);
            config(['hister' => require $root.'/config/hister.php']);
            config(['mcp.servers.hister' => (require $root.'/config/mcp.php')['servers']['hister']]);
        }
        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.hister_migration' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('hister_migration');

        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
            $table->string('data_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['section', 'config_key']);
        });
        Schema::create('agent_tool_registry', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('service_class');
            $table->string('method');
            $table->text('description');
            $table->text('parameters');
            $table->text('returns_description')->nullable();
            $table->text('permissions')->nullable();
            $table->string('risk_level');
            $table->string('category');
            $table->boolean('requires_confirmation');
            $table->boolean('enabled');
            $table->integer('max_calls_per_run')->nullable();
            $table->string('source')->nullable();
            $table->string('mcp_server')->nullable();
            $table->string('mcp_tool')->nullable();
            $table->text('notes')->nullable();
            $table->string('availability_status')->nullable();
            $table->string('privacy_class')->nullable();
            $table->boolean('allows_private_data')->default(false);
            $table->timestamps();
        });
        Schema::create('mcp_server_definitions', function (Blueprint $table): void {
            $table->string('server_name')->primary();
            $table->boolean('enabled');
            $table->text('definition');
            $table->string('source');
            $table->timestamps();
        });
        Schema::create('skill_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('skill_name');
            $table->string('version');
            $table->string('content_hash');
            $table->text('frontmatter');
            $table->text('body_text');
            $table->text('full_content');
            $table->string('change_summary')->nullable();
            $table->string('changed_by')->nullable();
            $table->boolean('is_active');
            $table->integer('tools_count');
            $table->integer('tool_phases_count');
            $table->text('permissions')->nullable();
            $table->timestamps();
            $table->unique(['skill_name', 'content_hash']);
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousConnection);
        DB::purge('hister_migration');
        parent::tearDown();
    }

    public function test_registration_is_idempotent_and_retains_operator_revocation(): void
    {
        $migration = require database_path('migrations/2026_09_27_220000_register_hister_reference_recall.php');
        $migration->up();
        $this->assertSame(3, DB::table('agent_tool_registry')->count());
        $this->assertSame(1, DB::table('mcp_server_definitions')->count());
        $this->assertSame(2, DB::table('skill_versions')->where('is_active', true)->count());
        $row = DB::table('mcp_server_definitions')->first();
        $definition = json_decode($row->definition, true);
        $this->assertSame('${PLOS_ROOT}/scripts/hister-mcp.mjs', $definition['args'][0]);
        $this->assertSame([], $definition['environment_bindings']);
        foreach (['offline-assistant', 'ai-ops'] as $name) {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot($name);
            foreach (['hister_search', 'hister_get_preview', 'hister_get_history'] as $tool) {
                $this->assertContains($tool, $skill['frontmatter']['tools']);
                if ($name === 'ai-ops') {
                    $this->assertContains($tool, $skill['frontmatter']['tool_phases']['assess']);
                }
            }
        }
        DB::table('agent_tool_registry')->update(['enabled' => false]);
        DB::table('mcp_server_definitions')->update(['enabled' => false]);
        $migration->up();
        $migration->down();
        $this->assertSame(3, DB::table('agent_tool_registry')->count());
        $this->assertSame(0, DB::table('agent_tool_registry')->where('enabled', true)->count());
        $this->assertSame(0, DB::table('mcp_server_definitions')->where('enabled', true)->count());
    }

    public function test_tool_discovery_merge_preserves_operator_definitions(): void
    {
        DB::table('system_configs')->insert(['section' => 'os_tools', 'config_key' => 'definitions', 'config_value' => json_encode(['sql-tap' => ['purpose' => 'operator override'], 'other' => ['purpose' => 'retained']]), 'data_type' => 'json']);
        $migration = require database_path('migrations/2026_09_27_220000_register_tldr_local_tool_discovery.php');
        $migration->up();
        $migration->up();
        $definitions = json_decode(DB::table('system_configs')->where('config_key', 'definitions')->value('config_value'), true);
        $this->assertSame(['purpose' => 'operator override'], $definitions['sql-tap']);
        $this->assertSame(['purpose' => 'retained'], $definitions['other']);
        $this->assertArrayHasKey('plos-pdf', $definitions);
        $this->assertArrayNotHasKey('plos-pggraph-query', $definitions);
        $this->assertNull($definitions['plos-pdf']['adapter']);
    }

    public function test_dependency_completion_preserves_operator_replacements(): void
    {
        $definitions = json_decode(file_get_contents(base_path('resources/tldr-tools/os-definitions.json')), true);
        unset($definitions['docker']);
        $definitions['plos-archify']['dependencies'] = ['python3'];
        $definitions['plos-pdf'] = ['paths' => ['/custom/pdf'], 'dependencies' => ['custom-java']];
        DB::table('system_configs')->insert(['section' => 'os_tools', 'config_key' => 'definitions', 'data_type' => 'json', 'config_value' => json_encode($definitions)]);
        $migration = require database_path('migrations/2026_09_27_230000_complete_tldr_discovery_dependencies.php');
        $migration->up();
        $migration->up();
        $actual = json_decode(DB::table('system_configs')->where('config_key', 'definitions')->value('config_value'), true);
        $this->assertSame(['python3', 'node'], $actual['plos-archify']['dependencies']);
        $this->assertSame($definitions['plos-pdf'], $actual['plos-pdf']);
        $this->assertSame(['/usr/bin/docker', '/usr/local/bin/docker'], $actual['docker']['paths']);
        $this->assertNull($actual['docker']['adapter']);
    }

    public function test_pggraph_retirement_preserves_other_definitions_and_stays_retired(): void
    {
        $retained = ['plos-hister' => ['purpose' => 'operator setting'], 'docker' => ['purpose' => 'operator container use']];
        DB::table('system_configs')->insert(['section' => 'os_tools', 'config_key' => 'definitions', 'data_type' => 'json',
            'config_value' => json_encode($retained + ['plos-pggraph-query' => ['purpose' => 'retired']])]);
        $migration = require database_path('migrations/2026_10_14_170000_retire_pggraph_reference_discovery.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $actual = json_decode(DB::table('system_configs')->where('config_key', 'definitions')->value('config_value'), true);
        $this->assertSame($retained, $actual);
        $skill = DB::table('skill_versions')->where('skill_name', 'offline-assistant')->where('is_active', true)->first();
        $this->assertSame('1.2.9', $skill->version);
        $this->assertStringNotContainsString('plos-pggraph-query', $skill->full_content);
        $registration = require database_path('migrations/2026_09_27_220000_register_tldr_local_tool_discovery.php');
        $registration->up();
        $this->assertArrayNotHasKey('plos-pggraph-query', json_decode(DB::table('system_configs')->where('config_key', 'definitions')->value('config_value'), true));
    }

    public function test_needle_removal_is_exact_and_does_not_restore_on_rollback(): void
    {
        $definitions = ['plos-needle' => ['purpose' => 'retired'], 'plos-hister' => ['purpose' => 'operator setting']];
        DB::table('system_configs')->insert(['section' => 'os_tools', 'config_key' => 'definitions', 'data_type' => 'json', 'config_value' => json_encode($definitions)]);
        $migration = require database_path('migrations/2026_09_28_100000_remove_needle2_discovery.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $actual = json_decode(DB::table('system_configs')->where('config_key', 'definitions')->value('config_value'), true);
        $this->assertSame(['plos-hister' => $definitions['plos-hister']], $actual);
        $this->assertSame(1, DB::table('skill_versions')->where('skill_name', 'offline-assistant')->where('is_active', true)->count());
        $this->assertSame('1.2.5', DB::table('skill_versions')->where('is_active', true)->value('version'));
    }
}
