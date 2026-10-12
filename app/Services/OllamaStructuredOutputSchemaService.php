<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Exceptions\SchemaException;

class OllamaStructuredOutputSchemaService
{
    public function has(?string $schemaId): bool
    {
        return is_string($schemaId)
            && $schemaId !== ''
            && is_array(config("ollama_output_schemas.schemas.{$schemaId}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $schemaId): array
    {
        $schema = config("ollama_output_schemas.schemas.{$schemaId}");

        if (! is_array($schema)
            || ($schema['type'] ?? null) !== 'object'
            || ! is_array($schema['properties'] ?? null)
            || ! is_array($schema['required'] ?? null)) {
            throw new InvalidArgumentException("Unknown or invalid Ollama output schema: {$schemaId}");
        }

        return $schema;
    }

    public function validateResponse(string $schemaId, string $response): bool
    {
        try {
            $data = json_decode(trim($response), false, 512, JSON_THROW_ON_ERROR);
            $schema = json_decode(
                json_encode($this->resolve($schemaId), JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR
            );

            return (new CompliantValidator)->validate($data, $schema)->isValid();
        } catch (InvalidArgumentException|JsonException|SchemaException) {
            return false;
        }
    }

    /**
     * Narrow generation to fields required by the registered schema while
     * preserving all validation constraints on those fields. The returned
     * document remains a strict subset of the full schema and is still
     * validated against the full registry after generation.
     *
     * @return array<string, mixed>
     */
    public function requiredPropertyProjection(string $schemaId): array
    {
        return $this->projectRequiredProperties($this->resolve($schemaId));
    }

    /** @return array<string, mixed> */
    public function boundedEvalProjection(string $schemaId, string $taskClass): array
    {
        $schema = $this->requiredPropertyProjection($schemaId);
        if ($schemaId !== 'genealogy_facts_json' || $taskClass !== 'fact_extraction') {
            return $schema;
        }

        $schema['properties']['confidence']['const'] = 1;
        $schema['properties']['document_type']['const'] = 'note';
        $schema['properties']['document_year']['const'] = null;
        $schema['properties']['notes_remainder']['maxLength'] = 0;
        $schema['properties']['persons']['minItems'] = 1;
        $schema['properties']['persons']['maxItems'] = 1;

        $person = &$schema['properties']['persons']['items'];
        $person['properties']['name']['maxLength'] = 18;
        $person['properties']['role']['const'] = 'subject';
        $person['properties']['confidence']['const'] = 1;
        $person['properties']['facts']['minItems'] = 1;
        $person['properties']['facts']['maxItems'] = 1;
        $person['properties']['facts']['items']['properties']['field']['const'] = 'other';
        $person['properties']['facts']['items']['properties']['value']['maxLength'] = 64;
        $person['properties']['facts']['items']['properties']['confidence']['const'] = 1;
        $person['properties']['relationships']['maxItems'] = 0;
        unset($person);

        return $schema;
    }

    public function boundedEvalGrammar(string $schemaId, string $taskClass): ?string
    {
        if ($schemaId !== 'genealogy_facts_json' || $taskClass !== 'fact_extraction') {
            return null;
        }

        return <<<'GBNF'
root ::= "{\"confidence\":1,\"document_type\":\"note\",\"document_year\":null,\"persons\":[{\"name\":\"" name "\",\"role\":\"subject\",\"confidence\":1,\"facts\":[{\"field\":\"other\",\"value\":\"" fact "\",\"confidence\":1}],\"relationships\":[]}],\"notes_remainder\":\"\"}"
name ::= [A-Za-z .'-]{1,18}
fact ::= [A-Za-z0-9 ,.;:'/()-]{1,64}
GBNF;
    }

    public function version(): string
    {
        return (string) config('ollama_output_schemas.schema_version', 'unknown');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function projectRequiredProperties(array $schema): array
    {
        unset($schema['description'], $schema['title'], $schema['examples'], $schema['default']);

        if (($schema['type'] ?? null) === 'object'
            && is_array($schema['properties'] ?? null)
            && is_array($schema['required'] ?? null)
        ) {
            $properties = [];
            foreach ($schema['required'] as $name) {
                if (is_string($name) && is_array($schema['properties'][$name] ?? null)) {
                    $properties[$name] = $this->projectRequiredProperties($schema['properties'][$name]);
                }
            }
            $schema['properties'] = $properties;
        }

        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->projectRequiredProperties($schema['items']);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $schema[$keyword] = array_map(
                    fn (mixed $branch): mixed => is_array($branch)
                        ? $this->projectRequiredProperties($branch)
                        : $branch,
                    $schema[$keyword]
                );
            }
        }

        return $schema;
    }
}
