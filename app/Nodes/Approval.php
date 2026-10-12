<?php

namespace App\Nodes;

use App\Exceptions\WorkflowApprovalRequiredException;
use InvalidArgumentException;

class Approval extends BaseNode
{
    private const MAX_RESPONSE_FIELDS = 10;

    private const MAX_SELECT_OPTIONS = 20;

    public static function getDefinition(): array
    {
        return [
            'name' => 'Operator Approval',
            'type' => 'approval',
            'description' => 'Pause execution until an authenticated operator approves or rejects the run.',
            'category' => 'logic',
            'config' => [
                'title' => [
                    'type' => 'string',
                    'label' => 'Approval title',
                    'default' => 'Workflow approval required',
                ],
                'instructions' => [
                    'type' => 'string',
                    'label' => 'Operator instructions',
                    'default' => 'Review the workflow checkpoint before allowing downstream actions.',
                ],
                'timeout_minutes' => [
                    'type' => 'integer',
                    'label' => 'Timeout (minutes)',
                    'default' => 1440,
                ],
                'response_fields' => [
                    'type' => 'json',
                    'label' => 'Response fields',
                    'default' => [],
                ],
            ],
            'outputs' => [
                'approval' => 'The authenticated operator decision and validated field values.',
            ],
        ];
    }

    public function execute(array $input): array
    {
        throw new WorkflowApprovalRequiredException(
            $this->boundedText('title', 'Workflow approval required', 160),
            $this->boundedText(
                'instructions',
                'Review the workflow checkpoint before allowing downstream actions.',
                2000
            ),
            $this->normalizeResponseFields($this->getConfigValue('response_fields', [])),
            max(1, min(10080, (int) $this->getConfigValue('timeout_minutes', 1440))),
        );
    }

    private function boundedText(string $key, string $default, int $maxLength): string
    {
        $value = trim((string) $this->getConfigValue($key, $default));

        return mb_substr($value !== '' ? $value : $default, 0, $maxLength);
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeResponseFields(mixed $rawFields): array
    {
        if (is_string($rawFields)) {
            $decoded = json_decode($rawFields, true);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Approval response_fields must be a JSON array.');
            }
            $rawFields = $decoded;
        }

        if (! is_array($rawFields) || ! array_is_list($rawFields)) {
            throw new InvalidArgumentException('Approval response_fields must be a list.');
        }

        if (count($rawFields) > self::MAX_RESPONSE_FIELDS) {
            throw new InvalidArgumentException('Approval response_fields cannot contain more than 10 fields.');
        }

        $fields = [];
        $seenNames = [];
        foreach ($rawFields as $rawField) {
            if (! is_array($rawField)) {
                throw new InvalidArgumentException('Each approval response field must be an object.');
            }

            $name = trim((string) ($rawField['name'] ?? ''));
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,49}\z/', $name) !== 1) {
                throw new InvalidArgumentException("Invalid approval response field name: {$name}");
            }
            if (isset($seenNames[$name])) {
                throw new InvalidArgumentException("Duplicate approval response field name: {$name}");
            }
            $seenNames[$name] = true;

            $type = (string) ($rawField['type'] ?? 'text');
            if (! in_array($type, ['text', 'textarea', 'select', 'boolean'], true)) {
                throw new InvalidArgumentException("Unsupported approval response field type: {$type}");
            }

            $options = [];
            if ($type === 'select') {
                $rawOptions = $rawField['options'] ?? [];
                if (! is_array($rawOptions) || $rawOptions === [] || count($rawOptions) > self::MAX_SELECT_OPTIONS) {
                    throw new InvalidArgumentException("Select field {$name} must define 1-20 options.");
                }
                $options = array_values(array_unique(array_map(
                    fn (mixed $option): string => mb_substr(trim((string) $option), 0, 100),
                    $rawOptions
                )));
                if (in_array('', $options, true)) {
                    throw new InvalidArgumentException("Select field {$name} contains an empty option.");
                }
            }

            $fields[] = [
                'name' => $name,
                'label' => mb_substr(trim((string) ($rawField['label'] ?? $name)), 0, 100),
                'type' => $type,
                'required' => filter_var($rawField['required'] ?? false, FILTER_VALIDATE_BOOL),
                'options' => $options,
            ];
        }

        return $fields;
    }
}
