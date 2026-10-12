<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Only known predecessor definitions are upgraded; customized rows are untouched. */
    public function transitions(): array
    {
        $first = require database_path('migrations/2026_09_13_150000_register_local_os_tool_discovery.php');
        $second = require database_path('migrations/2026_09_13_160000_expand_os_tool_discovery_review.php');
        $transitions = [];
        foreach ([...$first->definitions(), ...$second->definitions()] as $before) {
            if (! in_array($before['name'], ['os_tools_review', 'os_tools_shellcheck_fixture'], true)) {
                continue;
            }
            $after = $before;
            $parameters = json_decode($before['parameters'], true, flags: JSON_THROW_ON_ERROR);
            $pin = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
            if ($before['name'] === 'os_tools_review') {
                $parameters['properties']['previous_evidence'] = $pin;
                $after['description'] = 'Rediscover the current job environment and prepare tool, package and documentation evidence for review. Optionally compare an earlier evidence fingerprint. Never installs, executes or approves a discovered tool.';
            } else {
                foreach (['expected_environment', 'expected_evidence'] as $field) {
                    $parameters['properties'][$field] = $pin;
                    $parameters['required'][] = $field;
                }
                $after['description'] = 'Run one named synthetic ShellCheck fixture only after exact-action confirmation and matching current execution-environment, binary and reviewed-evidence fingerprints. Changed evidence requires re-review; no arbitrary paths or targets.';
            }
            $after['parameters'] = json_encode($parameters, JSON_THROW_ON_ERROR);
            $after['notes'] = $before['notes'].' os-tools-revet-v1: review evidence is host-bound and rechecked before adapter launch; existing execution approval remains mandatory.';
            $transitions[] = ['before' => $before, 'after' => $after];
        }

        return $transitions;
    }

    public function up(): void
    {
        $this->apply('before', 'after');
    }

    public function down(): void
    {
        $this->apply('after', 'before');
    }

    /** MySQL may reorder JSON object keys; lists, scalar types and values remain exact. */
    private function equivalentJson(string $left, string $right): bool
    {
        return json_encode($this->orderedObjects(json_decode($left, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR)
            === json_encode($this->orderedObjects(json_decode($right, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    }

    private function orderedObjects(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members);

            return (object) array_map($this->orderedObjects(...), $members);
        }

        return is_array($value) ? array_map($this->orderedObjects(...), $value) : $value;
    }

    private function apply(string $from, string $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            foreach ($this->transitions() as $transition) {
                $expected = $transition[$from];
                $current = DB::table('agent_tool_registry')->where('name', $expected['name'])->lockForUpdate()->first();
                if ($current === null) {
                    continue;
                }
                foreach ($expected as $field => $value) {
                    $equal = in_array($field, ['parameters', 'permissions'], true)
                        ? $this->equivalentJson($current->{$field}, $value)
                        : (string) $current->{$field} === (string) (is_bool($value) ? (int) $value : $value);
                    if (! $equal) {
                        continue 2;
                    }
                }
                DB::table('agent_tool_registry')->where('id', $current->id)->update($transition[$to] + ['updated_at' => now()]);
            }
        });
    }
};
