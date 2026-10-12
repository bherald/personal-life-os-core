<?php

namespace App\Services;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ValidationError;

class MCPToolInvocationValidatorService
{
    /**
     * @param  array<string,mixed>  $arguments
     * @return array{names:list<string>,sha256:string}
     */
    public function summarizeArguments(array $arguments): array
    {
        $json = json_encode((object) $arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'names' => array_values(array_map($this->safeFieldName(...), array_keys($arguments))),
            'sha256' => hash('sha256', $json),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $schema
     * @return array{valid:bool,codes:list<string>,fields:list<string>,schema_sha256:string,arguments_sha256:string}
     */
    public function validate(array $arguments, array $schema, bool $rejectUnknownRootArguments = true): array
    {
        $schema = $this->hardenRootObjectSchema($schema, $rejectUnknownRootArguments);
        $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $argumentsJson = json_encode((object) $arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $codes = [];
        $fields = [];

        if ($rejectUnknownRootArguments) {
            $known = array_keys((array) ($schema['properties'] ?? []));
            $unknown = array_values(array_diff(array_keys($arguments), $known));
            if ($unknown !== []) {
                $codes[] = 'unknown_argument';
                $fields = array_merge($fields, $unknown);
            }
        }

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (is_string($required) && ! array_key_exists($required, $arguments)) {
                $codes[] = 'missing_required';
                $fields[] = $required;
            }
        }

        try {
            $result = (new CompliantValidator)->validate(
                json_decode($argumentsJson, false, 512, JSON_THROW_ON_ERROR),
                json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR),
            );

            if (! $result->isValid() && $result->error() !== null) {
                $this->collectErrors($result->error(), $codes, $fields);
            }
        } catch (\Throwable) {
            $codes[] = 'validator_failure';
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
            'arguments_sha256' => hash('sha256', $argumentsJson),
        ];
    }

    /** @param array<string,mixed> $schema */
    private function hardenRootObjectSchema(array $schema, bool $rejectUnknownRootArguments): array
    {
        if (($schema['type'] ?? null) === 'object' && $rejectUnknownRootArguments && ! array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        return $schema;
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
            'additionalProperties', 'unevaluatedProperties' => 'unknown_argument',
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
