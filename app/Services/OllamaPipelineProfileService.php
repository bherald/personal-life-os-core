<?php

namespace App\Services;

use InvalidArgumentException;

class OllamaPipelineProfileService
{
    public function getTaskProfile(string $taskClass): array
    {
        $defaults = config('ollama_pipeline.defaults', []);
        $task = config("ollama_pipeline.tasks.{$taskClass}");

        if (! is_array($task)) {
            throw new InvalidArgumentException("Unknown Ollama pipeline task: {$taskClass}");
        }

        $profile = array_merge($defaults, $task, ['task_class' => $taskClass]);
        $profile['local_only_safe'] = ($profile['route'] ?? null) === 'local_first';
        $profile['human_gate_required'] = ($profile['requires_human_review'] ?? true)
            || ($profile['route'] ?? null) !== 'local_first';

        return $profile;
    }

    public function getAllTaskProfiles(): array
    {
        $tasks = config('ollama_pipeline.tasks', []);

        $profiles = [];
        foreach (array_keys($tasks) as $taskClass) {
            $profiles[$taskClass] = $this->getTaskProfile($taskClass);
        }

        return $profiles;
    }

    public function getTasksForRoute(string $route): array
    {
        return collect($this->getAllTaskProfiles())
            ->filter(fn (array $profile): bool => ($profile['route'] ?? null) === $route)
            ->keys()
            ->values()
            ->all();
    }

    public function requiresHumanReview(string $taskClass): bool
    {
        return (bool) ($this->getTaskProfile($taskClass)['human_gate_required'] ?? true);
    }

    public function getStageSequence(string $taskClass): array
    {
        return $this->getTaskProfile($taskClass)['stages'] ?? [];
    }
}
