<?php

namespace App\Services;

use RuntimeException;

class UniversalResearchRuntimePolicyService
{
    public const CONFIG_KEY = 'research.universal_orchestrator_runtime_policy';

    public const SCHEMA = 'plos.research_universal_orchestrator_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *   gather:array{
     *     max_articles_per_source:int,
     *     search_timeout_seconds:int,
     *     article_timeout_seconds:int,
     *     source_delay_ms:int,
     *     article_delay_ms:int,
     *     min_link_text_chars:int,
     *     min_article_content_chars:int
     *   },
     *   extraction:array{min_content_chars:int,max_content_chars:int,max_facts_per_source:int,max_tokens:int},
     *   synthesis:array{max_facts:int,max_sources:int,max_words:int,max_tokens:int},
     *   enhanced_knowledge:array{max_existing_facts:int,max_facts:int,max_tokens:int}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Universal research-orchestrator runtime policy is unavailable.');
        }

        return [
            'gather' => $this->gatherPolicy($policy),
            'extraction' => $this->extractionPolicy($policy),
            'synthesis' => $this->synthesisPolicy($policy),
            'enhanced_knowledge' => $this->enhancedKnowledgePolicy($policy),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function gatherPolicy(array $policy): array
    {
        $gather = $this->section($policy, 'gather');

        return [
            'max_articles_per_source' => $this->boundedInt($gather, 'max_articles_per_source', 0, 20, 'gather.'),
            'search_timeout_seconds' => $this->boundedInt($gather, 'search_timeout_seconds', 1, 300, 'gather.'),
            'article_timeout_seconds' => $this->boundedInt($gather, 'article_timeout_seconds', 1, 300, 'gather.'),
            'source_delay_ms' => $this->boundedInt($gather, 'source_delay_ms', 0, 10000, 'gather.'),
            'article_delay_ms' => $this->boundedInt($gather, 'article_delay_ms', 0, 10000, 'gather.'),
            'min_link_text_chars' => $this->boundedInt($gather, 'min_link_text_chars', 1, 500, 'gather.'),
            'min_article_content_chars' => $this->boundedInt($gather, 'min_article_content_chars', 1, 1000000, 'gather.'),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function extractionPolicy(array $policy): array
    {
        $extraction = $this->section($policy, 'extraction');

        return [
            'min_content_chars' => $this->boundedInt($extraction, 'min_content_chars', 1, 1000000, 'extraction.'),
            'max_content_chars' => $this->boundedInt($extraction, 'max_content_chars', 100, 100000, 'extraction.'),
            'max_facts_per_source' => $this->boundedInt($extraction, 'max_facts_per_source', 1, 50, 'extraction.'),
            'max_tokens' => $this->boundedInt($extraction, 'max_tokens', 1, 8192, 'extraction.'),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function synthesisPolicy(array $policy): array
    {
        $synthesis = $this->section($policy, 'synthesis');

        return [
            'max_facts' => $this->boundedInt($synthesis, 'max_facts', 1, 200, 'synthesis.'),
            'max_sources' => $this->boundedInt($synthesis, 'max_sources', 1, 100, 'synthesis.'),
            'max_words' => $this->boundedInt($synthesis, 'max_words', 50, 5000, 'synthesis.'),
            'max_tokens' => $this->boundedInt($synthesis, 'max_tokens', 1, 16384, 'synthesis.'),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function enhancedKnowledgePolicy(array $policy): array
    {
        $enhanced = $this->section($policy, 'enhanced_knowledge');

        return [
            'max_existing_facts' => $this->boundedInt($enhanced, 'max_existing_facts', 0, 100, 'enhanced_knowledge.'),
            'max_facts' => $this->boundedInt($enhanced, 'max_facts', 1, 100, 'enhanced_knowledge.'),
            'max_tokens' => $this->boundedInt($enhanced, 'max_tokens', 1, 16384, 'enhanced_knowledge.'),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function section(array $policy, string $key): array
    {
        $section = $policy[$key] ?? null;
        if (! is_array($section)) {
            throw new RuntimeException("Universal research-orchestrator runtime policy has invalid {$key}.");
        }

        return $section;
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum, string $prefix): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("Universal research-orchestrator runtime policy has invalid {$prefix}{$key}.");
        }

        return $value;
    }
}
