<?php

namespace Tests\Support;

use App\Services\SystemConfigService;

/** Explicit controller fixtures; real database authority is covered by TableOnlyLlmRoutingTest. */
trait UsesLlmRoutingBindings
{
    protected function setUpUsesLlmRoutingBindings(): void
    {
        $scorecard = config('ollama_eval.k3_05');
        $scorecard['comparator_model'] = 'qwen3:4b';
        $bindings = [
            'mhc08_k3' => ['instance_id' => 'fixture_kimi', 'model' => 'kimi-k3-colibri', 'host' => '127.0.0.1', 'port' => 8001, 'host_affinity' => 'fixture_host', 'production_remote' => '127.0.0.2'],
            'qwen_bench' => ['instance_id' => 'fixture_qwen', 'model' => 'qwen3.8-27b-q4_k_m', 'host' => '127.0.0.1', 'port' => 11438, 'target_ref' => 'provider:fixture_qwen/qwen3.8-27b-q4_k_m'],
            'bitnet_canary' => ['instance_id' => 'fixture_bitnet', 'model' => 'bitnet-b1.58-2b-4t-i2s', 'host' => '127.0.0.1', 'port' => 11435],
            'ollama_coexistence' => ['instance_id' => 'ollama_secondary', 'model' => 'qwen3:8b', 'base_url' => 'http://127.0.0.1:11434', 'qwen_instance_id' => 'fixture_qwen', 'admission_group' => 'fixture_qwen_ollama'],
            'kimi_scorecard' => $scorecard,
            'knowledge_graph' => ['aliases' => ['primary' => ['ollama_primary'], 'secondary' => ['ollama_secondary'], 'any' => null], 'default_alias' => 'primary', 'managed' => ['instance_id' => 'ollama_secondary', 'model' => 'qwen3:8b', 'target_id' => 'fixture_ollama_target', 'model_role' => 'standard', 'engine' => 'ollama']],
            'controller_canary' => ['targets' => [
                'fixture_qwen' => ['provider_instance_id' => 'fixture_qwen', 'model' => 'qwen3.8-27b-q4_k_m', 'engine' => 'llama.cpp', 'role' => 'deep_offline', 'adapter' => 'qwen'],
                'fixture_ollama_target' => ['provider_instance_id' => 'ollama_secondary', 'model' => 'qwen3:8b', 'engine' => 'ollama', 'role' => 'standard', 'adapter' => 'ollama'],
                'fixture_bitnet' => ['provider_instance_id' => 'fixture_bitnet', 'model' => 'bitnet-b1.58-2b-4t-i2s', 'engine' => 'bitnet.cpp', 'role' => 'privacy_deny_prefilter', 'adapter' => 'bitnet'],
            ]],
        ];
        $service = new class($bindings) extends SystemConfigService
        {
            public function __construct(private array $bindings) {}

            public function isDedicatedLlmTarget(string $target): bool
            {
                return $target === $this->bindings['mhc08_k3']['instance_id'];
            }

            public function llmRoutingBinding(string $name): array
            {
                if ($name === 'controller_transport') {
                    return ['base_url' => config('model_host_controller.url'),
                        'expected_host_id' => config('model_host_controller.expected_host_id'),
                        'allowed_hosts' => config('model_host_controller.allowed_hosts'),
                        'port' => config('model_host_controller.allowed_port')];
                }

                return $this->bindings[$name] ?? throw new \RuntimeException('Missing explicit test route binding: '.$name);
            }
        };
        $this->app->instance(SystemConfigService::class, $service);
    }
}
