<?php

namespace App\Nodes;

use Exception;

/**
 * Conditional Node - Branch workflow based on conditions
 */
class Conditional extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $condition = $this->getConfigValue('condition');
            $operator = $this->getConfigValue('operator', '==');
            $expectedValue = $this->getConfigValue('expected_value');
            $path = $this->getConfigValue('path');

            if (!$condition && !$path) {
                throw new Exception('Either condition or path must be specified');
            }

            $actualValue = $this->extractValue($input, $path ?? 'data');
            $result = $this->evaluateCondition($actualValue, $operator, $expectedValue ?? $condition);

            return $this->standardOutput([
                'condition_met' => $result,
                'actual_value' => $actualValue,
                'expected_value' => $expectedValue ?? $condition,
                'original_data' => $input,
            ], [
                'operator' => $operator,
                'path' => $path,
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function extractValue(array $input, string $path)
    {
        $parts = explode('.', $path);
        $value = $input;

        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function evaluateCondition($actual, string $operator, $expected): bool
    {
        return match ($operator) {
            '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'contains' => is_string($actual) && str_contains($actual, $expected),
            'matches' => is_string($actual) && preg_match($expected, $actual),
            'in' => is_array($expected) && in_array($actual, $expected),
            default => throw new Exception("Unknown operator: {$operator}"),
        };
    }
}
