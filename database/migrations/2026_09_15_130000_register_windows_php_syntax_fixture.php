<?php

use App\Services\OsToolWindowsPhpFixtureService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function definition(): array
    {
        return ['name' => 'os_tools_php_syntax_fixture', 'service_class' => OsToolWindowsPhpFixtureService::class,
            'method' => 'checkFixture', 'description' => 'Check one fixed synthetic PHP fixture for syntax on its reviewed Windows execution host. No code or target paths accepted.',
            'parameters' => json_encode(['type' => 'object', 'properties' => [
                'fixture' => ['type' => 'string', 'enum' => array_keys(OsToolWindowsPhpFixtureService::FILES)],
                'expected_environment' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'expected_evidence' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']],
                'required' => ['fixture', 'expected_environment', 'expected_evidence'], 'additionalProperties' => false], JSON_THROW_ON_ERROR),
            'returns_description' => 'Complete or incomplete syntax-only synthetic result; never a customer security finding.',
            'permissions' => json_encode(['os_tools:qualify'], JSON_THROW_ON_ERROR), 'risk_level' => 'write', 'category' => 'os_tools',
            'enabled' => false, 'requires_confirmation' => true, 'availability_status' => 'disabled',
            'privacy_class' => 'internal_private', 'allows_private_data' => false, 'source' => 'manual',
            'notes' => 'windows-php-fixture-v1: disabled until exact host, selected runtime/evidence and ordinary approval route are qualified.'];
    }

    public function up(): void
    {
        DB::table('agent_tool_registry')->insertOrIgnore($this->definition() + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve operator qualifications or edits. Exact pristine row only.
        DB::transaction(function (): void {
            $definition = $this->definition();
            $row = DB::table('agent_tool_registry')->where('name', $definition['name'])->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            foreach ($definition as $key => $value) {
                $equal = in_array($key, ['parameters', 'permissions'], true)
                    ? json_decode($row->{$key}, true, flags: JSON_THROW_ON_ERROR) == json_decode($value, true, flags: JSON_THROW_ON_ERROR)
                    : (string) $row->{$key} === (string) (is_bool($value) ? (int) $value : $value);
                if (! $equal) {
                    return;
                }
            }
            DB::table('agent_tool_registry')->where('id', $row->id)->delete();
        });
    }
};
