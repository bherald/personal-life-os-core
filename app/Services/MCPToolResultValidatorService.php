<?php

namespace App\Services;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ValidationError;

class MCPToolResultValidatorService
{
    /** PLOS outcome flags, distinct from MCP protocol/schema validity or answer quality.
     * Only declared result containers are inspected; source text is never parsed.
     */
    public function outcome(mixed $result, bool $mcpEnvelope = false): array
    {
        $failed = false;
        $warnings = [];
        $containers = is_array($result) ? [$result] : [];
        if ($mcpEnvelope && is_array($result['structuredContent'] ?? null)) {
            $containers[] = $result['structuredContent'];
        }
        foreach ($containers as $container) {
            $failed = $failed || ($container['isError'] ?? false) === true
                || ($container['success'] ?? null) === false
                || (! array_key_exists('success', $container) && ! empty($container['error']));
            if (($container['degraded'] ?? false) === true) {
                $warnings[] = 'reduced_coverage';
            }
            if (is_array($container['warnings'] ?? null) && $container['warnings'] !== []) {
                $warnings[] = 'source_warnings';
            }
            if (in_array($container['freshness'] ?? null, ['stale', 'future'], true)) {
                $warnings[] = 'source_freshness';
            }
        }

        return ['failed' => $failed, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array{valid:bool,codes:list<string>,schema_sha256:string}
     */
    public function validateSchema(array $schema): array
    {
        $codes = [];
        $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $type = $schema['type'] ?? null;
        $objectTypeAllowed = $type === null
            || $type === 'object'
            || (is_array($type) && in_array('object', $type, true));
        if (($schema !== [] && array_is_list($schema)) || ! $objectTypeAllowed) {
            $codes[] = 'output_schema_invalid';
        }

        try {
            (new CompliantValidator)->validate(
                new \stdClass,
                json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            $codes[] = 'output_schema_invalid';
        }

        $codes = array_values(array_unique($codes));

        return [
            'valid' => $codes === [],
            'codes' => $codes,
            'schema_sha256' => hash('sha256', $schemaJson),
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $schema
     * @return array{valid:bool,codes:list<string>,fields:list<string>,schema_sha256:string}
     */
    public function validate(array $result, array $schema, mixed $rawStructuredContent = null): array
    {
        $codes = [];
        $fields = [];
        $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $codes = $this->validateSchema($schema)['codes'];

        if (! array_key_exists('structuredContent', $result)) {
            $codes[] = 'missing_structured_content';
        } elseif ($rawStructuredContent !== null && ! is_object($rawStructuredContent)) {
            $codes[] = 'structured_content_not_object';
        } elseif ($rawStructuredContent === null && ! is_array($result['structuredContent'])) {
            $codes[] = 'structured_content_not_object';
        } else {
            try {
                $structuredContent = $rawStructuredContent;
                if ($structuredContent === null) {
                    $decodedContent = $result['structuredContent'];
                    $structuredContent = $decodedContent === [] || ! array_is_list($decodedContent)
                        ? (object) $decodedContent
                        : $decodedContent;
                }
                $validation = (new CompliantValidator)->validate(
                    $structuredContent,
                    json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR),
                );

                if (! $validation->isValid() && $validation->error() !== null) {
                    $this->collectErrors($validation->error(), $codes, $fields);
                }
            } catch (\Throwable) {
                $codes[] = 'validator_failure';
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes);
        $fields = array_values(array_unique(array_map(
            $this->safeFieldName(...),
            array_filter($fields, static fn (mixed $field): bool => is_string($field) && $field !== '')
        )));
        sort($fields);

        return [
            'valid' => $codes === [],
            'codes' => $codes,
            'fields' => $fields,
            'schema_sha256' => hash('sha256', $schemaJson),
        ];
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $fields
     */
    private function collectErrors(ValidationError $error, array &$codes, array &$fields): void
    {
        $keyword = preg_replace('/[^a-zA-Z0-9_-]/', '', $error->keyword()) ?: 'schema';
        $codes[] = match ($keyword) {
            'required' => 'missing_required',
            'additionalProperties', 'unevaluatedProperties' => 'unknown_result_field',
            default => 'schema_'.$keyword,
        };

        $path = $error->data()->fullPath();
        if ($path !== []) {
            $fields[] = implode('.', array_map('strval', $path));
        }

        foreach ($error->subErrors() as $subError) {
            $this->collectErrors($subError, $codes, $fields);
        }
    }

    private function safeFieldName(string $field): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/', $field) === 1) {
            return $field;
        }

        return 'field_sha256:'.substr(hash('sha256', $field), 0, 16);
    }
}
