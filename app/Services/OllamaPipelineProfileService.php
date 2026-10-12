<?php

namespace App\Services;

use App\Support\PromptIntegrityPolicy;
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
        $profile['enabled'] = true;
        $profile['structured_output_enforced'] = (new OllamaStructuredOutputSchemaService)
            ->has($profile['output_schema'] ?? null);

        if (! $this->compressionEnabled()) {
            $compressionStages = $this->compressionStageNames();
            $originalStages = array_values($profile['stages'] ?? []);
            $activeStages = array_values(array_filter(
                $originalStages,
                static fn (string $stage): bool => ! in_array($stage, $compressionStages, true)
            ));

            $profile['compression_enabled'] = false;

            if ($activeStages !== $originalStages) {
                $profile['compression_disabled'] = true;
                $profile['original_stages'] = $originalStages;
                $profile['stages'] = $activeStages;
            }

            if (in_array($taskClass, $this->compressionTaskClasses(), true)) {
                $profile['enabled'] = false;
                $profile['disabled_reason'] = PromptIntegrityPolicy::DISABLED_REASON;
                $profile['compression_disabled'] = true;
            }
        } else {
            $profile['compression_enabled'] = true;
        }

        $profile['local_only_safe'] = ($profile['route'] ?? null) === 'local_first';
        $profile['human_gate_required'] = ($profile['requires_human_review'] ?? true)
            || ($profile['route'] ?? null) !== 'local_first'
            || ! ($profile['enabled'] ?? true);

        return $profile;
    }

    public function getAllTaskProfiles(bool $includeDisabled = false): array
    {
        $tasks = config('ollama_pipeline.tasks', []);

        $profiles = [];
        foreach (array_keys($tasks) as $taskClass) {
            $profile = $this->getTaskProfile($taskClass);
            if ($includeDisabled || ($profile['enabled'] ?? true)) {
                $profiles[$taskClass] = $profile;
            }
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

    public function compressionEnabled(): bool
    {
        return PromptIntegrityPolicy::allowsLossyTransforms();
    }

    private function compressionStageNames(): array
    {
        return array_values(array_filter(
            config('ollama_pipeline.compression_stage_names', ['compress']),
            static fn ($stage): bool => is_string($stage) && $stage !== ''
        ));
    }

    private function compressionTaskClasses(): array
    {
        return array_values(array_filter(
            config('ollama_pipeline.compression_task_classes', []),
            static fn ($taskClass): bool => is_string($taskClass) && $taskClass !== ''
        ));
    }
}
