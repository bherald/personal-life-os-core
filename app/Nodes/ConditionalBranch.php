<?php

namespace App\Nodes;

use Exception;

/**
 * Multi-path Conditional Branching Node (N10)
 *
 * Evaluates an ordered list of branches (IF/ELSE IF/ELSE).
 * First matching branch wins. A branch without a condition acts as the default.
 * Output includes the matched branch name for downstream `only_if` gating.
 */
class ConditionalBranch extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $branches = $this->getConfigValue('branches', []);

            if (empty($branches)) {
                throw new Exception('No branches configured');
            }

            $matchedBranch = null;

            foreach ($branches as $branch) {
                $name = $branch['name'] ?? 'unnamed';

                // Branch without condition = default (always matches)
                if (empty($branch['path']) && !isset($branch['value'])) {
                    $matchedBranch = $name;
                    break;
                }

                $path = $branch['path'] ?? 'data';
                $operator = $branch['operator'] ?? '==';
                $expected = $branch['value'] ?? null;

                $actual = $this->extractValue($input, $path);

                if ($this->evaluateCondition($actual, $operator, $expected)) {
                    $matchedBranch = $name;
                    break;
                }
            }

            return $this->standardOutput([
                'branch' => $matchedBranch,
                'original_data' => $input,
            ], [
                'branch_count' => count($branches),
                'matched' => $matchedBranch !== null,
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
            'not_in' => is_array($expected) && !in_array($actual, $expected),
            'empty' => empty($actual),
            'not_empty' => !empty($actual),
            default => throw new Exception("Unknown operator: {$operator}"),
        };
    }
}
