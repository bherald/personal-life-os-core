<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use ReflectionClass;
use ReflectionMethod;
use Exception;

class NodeController extends Controller
{
    public function types(): JsonResponse
    {
        try {
            $nodePath = app_path('Nodes');
            $nodes = [];

            $files = glob($nodePath . '/*.php');

            foreach ($files as $file) {
                $className = basename($file, '.php');

                // Skip BaseNode
                if ($className === 'BaseNode') {
                    continue;
                }

                $fullClassName = "App\\Nodes\\{$className}";

                if (!class_exists($fullClassName)) {
                    continue;
                }

                $nodeInfo = $this->getNodeMetadata($fullClassName);

                if ($nodeInfo) {
                    $nodes[] = $nodeInfo;
                }
            }

            // Sort nodes alphabetically by name
            usort($nodes, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'success' => true,
                'data' => $nodes
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    private function getNodeMetadata(string $className): ?array
    {
        try {
            $reflection = new ReflectionClass($className);

            // Check if node has static getDefinition method (newer pattern)
            if (method_exists($className, 'getDefinition')) {
                $definition = $className::getDefinition();

                // Format config for frontend
                $formattedConfig = [
                    'required' => [],
                    'optional' => []
                ];

                if (isset($definition['config'])) {
                    foreach ($definition['config'] as $key => $configItem) {
                        $param = [
                            'name' => $key,
                            'type' => $configItem['type'] ?? 'string',
                            'description' => $configItem['description'] ?? ucfirst(str_replace('_', ' ', $key)),
                            'label' => $configItem['label'] ?? ucfirst(str_replace('_', ' ', $key)),
                        ];

                        if (isset($configItem['default'])) {
                            $param['default'] = $configItem['default'];
                        }
                        if (isset($configItem['options'])) {
                            $param['options'] = $configItem['options'];
                        }

                        if ($configItem['required'] ?? false) {
                            $formattedConfig['required'][] = $param;
                        } else {
                            $formattedConfig['optional'][] = $param;
                        }
                    }
                }

                return [
                    'name' => $definition['name'] ?? $reflection->getShortName(),
                    'type' => $definition['type'] ?? $this->determineNodeType($reflection->getShortName()),
                    'description' => $definition['description'] ?? '',
                    'category' => $definition['category'] ?? 'utility',
                    'icon' => $definition['icon'] ?? null,
                    'config' => $formattedConfig,
                    'outputs' => $definition['outputs'] ?? [],
                    'className' => $className
                ];
            }

            // Fall back to reflection-based metadata for legacy nodes
            $name = $reflection->getShortName();

            // Get type by analyzing class name
            $type = $this->determineNodeType($name);

            // Get description and configuration from docblock
            $docComment = $reflection->getDocComment();
            $description = $this->extractDescription($docComment, $name);

            // Analyze execute method to understand inputs/outputs
            $executeMethod = $reflection->getMethod('execute');
            $methodDoc = $executeMethod->getDocComment();

            // Get required and optional config parameters
            $configParams = $this->extractConfigParams($className);

            // Get outputs by analyzing the node implementation
            $outputs = $this->extractOutputs($className);

            return [
                'name' => $name,
                'type' => $type,
                'description' => $description,
                'category' => 'utility',
                'config' => [
                    'required' => $configParams['required'],
                    'optional' => $configParams['optional']
                ],
                'outputs' => $outputs,
                'className' => $className
            ];

        } catch (Exception $e) {
            return null;
        }
    }

    private function determineNodeType(string $name): string
    {
        $name = strtolower($name);

        if (str_contains($name, 'ai') || str_contains($name, 'formatter')) {
            return 'ai';
        }

        if (str_contains($name, 'notify') || str_contains($name, 'pushover')) {
            return 'notification';
        }

        if (str_contains($name, 'weather') || str_contains($name, 'api')) {
            return 'api';
        }

        if (str_contains($name, 'trigger')) {
            return 'trigger';
        }

        if (str_contains($name, 'transform') || str_contains($name, 'filter')) {
            return 'transform';
        }

        return 'utility';
    }

    private function extractDescription(string|false $docComment, string $name): string
    {
        if (!$docComment) {
            return $this->generateDefaultDescription($name);
        }

        $lines = explode("\n", $docComment);
        $description = '';

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            if (empty($line) || str_starts_with($line, '@')) {
                continue;
            }

            $description .= $line . ' ';
        }

        return trim($description) ?: $this->generateDefaultDescription($name);
    }

    private function generateDefaultDescription(string $name): string
    {
        // Convert camelCase to words
        $words = preg_split('/(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY);
        return 'Node for ' . strtolower(implode(' ', $words));
    }

    private function extractConfigParams(string $className): array
    {
        $required = [];
        $optional = [];

        // Read the class file to analyze getConfigValue calls
        $reflection = new ReflectionClass($className);
        $filename = $reflection->getFileName();
        $content = file_get_contents($filename);

        // Match all getConfigValue calls
        preg_match_all('/getConfigValue\([\'"]([^\'"]*)[\'"]\s*(?:,\s*([^)]+))?\)/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $paramName = $match[1];
            $hasDefault = isset($match[2]);

            $paramInfo = [
                'name' => $paramName,
                'description' => $this->generateParamDescription($paramName),
                'type' => $this->inferParamType($paramName)
            ];

            if ($hasDefault) {
                $paramInfo['default'] = trim($match[2], '\'" ');
                $optional[] = $paramInfo;
            } else {
                $required[] = $paramInfo;
            }
        }

        return [
            'required' => array_values(array_unique($required, SORT_REGULAR)),
            'optional' => array_values(array_unique($optional, SORT_REGULAR))
        ];
    }

    private function generateParamDescription(string $paramName): string
    {
        $descriptions = [
            'prompt' => 'The prompt template for AI processing',
            'location' => 'Geographic location (lat,lon format)',
            'units' => 'Temperature units (imperial or metric)',
            'title' => 'Notification title',
            'priority' => 'Notification priority level',
            'sound' => 'Notification sound',
            'response_format' => 'Expected response format from AI',
            'ai_mode' => 'AI service mode (auto, openai, anthropic)'
        ];

        return $descriptions[$paramName] ?? ucfirst(str_replace('_', ' ', $paramName));
    }

    private function inferParamType(string $paramName): string
    {
        if (str_contains($paramName, 'priority') || str_contains($paramName, 'count') || str_contains($paramName, 'limit')) {
            return 'integer';
        }

        if (str_contains($paramName, 'enabled') || str_contains($paramName, 'active')) {
            return 'boolean';
        }

        return 'string';
    }

    private function extractOutputs(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $filename = $reflection->getFileName();
        $content = file_get_contents($filename);

        $outputs = [
            'data' => 'Main output data',
            'meta' => 'Metadata about the execution',
            'error' => 'Error message if execution failed'
        ];

        // Try to find specific output keys in standardOutput calls
        if (preg_match('/standardOutput\(\[(.*?)\]/s', $content, $match)) {
            preg_match_all('/[\'"]([^\'"]*)[\'"]\s*=>/', $match[1], $keys);

            if (!empty($keys[1])) {
                $specificOutputs = [];
                foreach ($keys[1] as $key) {
                    $specificOutputs[$key] = $this->generateOutputDescription($key);
                }
                $outputs['data_fields'] = $specificOutputs;
            }
        }

        return $outputs;
    }

    private function generateOutputDescription(string $key): string
    {
        $descriptions = [
            'formatted_text' => 'AI-formatted text output',
            'original_data' => 'Original input data',
            'current' => 'Current weather conditions',
            'forecast' => 'Weather forecast data',
            'notification_sent' => 'Whether notification was sent successfully',
            'message_length' => 'Length of the notification message',
            'temperature' => 'Current temperature',
            'humidity' => 'Current humidity percentage'
        ];

        return $descriptions[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
