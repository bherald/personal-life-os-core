<?php

namespace App\Services;

use Exception;

class ConditionalBranchService
{
    private static array $operators = [
        '==' => 'Equals',
        '===' => 'Strict equals',
        '!=' => 'Not equals',
        '>' => 'Greater than',
        '>=' => 'Greater than or equal',
        '<' => 'Less than',
        '<=' => 'Less than or equal',
        'contains' => 'String contains',
        'matches' => 'Regex match',
        'in' => 'In array',
        'not_in' => 'Not in array',
        'empty' => 'Is empty',
        'not_empty' => 'Is not empty',
    ];

    public function getOperators(): array
    {
        return self::$operators;
    }

    public function validateBranches(array $branches): array
    {
        $errors = [];

        if (empty($branches)) {
            return ['valid' => false, 'errors' => ['At least one branch is required']];
        }

        $names = [];
        $hasDefault = false;

        foreach ($branches as $i => $branch) {
            $idx = $i + 1;

            if (empty($branch['name'])) {
                $errors[] = "Branch #{$idx}: name is required";
            } elseif (in_array($branch['name'], $names)) {
                $errors[] = "Branch #{$idx}: duplicate name '{$branch['name']}'";
            } else {
                $names[] = $branch['name'];
            }

            $isDefault = empty($branch['path']) && !isset($branch['value']);

            if ($isDefault) {
                if ($hasDefault) {
                    $errors[] = "Branch #{$idx}: only one default branch allowed";
                }
                $hasDefault = true;

                if ($i < count($branches) - 1) {
                    $errors[] = "Branch #{$idx}: default branch must be last";
                }
            } else {
                if (!empty($branch['operator']) && !isset(self::$operators[$branch['operator']])) {
                    $errors[] = "Branch #{$idx}: unknown operator '{$branch['operator']}'";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'branch_count' => count($branches),
            'has_default' => $hasDefault,
        ];
    }

    public function testBranches(array $branches, array $sampleInput): array
    {
        $node = new \App\Nodes\ConditionalBranch(['branches' => $branches]);
        $result = $node->execute($sampleInput);

        $branchResults = [];
        foreach ($branches as $branch) {
            $branchResults[] = [
                'name' => $branch['name'] ?? 'unnamed',
                'matched' => ($result['data']['branch'] ?? null) === ($branch['name'] ?? null),
            ];
        }

        return [
            'matched_branch' => $result['data']['branch'] ?? null,
            'branches' => $branchResults,
            'error' => $result['error'] ?? null,
        ];
    }
}
