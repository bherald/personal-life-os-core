<?php

namespace App\Services;

class AgentOutputQualityGateService
{
    private const GENEALOGY_REVIEW_TYPES = [
        'genealogy_finding',
        'genealogy_merge',
        'genealogy_source',
        'genealogy_media',
    ];

    private const SOURCE_CHANGE_TYPES = [
        'source_add',
        'source_create',
        'citation_add',
        'external_record_link',
        'clipping_link',
        'media_link',
    ];

    private const MATERIAL_GENEALOGY_CHANGE_TYPES = [
        'fact_update',
        'event_add',
        'relationship_add',
        'residence_add',
        'family_event_update',
        'source_add',
        'source_create',
        'external_record_link',
        'clipping_link',
        'media_link',
        'media_metadata_update',
    ];

    private const LOCATOR_KEYS = [
        'source_url',
        'source_urls',
        'url',
        'urls',
        'repository',
        'provider',
        'file_id',
        'source_id',
        'citation_id',
        'record_id',
        'memorial_id',
        'attachment_id',
        'checksum',
        'commit',
        'command_run_id',
        'log_window',
        'page',
        'line',
        'timestamp',
    ];

    public function enrichReviewDetails(array $params, array $details): array
    {
        $enriched = $details;

        if (array_key_exists('quality_gate', $details) && $details['quality_gate'] !== null) {
            $enriched = $this->preserveAgentReportedQualityGate($enriched, $details['quality_gate']);
        }

        $classificationDetails = $enriched;
        unset(
            $classificationDetails['quality_gate'],
            $classificationDetails['agent_reported_quality_gate'],
            $classificationDetails['agent_reported_quality_gate_history'],
        );

        $enriched['quality_gate'] = $this->classifyReviewOutput($params, $classificationDetails);

        return $enriched;
    }

    private function preserveAgentReportedQualityGate(array $details, mixed $qualityGate): array
    {
        if (! array_key_exists('agent_reported_quality_gate', $details)) {
            $details['agent_reported_quality_gate'] = $qualityGate;

            return $details;
        }

        if ($details['agent_reported_quality_gate'] === $qualityGate) {
            return $details;
        }

        $history = $this->qualityGateHistory($details['agent_reported_quality_gate_history'] ?? null);
        if (! in_array($qualityGate, $history, true)) {
            $history[] = $qualityGate;
        }
        $details['agent_reported_quality_gate_history'] = $history;

        return $details;
    }

    /**
     * @return array<int, mixed>
     */
    private function qualityGateHistory(mixed $history): array
    {
        if ($history === null) {
            return [];
        }

        if (! is_array($history)) {
            return [$history];
        }

        return array_is_list($history) ? $history : [$history];
    }

    public function classifyReviewOutput(array $params, array $details = []): array
    {
        $reviewType = strtolower((string) ($params['review_type'] ?? 'finding'));
        $findingType = strtolower((string) ($params['finding_type'] ?? ''));
        $agentId = strtolower((string) ($params['agent_id'] ?? ''));
        $confidence = is_numeric($params['confidence'] ?? null) ? (float) $params['confidence'] : null;
        $text = $this->contextText($params, $details);

        $outputSurface = $this->classifyOutputSurface($reviewType, $findingType, $text, $details);
        $publicBound = $this->classifyPublicBound($outputSurface, $text, $details);
        $privateDataPossible = $this->classifyPrivateDataPossible($text, $reviewType, $agentId);
        $providerBoundaryStatus = $this->classifyProviderBoundaryStatus($text, $details);
        $livingPersonStatus = $this->classifyLivingPersonStatus($text, $reviewType, $agentId);

        $hardFailReasons = [];
        if ($privateDataPossible === 'yes') {
            $hardFailReasons[] = 'private_data_marker_detected';
        }
        if ($this->hasUnsupportedSourceOrCitation($details)) {
            $hardFailReasons[] = 'unsupported_source_or_citation';
        }
        if ($this->isGenealogyReview($reviewType, $findingType, $agentId) && $this->hasMaterialGenealogyFindingWithoutEvidence($details)) {
            $hardFailReasons[] = 'genealogy_finding_missing_evidence';
        }
        if ($outputSurface === 'public_doc' && $this->hasUnsupportedPublicDocClaim($text)) {
            $hardFailReasons[] = 'public_doc_unsupported_release_claim';
        }
        if ($providerBoundaryStatus === 'manual_browser' && $this->manualProviderPresentedAsAutomatedEvidence($text)) {
            $hardFailReasons[] = 'manual_browser_provider_as_automated_evidence';
        }
        if ($confidence !== null && $confidence >= 0.80 && $this->isGenealogyReview($reviewType, $findingType, $agentId) && $this->hasWeakGenealogyEvidence($details)) {
            $hardFailReasons[] = 'high_confidence_weak_genealogy_evidence';
        }
        if ($outputSurface === 'public_doc' && in_array($privateDataPossible, ['yes', 'unknown'], true)) {
            $hardFailReasons[] = 'public_doc_private_boundary_not_cleared';
        }

        $hardFailReasons = array_values(array_unique($hardFailReasons));
        $privacyReviewStatus = $this->classifyPrivacyReviewStatus($privateDataPossible, $livingPersonStatus, $providerBoundaryStatus);
        $publicExportStatus = $this->classifyPublicExportStatus($outputSurface, $publicBound, $privateDataPossible, $hardFailReasons);
        $riskLabel = $this->classifyRiskLabel($outputSurface, $privacyReviewStatus, $hardFailReasons);

        return [
            'output_surface' => $outputSurface,
            'decision_type' => $this->classifyDecisionType($params),
            'risk_label' => $riskLabel,
            'public_bound' => $publicBound,
            'private_data_possible' => $privateDataPossible,
            'rollback_required' => $this->classifyRollbackRequired($outputSurface),
            'privacy_review_status' => $privacyReviewStatus,
            'public_export_status' => $publicExportStatus,
            'living_person_status' => $livingPersonStatus,
            'provider_boundary_status' => $providerBoundaryStatus,
            'approval_worthy_score' => $this->score($publicBound, $privateDataPossible, $privacyReviewStatus, $providerBoundaryStatus, $hardFailReasons),
            'hard_fail_reasons' => $hardFailReasons,
        ];
    }

    private function classifyOutputSurface(string $reviewType, string $findingType, string $text, array $details): string
    {
        if (preg_match('/\b(public release|public export|public[-_ ]docs?|docs\/public|readme|installer|install docs?)\b/i', $text)) {
            return 'public_doc';
        }

        if (preg_match('/\b(diff|touched_files|tests? run|pull request|composer test|php artisan test|npm run build)\b/i', $text)) {
            return 'code_change';
        }

        if (preg_match('/\b(\.env|config_change|migration|database schema|feature flag|system_configs?)\b/i', $text)) {
            return 'config_change';
        }

        if (preg_match('/\b(deploy|production|queue|horizon|scheduler|redis|mysql|postgres|nginx|systemctl)\b/i', $text)) {
            return 'ops_action';
        }

        if ($this->containsProposalChanges($details) || str_starts_with($reviewType, 'genealogy_') || str_starts_with($findingType, 'genealogy_')) {
            return 'operator_review';
        }

        if (preg_match('/\b(private note|planning note|private doc|docs\/planning)\b/i', $text)) {
            return 'private_doc';
        }

        if (preg_match('/\b(research note|source analysis|finding summary)\b/i', $text)) {
            return 'research_note';
        }

        if (preg_match('/\b(generated asset|image|media file|sprite|thumbnail)\b/i', $text)) {
            return 'generated_asset';
        }

        return 'operator_review';
    }

    private function classifyPublicBound(string $outputSurface, string $text, array $details): string
    {
        $explicit = $details['public_bound'] ?? $details['public_export_status'] ?? null;
        if (is_bool($explicit)) {
            return $explicit ? 'yes' : 'no';
        }
        if (is_string($explicit) && in_array(strtolower($explicit), ['yes', 'no', 'unknown'], true)) {
            return strtolower($explicit);
        }

        if ($outputSurface === 'public_doc') {
            return 'yes';
        }

        if (preg_match('/\b(public release|public export|docs\/public|public github|readme)\b/i', $text)) {
            return 'yes';
        }

        if (preg_match('/\b(private only|operator only|docs\/planning|internal note|review queue)\b/i', $text)) {
            return 'no';
        }

        return 'unknown';
    }

    private function classifyPrivateDataPossible(string $text, string $reviewType, string $agentId): string
    {
        if (preg_match('/(\[REDACTED(?:_[A-Z0-9]+)?\]|\b(password|passwd|pwd|api[_-]?key|apikey|secret|token|bearer|session[_-]?cookie|ssh[_-]?key|webhook)\b)/i', $text)) {
            return 'yes';
        }

        if (preg_match('#(/home/[A-Za-z0-9._-]+|/Users/[A-Za-z0-9._-]+|C:\\\\Users\\\\|/var/www/|/srv/|/mnt/|/tmp/|storage/app/private|\.env\b)#i', $text)) {
            return 'yes';
        }

        if (preg_match('/\b(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}|localhost:\d+)\b/i', $text)) {
            return 'yes';
        }

        if ($this->isGenealogyReview($reviewType, '', $agentId)) {
            return 'unknown';
        }

        return 'no';
    }

    private function classifyProviderBoundaryStatus(string $text, array $details): string
    {
        $explicit = $details['provider_boundary_status'] ?? null;
        if (is_string($explicit) && in_array($explicit, ['automated_public', 'manual_browser', 'private_opt_in', 'disabled_private_opt_in', 'not_applicable', 'unknown'], true)) {
            return $explicit;
        }

        if (preg_match('/\b(ancestry|myheritage|newspapers\.com|fold3|findmypast|familysearch|browser-only|manual browser|login session)\b/i', $text)) {
            return 'manual_browser';
        }

        if (preg_match('/\b(nextcloud|google drive|dropbox|box connector|gmail|slack|private connector|oauth)\b/i', $text)) {
            return 'private_opt_in';
        }

        if (preg_match('/\b(nara\.gov|archives\.gov|loc\.gov|archive\.org|wikipedia\.org|wikidata\.org|openstreetmap\.org)\b/i', $text)) {
            return 'automated_public';
        }

        if (! $this->containsUrl($text)) {
            return 'not_applicable';
        }

        return 'unknown';
    }

    private function classifyLivingPersonStatus(string $text, string $reviewType, string $agentId): string
    {
        if (! $this->isGenealogyReview($reviewType, '', $agentId)) {
            return 'not_applicable';
        }

        if (preg_match('/\b(living|possibly living|born after 1926|minor child|private relationship)\b/i', $text)) {
            return 'possible';
        }

        if (preg_match('/\b(deceased|died|death date|burial|obituary)\b/i', $text)) {
            return 'none_detected';
        }

        return 'unknown';
    }

    private function classifyPrivacyReviewStatus(string $privateDataPossible, string $livingPersonStatus, string $providerBoundaryStatus): string
    {
        if ($privateDataPossible === 'yes') {
            return 'failed';
        }

        if (in_array($livingPersonStatus, ['possible', 'confirmed', 'unknown'], true) || in_array($providerBoundaryStatus, ['manual_browser', 'private_opt_in', 'unknown'], true)) {
            return 'warning';
        }

        return 'passed';
    }

    private function classifyPublicExportStatus(string $outputSurface, string $publicBound, string $privateDataPossible, array $hardFailReasons): string
    {
        if ($outputSurface !== 'public_doc' && $publicBound === 'no') {
            return 'private_only';
        }

        if ($publicBound === 'unknown') {
            return 'unknown';
        }

        if ($privateDataPossible === 'yes' || $hardFailReasons !== []) {
            return 'needs_redaction';
        }

        return $publicBound === 'yes' ? 'safe' : 'private_only';
    }

    private function classifyRiskLabel(string $outputSurface, string $privacyReviewStatus, array $hardFailReasons): string
    {
        if ($hardFailReasons !== [] || $privacyReviewStatus === 'failed') {
            return 'blocker';
        }

        if (in_array($outputSurface, ['config_change', 'data_change', 'ops_action'], true)) {
            return 'high';
        }

        if (in_array($outputSurface, ['operator_review', 'code_change', 'public_doc'], true)) {
            return 'medium';
        }

        if (in_array($outputSurface, ['private_doc', 'research_note'], true)) {
            return 'docs_only';
        }

        return 'low';
    }

    private function classifyDecisionType(array $params): string
    {
        $decision = strtolower((string) ($params['decision_type'] ?? $params['decision'] ?? 'unknown'));

        return in_array($decision, ['approve', 'reject', 'revise', 'defer', 'publish', 'merge', 'deploy', 'archive', 'ignore', 'unknown'], true)
            ? $decision
            : 'unknown';
    }

    private function classifyRollbackRequired(string $outputSurface): string
    {
        if (in_array($outputSurface, ['code_change', 'config_change', 'data_change', 'ops_action'], true)) {
            return 'yes';
        }

        if (in_array($outputSurface, ['private_doc', 'research_note', 'generated_asset'], true)) {
            return 'no';
        }

        return 'unknown';
    }

    private function score(string $publicBound, string $privateDataPossible, string $privacyReviewStatus, string $providerBoundaryStatus, array $hardFailReasons): int
    {
        $score = 100;

        if ($publicBound === 'unknown') {
            $score -= 8;
        }

        $score -= match ($privateDataPossible) {
            'yes' => 25,
            'unknown' => 8,
            default => 0,
        };

        $score -= match ($privacyReviewStatus) {
            'failed' => 35,
            'warning' => 10,
            default => 0,
        };

        $score -= match ($providerBoundaryStatus) {
            'manual_browser' => 20,
            'private_opt_in', 'disabled_private_opt_in' => 15,
            'unknown' => 5,
            default => 0,
        };

        foreach ($hardFailReasons as $reason) {
            $score -= match ($reason) {
                'private_data_marker_detected' => 20,
                'genealogy_finding_missing_evidence' => 30,
                'unsupported_source_or_citation' => 20,
                'public_doc_unsupported_release_claim' => 30,
                'manual_browser_provider_as_automated_evidence' => 25,
                'high_confidence_weak_genealogy_evidence' => 20,
                'public_doc_private_boundary_not_cleared' => 20,
                default => 10,
            };
        }

        if ($hardFailReasons !== []) {
            $score = min($score, 49);
        }

        if (in_array('private_data_marker_detected', $hardFailReasons, true)) {
            $score = min($score, 25);
        }

        return max(0, min(100, $score));
    }

    private function hasUnsupportedPublicDocClaim(string $text): bool
    {
        return (bool) preg_match('/\b(production[- ]ready|turnkey install|complete license review|fully license reviewed|gpu support|security guarantee|secure by default|tests? passed|ci passed|all checks passed)\b/i', $text);
    }

    private function manualProviderPresentedAsAutomatedEvidence(string $text): bool
    {
        return (bool) preg_match('/\b(automated|auto-collected|scraped|crawled|api collected|batch imported)\b/i', $text);
    }

    private function hasMaterialGenealogyFindingWithoutEvidence(array $details): bool
    {
        foreach ($this->proposalItems($details) as $item) {
            $changeType = strtolower((string) ($item['change_type'] ?? $item['relationship_type'] ?? ''));
            if (! in_array($changeType, self::MATERIAL_GENEALOGY_CHANGE_TYPES, true)) {
                continue;
            }

            if (! $this->hasEvidence($item)) {
                return true;
            }
        }

        return false;
    }

    private function hasWeakGenealogyEvidence(array $details): bool
    {
        $items = $this->proposalItems($details);
        if ($items === []) {
            return true;
        }

        foreach ($items as $item) {
            $changeType = strtolower((string) ($item['change_type'] ?? $item['relationship_type'] ?? ''));
            if (in_array($changeType, self::MATERIAL_GENEALOGY_CHANGE_TYPES, true) && ! $this->hasStrongEvidence($item)) {
                return true;
            }
        }

        return false;
    }

    private function hasUnsupportedSourceOrCitation(array $details): bool
    {
        foreach ($this->proposalItems($details) as $item) {
            $changeType = strtolower((string) ($item['change_type'] ?? ''));
            if (! in_array($changeType, self::SOURCE_CHANGE_TYPES, true) && ! $this->hasSourceLikeKeys($item)) {
                continue;
            }

            if (! $this->hasRetrievableLocator($item) || $this->hasOnlyGenericEvidenceSources($item)) {
                return true;
            }
        }

        return false;
    }

    private function hasEvidence(array $item): bool
    {
        return $this->hasMeaningfulText($item['evidence_summary'] ?? null)
            || $this->hasRetrievableLocator($item)
            || $this->hasMeaningfulText($item['source_citation'] ?? null)
            || $this->hasMeaningfulText($item['extracted_text'] ?? null);
    }

    private function hasStrongEvidence(array $item): bool
    {
        return $this->hasMeaningfulText($item['evidence_summary'] ?? null)
            && ($this->hasRetrievableLocator($item) || $this->hasMeaningfulText($item['source_citation'] ?? null));
    }

    private function hasRetrievableLocator(array $item): bool
    {
        foreach (self::LOCATOR_KEYS as $key) {
            if ($this->valueLooksLikeLocator($item[$key] ?? null)) {
                return true;
            }
        }

        return $this->evidenceSourcesAreSupported($item['evidence_sources'] ?? null);
    }

    private function evidenceSourcesAreSupported(mixed $value): bool
    {
        $sources = is_array($value) ? $value : [$value];
        foreach ($sources as $source) {
            if ($this->valueLooksLikeLocator($source) && ! $this->isGenericSourceLabel((string) $source)) {
                return true;
            }
        }

        return false;
    }

    private function valueLooksLikeLocator(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->valueLooksLikeLocator($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $text = trim((string) $value);
        if (strlen($text) < 4 || $this->isGenericSourceLabel($text)) {
            return false;
        }

        return (bool) preg_match('/(https?:\/\/|[a-z0-9.-]+\.[a-z]{2,}|record|source|citation|archive|census|certificate|memorial|file|id[:# -]?\d+)/i', $text);
    }

    private function hasOnlyGenericEvidenceSources(array $item): bool
    {
        if (! array_key_exists('evidence_sources', $item)) {
            return false;
        }

        $sources = is_array($item['evidence_sources']) ? $item['evidence_sources'] : [$item['evidence_sources']];
        if ($sources === []) {
            return true;
        }

        foreach ($sources as $source) {
            if (! is_scalar($source) || $this->isGenericSourceLabel((string) $source)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function isGenericSourceLabel(string $value): bool
    {
        return (bool) preg_match('/^(src|source|sources?|unknown|n\/a|none|manual search|browser search|source_search_all|update_search_coverage)$/i', trim($value));
    }

    private function hasSourceLikeKeys(array $item): bool
    {
        foreach (['source', 'sources', 'citation', 'citations', 'evidence_sources'] as $key) {
            if (array_key_exists($key, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposalItems(array $details): array
    {
        $items = [];

        foreach (['proposals', 'proposed_changes', 'changes', 'citations', 'sources'] as $key) {
            if (! is_array($details[$key] ?? null)) {
                continue;
            }

            foreach ($details[$key] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        if ($items === [] && $this->containsProposalChanges($details)) {
            $items[] = $details;
        }

        return $items;
    }

    private function containsProposalChanges(array $details): bool
    {
        if (array_key_exists('change_type', $details) || array_key_exists('proposals', $details) || array_key_exists('proposed_changes', $details)) {
            return true;
        }

        return false;
    }

    private function isGenealogyReview(string $reviewType, string $findingType, string $agentId): bool
    {
        return in_array($reviewType, self::GENEALOGY_REVIEW_TYPES, true)
            || str_starts_with($reviewType, 'genealogy_')
            || str_starts_with($findingType, 'genealogy_')
            || str_starts_with($agentId, 'genealogy-')
            || str_starts_with($agentId, 'genealogy_');
    }

    private function hasMeaningfulText(mixed $value): bool
    {
        return is_scalar($value) && strlen(trim((string) $value)) >= 8 && ! $this->isGenericSourceLabel((string) $value);
    }

    private function containsUrl(string $text): bool
    {
        return (bool) preg_match('/https?:\/\/|[a-z0-9.-]+\.[a-z]{2,}/i', $text);
    }

    private function contextText(array $params, array $details): string
    {
        $parts = [
            $params['agent_id'] ?? '',
            $params['review_type'] ?? '',
            $params['finding_type'] ?? '',
            $params['title'] ?? '',
            $params['summary'] ?? '',
        ];

        $this->appendScalarValues($details, $parts);

        return implode(' ', array_filter(array_map(static fn ($part) => is_scalar($part) ? (string) $part : '', $parts)));
    }

    private function appendScalarValues(mixed $value, array &$parts): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_scalar($key)) {
                    $parts[] = (string) $key;
                }
                $this->appendScalarValues($item, $parts);
            }

            return;
        }

        if (is_scalar($value)) {
            $parts[] = (string) $value;
        }
    }
}
