<?php

namespace App\Services;

/**
 * GR-13: Causal Edge Types — classify KG predicates and assign search weights.
 *
 * In knowledge graph search, causal relationships (parent_of, born_in,
 * caused_by) carry more semantic weight than associative ones (related_to,
 * associated_with). This classifier categorises each predicate into one of
 * four edge types and returns a weight multiplier for graph scoring.
 *
 * All methods are pure (no LLM, no DB) — zero latency overhead.
 *
 * Reference: CausalGraph2LLM (ACL 2025)
 */
class CausalEdgeClassifier
{
    /** Edge type constants */
    public const TYPE_CAUSAL       = 'causal';
    public const TYPE_HIERARCHICAL = 'hierarchical';
    public const TYPE_TEMPORAL     = 'temporal';
    public const TYPE_ASSOCIATIVE  = 'associative';

    /** Weight multipliers per edge type */
    public const WEIGHT_CAUSAL       = 2.0;
    public const WEIGHT_HIERARCHICAL = 1.5;
    public const WEIGHT_TEMPORAL     = 1.2;
    public const WEIGHT_ASSOCIATIVE  = 1.0;

    /**
     * Predicates that express direct cause-effect or generative relationships.
     * Genealogy-heavy: parent_of, child_of, born_in, died_in are causal
     * because they represent life events that create/end entities.
     */
    private const CAUSAL_PREDICATES = [
        'caused_by', 'causes', 'led_to', 'resulted_in', 'produced',
        'parent_of', 'child_of', 'born_in', 'died_in',
        'founded_by', 'created_by', 'built_by', 'destroyed_by',
        'married_to', 'divorced_from',
        'emigrated_to', 'immigrated_from',
        'killed_by', 'cured_by',
    ];

    /** Predicates that express containment, taxonomy, or structural hierarchy. */
    private const HIERARCHICAL_PREDICATES = [
        'part_of', 'subclass_of', 'instance_of', 'member_of',
        'belongs_to', 'contained_in', 'subdivision_of',
        'reports_to', 'manages', 'employed_by', 'works_at',
        'studied_at', 'enrolled_in',
    ];

    /** Predicates that express temporal ordering or co-occurrence. */
    private const TEMPORAL_PREDICATES = [
        'occurred_on', 'happened_at', 'occurred_during',
        'preceded_by', 'followed_by', 'contemporaneous_with',
        'lived_during', 'active_during',
    ];

    // =========================================================================
    // Classification (pure)
    // =========================================================================

    /**
     * Classify a predicate into an edge type.
     * Falls back to 'associative' for unknown predicates.
     * Pure — no I/O.
     */
    public function classify(string $predicate): string
    {
        $normalized = strtolower(trim($predicate));

        if (in_array($normalized, self::CAUSAL_PREDICATES, true)) {
            return self::TYPE_CAUSAL;
        }
        if (in_array($normalized, self::HIERARCHICAL_PREDICATES, true)) {
            return self::TYPE_HIERARCHICAL;
        }
        if (in_array($normalized, self::TEMPORAL_PREDICATES, true)) {
            return self::TYPE_TEMPORAL;
        }

        // Heuristic: predicates containing causal keywords
        if (preg_match('/(?:caus|result|lead|produc|creat|generat|born|died|found|built|kill)/', $normalized)) {
            return self::TYPE_CAUSAL;
        }

        return self::TYPE_ASSOCIATIVE;
    }

    /**
     * Get the search weight multiplier for an edge type.
     * Pure — no I/O.
     */
    public function getWeight(string $edgeType): float
    {
        return match ($edgeType) {
            self::TYPE_CAUSAL       => self::WEIGHT_CAUSAL,
            self::TYPE_HIERARCHICAL => self::WEIGHT_HIERARCHICAL,
            self::TYPE_TEMPORAL     => self::WEIGHT_TEMPORAL,
            default                 => self::WEIGHT_ASSOCIATIVE,
        };
    }

    /**
     * Get the weight for a predicate directly (classify + weight in one call).
     * Pure — no I/O.
     */
    public function getPredicateWeight(string $predicate): float
    {
        return $this->getWeight($this->classify($predicate));
    }

    /**
     * Classify multiple predicates and return a map of predicate → type.
     * Pure — no I/O.
     *
     * @param  string[] $predicates
     * @return array<string, string>  predicate → edge type
     */
    public function classifyBatch(array $predicates): array
    {
        $result = [];
        foreach ($predicates as $p) {
            $result[$p] = $this->classify($p);
        }
        return $result;
    }

    /**
     * Return all known causal predicates.
     * Pure — no I/O.
     *
     * @return string[]
     */
    public function getCausalPredicates(): array
    {
        return self::CAUSAL_PREDICATES;
    }

    /**
     * Return all known hierarchical predicates.
     * Pure — no I/O.
     *
     * @return string[]
     */
    public function getHierarchicalPredicates(): array
    {
        return self::HIERARCHICAL_PREDICATES;
    }

    /**
     * Return all known temporal predicates.
     * Pure — no I/O.
     *
     * @return string[]
     */
    public function getTemporalPredicates(): array
    {
        return self::TEMPORAL_PREDICATES;
    }
}
