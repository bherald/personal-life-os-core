<?php

namespace App\Nodes;

use Exception;

/**
 * Transform Node - Transform data using various operations
 */
class Transform extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $operation = $this->getConfigValue('operation');
            $path = $this->getConfigValue('path', 'data');
            $outputKey = $this->getConfigValue('output_key', 'transformed');

            $data = $this->extractValue($input, $path);
            $transformed = $this->applyTransformation($data, $operation);

            return $this->standardOutput([
                $outputKey => $transformed,
                'original_data' => $input,
            ], [
                'operation' => $operation,
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

    private function applyTransformation($data, $operation)
    {
        // Parse operation (format: "operation:param1,param2")
        $parts = explode(':', $operation);
        $op = $parts[0];
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

        return match ($op) {
            'uppercase' => is_string($data) ? strtoupper($data) : $data,
            'lowercase' => is_string($data) ? strtolower($data) : $data,
            'trim' => is_string($data) ? trim($data) : $data,
            'json_encode' => json_encode($data, JSON_PRETTY_PRINT),
            'json_decode' => is_string($data) ? json_decode($data, true) : $data,
            'count' => is_array($data) ? count($data) : 0,
            'sum' => is_array($data) ? array_sum($data) : 0,
            'average' => is_array($data) && count($data) > 0 ? array_sum($data) / count($data) : 0,
            'filter' => is_array($data) ? array_filter($data) : $data,
            'map' => is_array($data) ? array_map(fn($item) => $item, $data) : $data,
            'pluck' => is_array($data) && isset($params[0]) ? array_column($data, $params[0]) : $data,
            'first' => is_array($data) ? ($data[0] ?? null) : $data,
            'last' => is_array($data) ? end($data) : $data,
            'unique' => is_array($data) ? array_unique($data) : $data,
            'sort' => is_array($data) ? (sort($data) ? $data : $data) : $data,
            default => throw new Exception("Unknown transformation: {$op}"),
        };
    }
}
