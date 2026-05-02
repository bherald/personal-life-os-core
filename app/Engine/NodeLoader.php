<?php

namespace App\Engine;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Exception;

class NodeLoader
{
    private array $nodeCache = [];
    private string $nodesPath;

    public function __construct()
    {
        $this->nodesPath = app_path('Nodes');
        $this->scanNodesOnBoot();
    }

    public function loadNode(string $nodeType, array $config = []): object
    {
        // Check if nodeType is already a fully-qualified class name
        if (str_starts_with($nodeType, 'App\\Nodes\\')) {
            $className = $nodeType;
        } else {
            $className = "App\\Nodes\\{$nodeType}";
        }

        if (!class_exists($className)) {
            throw new Exception("Node class not found: {$className}");
        }

        // Clear opcache for hot-reload in development
        if (app()->environment('local') && function_exists('opcache_invalidate')) {
            // Extract relative path for opcache invalidation
            $relativePath = str_starts_with($nodeType, 'App\\Nodes\\')
                ? str_replace('App\\Nodes\\', '', $nodeType)
                : $nodeType;
            $filePath = $this->nodesPath . "/" . str_replace('\\', '/', $relativePath) . ".php";
            if (file_exists($filePath)) {
                opcache_invalidate($filePath, true);
            }
        }

        return new $className($config);
    }

    private function scanNodesOnBoot(): void
    {
        if (!File::exists($this->nodesPath)) {
            Log::warning('Nodes directory does not exist', ['path' => $this->nodesPath]);
            return;
        }

        $files = File::files($this->nodesPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $nodeType = $file->getFilenameWithoutExtension();
                $this->nodeCache[$nodeType] = $file->getPathname();
            }
        }

        Log::debug('Scanned nodes on boot', ['count' => count($this->nodeCache)]);
    }

    public function getAvailableNodes(): array
    {
        return array_keys($this->nodeCache);
    }

    public function clearCache(): void
    {
        $this->nodeCache = [];
        $this->scanNodesOnBoot();
    }
}
