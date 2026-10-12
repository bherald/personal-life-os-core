<?php

namespace App\Services\UnifiedCli;

use App\Services\Genealogy\GenealogySourceService;
use App\Services\MultiAgentDebateService;
use App\Services\VisionScreenshotService;

/** Explicit adapters for reviewed names-only contracts; synced rows stay intact. */
final class CliLegacyToolMapping
{
    public function adapt(string $name, array $definition): array
    {
        $mapping = match ($name) {
            'vision_screenshot' => ['service' => VisionScreenshotService::class, 'method' => 'screenshotAndExtract',
                'required' => ['url'], 'properties' => ['url' => ['type' => 'string', 'minLength' => 1],
                    'extraction_prompt' => ['type' => 'string'], 'wait_for' => ['type' => 'string'],
                    'selector' => ['type' => 'string'], 'full_page' => ['type' => 'boolean']]],
            'multi_agent_debate' => ['service' => MultiAgentDebateService::class, 'method' => 'runDebate',
                'required' => ['task'], 'properties' => ['task' => ['type' => 'string', 'minLength' => 1],
                    'mode' => ['type' => 'string', 'enum' => ['diverse', 'adversarial', 'consensus']], 'context' => ['type' => 'string']]],
            'nara_search_census' => ['service' => GenealogySourceService::class, 'method' => 'searchNARACensus',
                'required' => ['surname'], 'properties' => ['surname' => ['type' => 'string', 'minLength' => 1],
                    'given_name' => ['type' => 'string'], 'year' => ['type' => 'integer'],
                    'state' => ['type' => 'string'], 'county' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1]],
                'bundles' => ['options' => ['given_name', 'year', 'state', 'county', 'limit']]],
            default => null,
        };
        $parameters = $definition['parameters'] ?? [];
        if ($mapping === null || ($definition['service'] ?? null) !== $mapping['service']
            || ($definition['method'] ?? null) !== $mapping['method']
            || ! is_array($parameters) || ! is_array($parameters['required'] ?? null) || ! is_array($parameters['optional'] ?? null)
            || count($parameters) !== 2 || ! array_is_list($parameters['required']) || ! array_is_list($parameters['optional'])) {
            return $definition;
        }
        $required = $parameters['required'];
        $optional = $parameters['optional'];
        $expectedRequired = $mapping['required'];
        $expectedOptional = array_values(array_diff(array_keys($mapping['properties']), $expectedRequired));
        sort($required);
        sort($optional);
        sort($expectedRequired);
        sort($expectedOptional);
        if ($required !== $expectedRequired || $optional !== $expectedOptional) {
            // Changed or unknown contracts need an explicit mapping, not a guess.
            return $definition;
        }
        $definition['parameters'] = ['type' => 'object', 'properties' => $mapping['properties'],
            'required' => $mapping['required'], 'additionalProperties' => false];
        $definition['cli_parameter_bundles'] = $mapping['bundles'] ?? [];
        $definition['cli_legacy_mapping'] = $name.':v1';

        return $definition;
    }
}
