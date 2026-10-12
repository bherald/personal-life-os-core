<?php

namespace App\Services\Ops;

use UnexpectedValueException;

final class OtlpTraceJsonValidatorService
{
    public const SCHEMA = 'plos.otlp_trace_json_validation.v2';

    public const FORBIDDEN_CONTENT_ATTRIBUTE_FAMILIES = [
        'input.value',
        'output.value',
        'llm.input_messages',
        'llm.output_messages',
        'llm.prompts',
        'llm.choices',
        'message.content',
        'message_content.text',
        'message_content.data',
        'message_content.signature',
        'message_content.encrypted_content',
        'embedding.text',
        'embedding.vector',
        'embedding.embeddings',
        'document.content',
        'document.metadata',
        'gen_ai.retrieval.query.text',
        'gen_ai.retrieval.documents',
        'retrieval.documents',
        'tool.parameters',
        'tool.json_schema',
        'tool_call.function.arguments',
        'tool_call.reasoning_signature',
        'exception.message',
        'exception.stacktrace',
        'image.url',
        'audio.url',
        'audio.transcript',
    ];

    /**
     * Validate the protobuf JSON shape emitted for ExportTraceServiceRequest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $resourceSpans = $this->requiredList($payload, 'resourceSpans', 'root');
        if ($resourceSpans === []) {
            throw new UnexpectedValueException('OTLP trace JSON requires at least one resourceSpans entry.');
        }

        $spanCount = 0;
        $spanIdentities = [];

        foreach ($resourceSpans as $resourceIndex => $resourceEntry) {
            $resource = $this->requiredArray($resourceEntry, 'resource', "resourceSpans.{$resourceIndex}");
            $this->validateAttributes(
                $this->requiredList($resource, 'attributes', "resourceSpans.{$resourceIndex}.resource"),
                "resourceSpans.{$resourceIndex}.resource.attributes",
            );

            $scopeSpans = $this->requiredList($resourceEntry, 'scopeSpans', "resourceSpans.{$resourceIndex}");
            if ($scopeSpans === []) {
                throw new UnexpectedValueException("resourceSpans.{$resourceIndex}.scopeSpans must not be empty.");
            }

            foreach ($scopeSpans as $scopeIndex => $scopeEntry) {
                $path = "resourceSpans.{$resourceIndex}.scopeSpans.{$scopeIndex}";
                $scope = $this->requiredArray($scopeEntry, 'scope', $path);
                $this->requiredNonEmptyString($scope, 'name', $path.'.scope');
                $this->requiredNonEmptyString($scope, 'version', $path.'.scope');

                foreach ($this->requiredList($scopeEntry, 'spans', $path) as $spanIndex => $span) {
                    $spanPath = $path.'.spans.'.$spanIndex;
                    if (! is_array($span)) {
                        throw new UnexpectedValueException("{$spanPath} must be an object.");
                    }

                    $traceId = $this->validateBytesId($span['traceId'] ?? null, 16, $spanPath.'.traceId');
                    $spanId = $this->validateBytesId($span['spanId'] ?? null, 8, $spanPath.'.spanId');
                    if (array_key_exists('parentSpanId', $span)) {
                        $this->validateBytesId($span['parentSpanId'], 8, $spanPath.'.parentSpanId');
                    }

                    $identity = $traceId.':'.$spanId;
                    if (isset($spanIdentities[$identity])) {
                        throw new UnexpectedValueException("{$spanPath} repeats a traceId/spanId identity.");
                    }
                    $spanIdentities[$identity] = true;

                    $this->requiredNonEmptyString($span, 'name', $spanPath);
                    $this->validateIntegerRange($span['kind'] ?? null, 0, 5, $spanPath.'.kind');
                    $start = $this->validateUnsignedDecimal($span['startTimeUnixNano'] ?? null, $spanPath.'.startTimeUnixNano');
                    $end = $this->validateUnsignedDecimal($span['endTimeUnixNano'] ?? null, $spanPath.'.endTimeUnixNano');
                    if ($this->compareUnsignedDecimal($end, $start) < 0) {
                        throw new UnexpectedValueException("{$spanPath}.endTimeUnixNano precedes startTimeUnixNano.");
                    }

                    $this->validateAttributes(
                        $this->requiredList($span, 'attributes', $spanPath),
                        $spanPath.'.attributes',
                    );
                    $status = $this->requiredArray($span, 'status', $spanPath);
                    $this->validateIntegerRange($status['code'] ?? null, 0, 2, $spanPath.'.status.code');
                    $spanCount++;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => 'pass',
            'protobuf_json_mapping' => true,
            'byte_id_encoding' => 'canonical_base64',
            'uint64_encoding' => 'decimal_string',
            'validated_spans' => $spanCount,
            'collector_invoked' => false,
            'content_capture' => false,
            'content_attribute_gate' => 'fail_closed',
            'forbidden_content_attribute_families' => count(self::FORBIDDEN_CONTENT_ATTRIBUTE_FAMILIES),
            'reasoning_content_exported' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    /** @return array<int, mixed> */
    private function requiredList(mixed $parent, string $key, string $path): array
    {
        if (! is_array($parent) || ! array_key_exists($key, $parent) || ! is_array($parent[$key]) || ! array_is_list($parent[$key])) {
            throw new UnexpectedValueException("{$path}.{$key} must be a list.");
        }

        return $parent[$key];
    }

    /** @return array<string, mixed> */
    private function requiredArray(mixed $parent, string $key, string $path): array
    {
        if (! is_array($parent) || ! array_key_exists($key, $parent) || ! is_array($parent[$key]) || array_is_list($parent[$key])) {
            throw new UnexpectedValueException("{$path}.{$key} must be an object.");
        }

        return $parent[$key];
    }

    private function requiredNonEmptyString(array $parent, string $key, string $path): string
    {
        $value = $parent[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("{$path}.{$key} must be a nonempty string.");
        }

        return $value;
    }

    private function validateBytesId(mixed $value, int $bytes, string $path): string
    {
        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("{$path} must be a canonical base64 string.");
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) !== $bytes || base64_encode($decoded) !== $value) {
            throw new UnexpectedValueException("{$path} must encode exactly {$bytes} bytes as canonical base64.");
        }
        if ($decoded === str_repeat("\0", $bytes)) {
            throw new UnexpectedValueException("{$path} must not be all zero bytes.");
        }

        return $value;
    }

    private function validateIntegerRange(mixed $value, int $minimum, int $maximum, string $path): void
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new UnexpectedValueException("{$path} must be an integer from {$minimum} through {$maximum}.");
        }
    }

    private function validateUnsignedDecimal(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new UnexpectedValueException("{$path} must be an unsigned decimal string.");
        }

        return $value;
    }

    private function compareUnsignedDecimal(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    /** @param array<int, mixed> $attributes */
    private function validateAttributes(array $attributes, string $path): void
    {
        $keys = [];

        foreach ($attributes as $index => $attribute) {
            $attributePath = $path.'.'.$index;
            if (! is_array($attribute) || array_is_list($attribute)) {
                throw new UnexpectedValueException("{$attributePath} must be an object.");
            }

            $key = $this->requiredNonEmptyString($attribute, 'key', $attributePath);
            $this->rejectContentAttributeKey($key, $attributePath);
            if (isset($keys[$key])) {
                throw new UnexpectedValueException("{$attributePath} repeats attribute key {$key}.");
            }
            $keys[$key] = true;

            $value = $this->requiredArray($attribute, 'value', $attributePath);
            if (count($value) !== 1) {
                throw new UnexpectedValueException("{$attributePath}.value must contain exactly one scalar protobuf value.");
            }

            $valueKey = array_key_first($value);
            $scalar = $value[$valueKey];
            $valid = match ($valueKey) {
                'stringValue' => is_string($scalar),
                'boolValue' => is_bool($scalar),
                'intValue' => is_string($scalar) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $scalar) === 1,
                'doubleValue' => (is_int($scalar) || is_float($scalar)) && is_finite((float) $scalar),
                default => false,
            };

            if (! $valid) {
                throw new UnexpectedValueException("{$attributePath}.value is not a supported scalar protobuf JSON value.");
            }
        }
    }

    private function rejectContentAttributeKey(string $key, string $path): void
    {
        foreach (self::FORBIDDEN_CONTENT_ATTRIBUTE_FAMILIES as $family) {
            if (
                $key === $family
                || str_starts_with($key, $family.'.')
                || str_ends_with($key, '.'.$family)
                || str_contains($key, '.'.$family.'.')
            ) {
                throw new UnexpectedValueException("{$path}.key uses forbidden content-bearing attribute family {$family}.");
            }
        }
    }
}
