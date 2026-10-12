<?php

namespace App\Services\Review;

use App\Services\Ops\AiModelUpdateReviewPresentationService;
use App\Services\ReviewTypeRegistryService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReviewDeskSourceService
{
    public function __construct(private ReviewTypeRegistryService $registry, private ReviewContextEnrichmentService $enrichment) {}

    public function sources(): array
    {
        $sources = [];
        try {
            $disabled = $this->registry->getDisabledTypeNames();
        } catch (Throwable) {
            $disabled = [];
        }
        foreach ($this->registry->getTypes() as $name => $type) {
            if ($name === RegistryFaceReviewService::TYPE && (($type['source_table'] ?? null) !== 'file_registry_faces'
                || ($type['source_connection'] ?? null) === 'pgsql_rag')) {
                continue;
            }
            // Date estimates and legacy metadata inspections share a table, but
            // have different predicates and decision histories.
            if ($name === 'scan_date_estimate') {
                continue;
            }
            $connection = $type['source_connection'] === 'pgsql_rag' ? 'pgsql_rag' : null;
            $key = ($connection ?? 'default').':'.$type['source_table'];
            $sources[$key] ??= [
                'key' => $key, 'table' => $type['source_table'], 'connection' => $connection,
                'id_column' => 'id', 'status_column' => $type['source_table'] === 'research_facts' ? 'review_status' : 'status',
                'pending_states' => ['pending'], 'types' => [], 'name' => $name,
                'selection' => [],
                'discovery_mode' => 'queue', 'disabled_types' => $disabled,
                'source_url' => '/reviews/source', 'effect' => 'Inspect the exact proposal and its native approval effect.',
                'blocker' => 'Specialized effect or form requires native review; generic packet approval is not admitted.',
            ];
            $sources[$key]['types'][$name] = $type;
            if ($name === RegistryFaceReviewService::TYPE) {
                $sources[$key]['status_column'] = 'source_current';
                $sources[$key]['pending_states'] = ['1'];
                $sources[$key]['discovery_mode'] = 'on_demand';
            }
        }
        foreach (DB::table('review_desk_sources')->orderBy('name')->get() as $row) {
            if ($row->name === 'scan_date_estimate') {
                $type = $this->registry->getType('scan_date_estimate');
                if (! $type || in_array('scan_date_estimate', $disabled, true)) {
                    continue;
                }
                $sources['scan_date_estimate'] = [
                    'key' => 'scan_date_estimate', 'table' => $row->source_table, 'connection' => $row->connection_name,
                    'id_column' => $row->id_column, 'status_column' => $row->status_column,
                    'pending_states' => json_decode($row->pending_states, true), 'types' => ['scan_date_estimate' => $type],
                    'name' => $row->name, 'selection' => json_decode($row->selection ?? '[]', true),
                    'discovery_mode' => $row->discovery_mode, 'disabled_types' => $disabled,
                    'source_url' => $row->source_url, 'effect' => $row->effect, 'blocker' => $row->blocker,
                ];

                continue;
            }
            $key = ($row->connection_name ?? 'default').':'.($row->source_table ?? $row->name);
            if (isset($sources[$key])) {
                $sources[$key]['source_url'] = $row->source_url;
                $sources[$key]['effect'] = $row->effect;
                $sources[$key]['blocker'] = $row->blocker;
                $sources[$key]['pending_states'] = json_decode($row->pending_states, true);
                $sources[$key]['selection'] = json_decode($row->selection ?? '[]', true);
                $sources[$key]['discovery_mode'] = $row->discovery_mode;

                continue;
            }
            $sources[$key] = [
                'key' => $key, 'table' => $row->source_table, 'connection' => $row->connection_name,
                'id_column' => $row->id_column, 'status_column' => $row->status_column,
                'pending_states' => json_decode($row->pending_states, true), 'types' => [], 'name' => $row->name,
                'selection' => json_decode($row->selection ?? '[]', true),
                'discovery_mode' => $row->discovery_mode, 'disabled_types' => $disabled,
                'source_url' => $row->source_url, 'effect' => $row->effect, 'blocker' => $row->blocker,
            ];
        }

        return $sources;
    }

    public function connection(array $source): Connection
    {
        return DB::connection($source['connection']);
    }

    public function read(array $source, string $id, bool $lock = false): ?object
    {
        $query = $this->connection($source)->table($source['table'])->where($source['id_column'], $id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function project(array $source, object $row): array
    {
        if ($source['name'] === RegistryFaceReviewService::TYPE) {
            $pending = app(RegistryFaceReviewService::class)->pending()->where('face.id', $row->id)->exists();

            return ['unified_id' => RegistryFaceReviewService::TYPE.':'.$row->id, 'review_type' => RegistryFaceReviewService::TYPE,
                'category' => 'Photos', 'title' => 'Unidentified face', 'question' => 'Who is in this photo?',
                'current' => 'Identity unknown', 'proposed' => null,
                'effect' => 'Choose a person you recognize. Naming stages a separate proposal for your approval; family tree membership is optional.',
                'sensitive' => true, 'bulk_eligible' => false, 'actions' => [], 'status' => $pending ? 'pending' : 'history',
                'source_state' => $pending ? 'pending' : 'closed', 'blockers' => [], 'source_url' => '/reviews?mode=photos&ref=registry_face:'.$row->id,
                'enabled' => ! in_array(RegistryFaceReviewService::TYPE, $source['disabled_types'], true),
                '_aliases' => ['registry_face:'.$row->id], '_source_revision' => hash('sha256', json_encode([(array) $row, $pending], JSON_THROW_ON_ERROR))];
        }
        if ($source['key'] === 'scan_date_estimate') {
            return $this->scanDateProjection($source, $row);
        }
        $name = isset($source['types'][$row->review_type ?? '']) ? $row->review_type : $source['name'];
        if ($source['table'] === 'agent_review_queue' && ! isset($source['types'][$row->review_type ?? ''])) {
            $name = $row->review_type ?? 'agent';
        }
        $type = $source['types'][$name] ?? null;
        $mapped = $type ? ($this->registry->mapRowsForDisplay($name, [$row])[0] ?? []) : [];
        $id = (string) $row->{$source['id_column']};
        $unifiedId = $mapped['unified_id'] ?? $name.':'.$id;
        $state = (string) ($row->{$source['status_column']} ?? 'unknown');
        $pending = in_array($state, $source['pending_states'], true) && $this->matchesSelection($source, $row);
        if (! empty($row->expires_at) && strtotime($row->expires_at) <= time() && $pending) {
            $state = 'expired';
            $pending = false;
        }
        $aliases = [$unifiedId, $name.':'.$id];
        $policyFacts = [];
        if ($source['table'] === 'research_facts') {
            $mission = $this->connection($source)->table('research_missions')->where('id', $row->mission_id)->first();
            $policyFacts = ['domain_category' => $mission->domain_category ?? null, 'indexed_to_rag' => (bool) ($row->indexed_to_rag ?? false)];
            $row = clone $row;
            $row->_mission = $mission;
        }
        if ($source['table'] === 'agent_review_queue') {
            foreach (array_unique([$name, $row->review_type ?? $name, 'agent']) as $aliasType) {
                $aliases[] = $aliasType.':'.$id;
                if (! empty($row->token)) {
                    $aliases[] = $aliasType.':'.$row->token;
                }
                $aliases[] = app(ReviewTargetReferenceService::class)->forReviewRow($row, $aliasType);
            }
        }

        $title = $this->safeText($mapped['title'] ?? $row->title ?? $row->subject ?? $row->packet_label ?? $row->fact_statement ?? $type['label'] ?? $name);

        $projected = [
            'unified_id' => $unifiedId, 'review_type' => $name,
            'category' => $mapped['category'] ?? $type['category'] ?? $name,
            'title' => $title,
            'question' => 'Inspect '.$title.' in its source workspace.',
            'current' => isset($row->current_value) ? $this->safeText($row->current_value) : $state,
            'proposed' => isset($row->proposed_value) ? $this->safeText($row->proposed_value) : null, 'effect' => $source['effect'],
            'sensitive' => true, 'bulk_eligible' => false, 'actions' => [],
            'status' => $pending ? 'pending' : 'history', 'source_state' => $state,
            'blockers' => [$source['blocker']],
            'source_url' => $type ? '/reviews/source?unified_id='.rawurlencode($unifiedId) : $source['source_url'],
            'policy_facts' => $policyFacts,
            'has_identity_suggestion' => $name === 'face' && ($row->match_type ?? '') !== 'no_match' && ! empty($row->suggested_person_id),
            'enabled' => ! in_array($name, $source['disabled_types'], true),
            '_aliases' => array_values(array_unique($aliases)),
            '_source_revision' => hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR)),
        ];
        if ($name === 'provider_health') {
            $projected = array_replace($projected, $this->providerHealthObservation($row));
            $projected['_source_revision'] = hash('sha256', json_encode($projected, JSON_THROW_ON_ERROR));
        }

        return $projected;
    }

    private function scanDateProjection(array $source, object $row): array
    {
        $pending = $row->status === 'active' && $this->matchesSelection($source, $row)
            && ! empty($row->date_taken) && ! empty($row->asset_uuid) && (int) ($row->exif_written ?? 0) !== 1;
        $state = $pending ? 'pending' : ((int) ($row->date_needs_review ?? 0) === 0 && $row->date_taken_source === 'user_manual'
            ? 'approved' : ((int) ($row->date_needs_review ?? 0) === 0 && $row->date_taken_source === 'scan_exif' && ! $row->date_taken ? 'rejected' : 'unavailable'));
        $id = (string) $row->id;
        $details = ['file_registry_id' => (int) $row->id, 'asset_uuid' => $row->asset_uuid,
            'filename' => $this->safeText($row->filename), 'proposed_date' => $row->date_taken,
            'proposed_precision' => $row->date_taken_precision ?? 'year', 'scan_date' => $row->scan_date,
            'ai_confidence' => $row->date_taken_confidence, 'ai_source' => $row->date_taken_source,
            'ai_reasoning' => $this->safeText($row->date_taken_reasoning ?? '')];

        return ['unified_id' => 'scan_date_estimate:'.$id, 'review_type' => 'scan_date_estimate', 'category' => 'files',
            'title' => 'Review scan date: '.$this->safeText($row->filename), 'question' => 'Is this approximate original-photo date correct?',
            'current' => 'Digitized: '.$this->safeText($row->scan_date ?? 'unknown'),
            'proposed' => $this->safeText($row->date_taken).' ('.$details['proposed_precision'].')',
            'effect' => 'Accept the catalog estimate, or separately approve a source-pinned approximate year write. The digitization date is preserved.',
            'sensitive' => true, 'bulk_eligible' => false, 'actions' => [], 'status' => $pending ? 'pending' : 'history',
            'source_state' => $state, 'blockers' => [], 'source_url' => '/reviews/source?unified_id=scan_date_estimate%3A'.$id,
            'details' => $details, 'enabled' => true, 'policy_facts' => [],
            'created_at' => $row->date_extracted_at ?? $row->updated_at ?? null,
            '_aliases' => ['scan_date_estimate:'.$id], '_source_revision' => hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR))];
    }

    private function providerHealthObservation(object $row): array
    {
        $details = is_string($row->details ?? null) ? json_decode($row->details, true) : (array) ($row->details ?? []);
        $providerId = $details['provider'] ?? null;
        if (! is_string($providerId) || $providerId === '') {
            return [];
        }
        try {
            $provider = DB::table('llm_instances')->where('instance_id', $providerId)
                ->first(['is_healthy', 'circuit_state', 'last_health_check', 'config']);
        } catch (Throwable) {
            $provider = null;
        }
        $observed = $this->safeText($details['observed_at'] ?? $row->created_at ?? 'unknown time');
        $historical = 'Original alert ('.$observed.'): '.$this->safeText($row->title ?? 'Provider health concern').'.';
        if (! $provider) {
            return ['evidence_summary' => $historical, 'uncertainty' => 'Current provider state is unavailable. The original alert is not a live health check.'];
        }
        $config = json_decode((string) ($provider->config ?? '{}'), true);
        $managed = is_array($config) && array_key_exists('controller_catalog', $config);
        $lastProbe = $this->safeText($provider->last_health_check ?? 'unknown time');
        $health = $provider->is_healthy ? 'healthy' : 'unhealthy';

        return [
            'question' => $managed ? 'Does this controller-managed provider need intervention?' : 'Does this provider still need intervention?',
            'current' => 'Stored endpoint observation: '.$health.'; circuit '.$this->safeText($provider->circuit_state).'; checked '.$lastProbe.'.',
            'proposed' => 'Verify current availability before choosing a repair or closing this follow-up.',
            'evidence_summary' => $historical,
            'uncertainty' => $managed
                ? 'This engine is controller-managed. A stopped engine can be intentional. Verify controller reachability, selected engine/model and lease state; the stored endpoint probe does not establish on-demand readiness.'
                : 'The alert and stored endpoint observation are separate dated evidence. Neither automatically closes this follow-up.',
        ];
    }

    public function context(array $item, ?array $source = null, ?object $row = null, bool $detail = true): ?array
    {
        if ($item['review_type'] === RegistryFaceReviewService::TYPE) {
            return ['item' => ['id' => (int) ($row->id ?? explode(':', $item['unified_id'], 2)[1]),
                'unified_id' => $item['unified_id']], 'registry_face_review' => ['operator_only' => true]];
        }
        if (! $detail && $item['review_type'] === 'file_source_recovery' && $source && $row) {
            $mapped = $this->registry->mapRowsForDisplay('file_source_recovery', [$row])[0] ?? [];

            return ['item' => ['summary' => $this->safeText($mapped['summary'] ?? '')]];
        }
        if ($item['review_type'] === 'scan_date_estimate' && $source && $row) {
            return ['item' => $this->scanDateProjection($source, $row)];
        }
        if ($item['review_type'] === GlobalFaceIdentityReviewService::REVIEW_TYPE && $source && $row) {
            return ['global_identity_review' => app(GlobalFaceIdentityReviewService::class)->presentation($row)];
        }
        if ($item['review_type'] === 'research') {
            $sourceId = explode(':', $item['unified_id'], 2)[1];
            $fact = DB::connection('pgsql_rag')->table('research_facts')->where('id', $sourceId)->first();
            if (! $fact) {
                return null;
            }
            $urls = json_decode($fact->source_urls ?? '[]', true);

            return ['item' => ['unified_id' => $item['unified_id'], 'title' => $this->safeText($fact->fact_statement),
                'summary' => $this->safeText($fact->context_snippet ?? ''), 'status' => $fact->review_status],
                'research_fact' => ['statement' => $this->safeText($fact->fact_statement),
                    'source_urls' => array_values(array_filter(array_map(fn ($url) => $this->safeUrl($url), array_slice(is_array($urls) ? $urls : [], 0, 20)))),
                    'context_snippet' => $this->safeText($fact->context_snippet ?? ''),
                    'source_citations' => $this->citationFields($fact->source_citations ?? []),
                    'source_count' => max(0, (int) ($fact->source_count ?? 0)),
                    'verification_summary' => $this->verificationFields($fact->verification_summary ?? []),
                    'indexed_to_rag' => (bool) $fact->indexed_to_rag,
                    'approval_effect' => 'Accept this already-indexed fact; no new indexing is performed.',
                    'rejection_effect' => 'Reject this fact and record its hash to suppress future duplicate discoveries.']];
        }
        if ($item['review_type'] === 'face') {
            $sourceId = explode(':', $item['unified_id'], 2)[1];
            if (! ctype_digit($sourceId) || (int) $sourceId < 1) {
                return null;
            }
            $mapped = $this->registry->fetchDisplayItemBySourceId('face', $sourceId);
            if (! $mapped) {
                $row ??= DB::table('genealogy_face_match_queue')->where('id', $sourceId)->first();
                if ($row) {
                    $mapped = $this->registry->mapRowsForDisplay('face', [$row])[0] ?? null;
                    if ($mapped) {
                        $mapped['id'] = $row->id;
                        $mapped['match_type'] = $row->match_type ?? '';
                        $mapped['suggested_person_id'] = $row->suggested_person_id ?? null;
                        $mapped['face_region'] = $row->face_region ?? null;
                        $mapped = array_replace($mapped, $this->faceCatalogEvidence((string) $row->id));
                    }
                }
            }

            if ($mapped) {
                $row ??= DB::table('genealogy_face_match_queue')->where('id', $sourceId)->first();
                $details = json_decode((string) ($row->match_details ?? '{}'), true);
                $mapped['tree_id'] = $row->tree_id ?? null;
                $mapped['region_reviewed'] = is_array($details) && ! empty($details['region_reviews'])
                    && ($details['face_region_convention'] ?? null) === 'normalized_top_left'
                    && preg_match('/^[a-f0-9]{64}$/D', (string) ($details['source_sha256'] ?? '')) === 1;
                $mapped['source_sha256'] = $mapped['region_reviewed'] ? $details['source_sha256'] : null;
            }
            $context = $mapped ? app(FaceMatchReviewPresentationService::class)->project($mapped, $this->registry->getType('face')) : null;
            if ($context) {
                $context['media_evidence_status'] = $mapped['media_evidence_status'] ?? 'catalog_reference';
                $context['face_review']['global_face'] = $row && is_array($details)
                    ? $this->globalFaceSnapshot($row, $details) : null;
            }

            return $context;
        }

        if ($item['review_type'] === 'privacy') {
            $sourceId = $row?->id ?? explode(':', $item['unified_id'], 2)[1];
            if (! ctype_digit((string) $sourceId) || (int) $sourceId < 1) {
                return null;
            }
            $mapped = $this->registry->fetchDisplayItemBySourceId('privacy', (string) $sourceId);
            if (! $mapped) {
                return ['item' => ['id' => (int) $sourceId, 'unified_id' => 'privacy:'.$sourceId,
                    'status' => $this->safeText($row->status ?? 'Not verified'),
                    'requires_review' => isset($row->requires_review) ? (bool) $row->requires_review : null, 'actions' => []],
                    'source_diagnostic' => 'The exact request was not returned by the registered privacy view. Its related records or eligibility conditions need native review; no decision is admitted.'];
            }
            $mapped['id'] = (int) $sourceId;
            $mapped['unified_id'] = 'privacy:'.$sourceId;

            return app(PrivacyRequestReviewPresentationService::class)->project($mapped, $this->registry->getType('privacy') ?? []);
        }
        if ($item['review_type'] === 'research_hint' && $row) {
            $info = $this->objectArray($row->source_info ?? null);

            return ['item' => ['summary' => $this->safeText($row->description ?? '')], 'research_hint' => [
                'description' => $this->safeText($row->description ?? ''),
                'suggested_record_type' => $this->safeText($row->suggested_record_type ?? ''),
                'record_source' => $this->safeText($row->record_source ?? ''),
                'record_url' => $this->safeUrl($row->record_url ?? null),
                'source_title' => $this->safeText($info['title'] ?? $info['name'] ?? ''),
                'source_excerpt' => $this->safeText($info['excerpt'] ?? $info['description'] ?? ''),
                'source_url' => $this->safeUrl($info['url'] ?? null),
            ], 'subject' => $this->subject($row)];
        }
        if ($item['review_type'] === 'intake' && $row) {
            $snapshot = $this->objectArray($row->staged_snapshot ?? null);
            $packets = [];
            foreach (array_slice(is_array($snapshot['packets'] ?? null) ? $snapshot['packets'] : [], 0, 20) as $packet) {
                if (! is_array($packet)) {
                    continue;
                }
                $preview = $this->objectArray($packet['preview_state'] ?? null);
                $packets[] = ['label' => $this->safeText($packet['packet_label'] ?? ''),
                    'file_count' => max(0, (int) ($packet['file_count'] ?? 0)),
                    'summary' => $this->safeText($preview['packet_summary'] ?? ''),
                    'questions' => $this->textList($preview['questions'] ?? [])];
            }

            return ['intake' => ['label' => $this->safeText($row->packet_label ?? $snapshot['packet_label'] ?? ''),
                'run_key' => is_string($row->run_key ?? null) ? $row->run_key : null,
                'file_count' => max(0, (int) ($snapshot['file_count'] ?? 0)), 'packets' => $packets,
                'packet_count' => max(count($packets), (int) ($snapshot['packet_count'] ?? 0))], 'subject' => $this->subject($row)];
        }

        $context = $this->enrichment->getContext($item['unified_id']);
        $details = $this->objectArray($row->details ?? ($context['item']['details'] ?? []));
        if (in_array($item['review_type'], ['provider_health', 'skill_optimization', 'ai_model_update'], true)) {
            $context ??= ['item' => ['summary' => $this->safeText($row->summary ?? '')]];
            if ($item['review_type'] === 'ai_model_update') {
                $context['ai_model_update'] ??= app(AiModelUpdateReviewPresentationService::class)->project($details);
            } elseif ($item['review_type'] === 'provider_health') {
                $context['provider_health'] = ['provider' => $this->safeText($details['provider'] ?? ''),
                    'recommended_action' => $this->safeText($details['recommended_action'] ?? ''),
                    'observed_at' => $this->safeText($details['observed_at'] ?? ''),
                    'summary' => $this->safeText($row->summary ?? $context['item']['summary'] ?? '')];
            } else {
                $summary = $this->objectArray($details['summary'] ?? null);
                $samples = [];
                foreach (array_slice(is_array($details['samples'] ?? null) ? $details['samples'] : [], 0, 20) as $sample) {
                    if (is_array($sample)) {
                        $samples[] = ['area' => $this->safeText($sample['area'] ?? ''),
                            'proposal_count' => max(0, (int) ($sample['proposal_count'] ?? 0)),
                            'proposal_types' => $this->namedCounts($sample['proposal_types'] ?? [])];
                    }
                }
                $context['skill_optimization'] = ['summary' => is_string($details['summary'] ?? null) ? $this->safeText($details['summary']) : '',
                    'report_count' => max(0, (int) ($summary['reports'] ?? 0)),
                    'proposal_types' => $this->namedCounts($summary['proposal_types'] ?? []), 'samples' => $samples,
                    'window_days' => max(0, (int) ($details['window_days'] ?? 0))];
            }
        }

        return $context;
    }

    private function subject(object $row): array
    {
        $subject = ['person' => '', 'tree' => '', 'person_url' => null];
        try {
            $person = ! empty($row->person_id) ? DB::table('genealogy_persons')->where('id', $row->person_id)->first() : null;
            if ($person) {
                $subject['person'] = $this->safeText(trim(($person->given_name ?? '').' '.($person->surname ?? '')));
                $subject['person_url'] = '/genealogy?person='.(int) $person->id;
            }
            $treeId = $person->tree_id ?? $row->tree_id ?? null;
            $tree = $treeId ? DB::table('genealogy_trees')->where('id', $treeId)->first() : null;
            $subject['tree'] = $this->safeText($tree->name ?? '');
        } catch (Throwable) {
        }

        return $subject;
    }

    /** One exact queue pointer; no source downloads or identity decisions. */
    private function globalFaceSnapshot(object $queue, array $details): ?array
    {
        if (empty($queue->file_registry_face_id) || empty($queue->media_id) || empty($queue->tree_id)
            || empty($details['region_reviews'])) {
            return null;
        }
        try {
            $face = DB::table('file_registry_faces as face')
                ->join('file_registry as file', 'file.id', '=', 'face.file_registry_id')
                ->join('genealogy_media as media', function ($join) use ($queue): void {
                    $join->where('media.id', (int) $queue->media_id)->where('media.tree_id', (int) $queue->tree_id);
                })
                ->where('face.id', (int) $queue->file_registry_face_id)
                ->select(['face.id as face_id', 'face.person_name', 'face.verified', 'face.hidden',
                    'face.genealogy_person_id', 'face.global_identity_uuid', 'face.global_identity_approval_receipt_id',
                    'face.source_current', 'face.superseded_at', 'face.source_sha256', 'face.face_region_convention',
                    'face.region_x', 'face.region_y', 'face.region_w', 'face.region_h',
                    'file.current_path', 'file.status as file_status', 'media.id as media_id', 'media.tree_id as media_tree_id',
                    'media.nextcloud_path', 'media.original_path as media_original_path'])->first();

            return app(FaceMatchReviewPresentationService::class)->globalFaceForReview($queue, $details, $face);
        } catch (Throwable) {
            return null;
        }
    }

    private function faceCatalogEvidence(string $id): array
    {
        try {
            $evidence = DB::table('genealogy_face_match_queue as face')
                ->leftJoin('genealogy_persons as person', 'person.id', '=', 'face.suggested_person_id')
                ->leftJoin('genealogy_media as media', 'media.id', '=', 'face.media_id')
                ->leftJoin('file_registry_faces as region', 'region.id', '=', 'face.file_registry_face_id')
                ->leftJoin('file_registry as file', function ($join): void {
                    $join->on('file.id', '=', 'region.file_registry_id')->where('file.status', 'active');
                })
                ->where('face.id', $id)
                ->select(['person.given_name', 'person.surname', 'media.file_exists', 'media.nextcloud_path',
                    'media.original_path as media_original_path', 'file.current_path', 'file.original_path',
                    'region.id as registry_face_id', 'region.source_current', 'region.superseded_at',
                    'region.source_sha256', 'region.face_region_convention'])->first();
            $currentFaceSource = ! $evidence || empty($evidence->registry_face_id)
                || ((bool) $evidence->source_current
                    && empty($evidence->superseded_at)
                    && is_string($evidence->source_sha256)
                    && $evidence->source_sha256 !== ''
                    && $evidence->face_region_convention === 'normalized_top_left');
            $available = $evidence && $currentFaceSource && (
                trim((string) ($evidence->current_path ?? '')) !== '' || trim((string) ($evidence->original_path ?? '')) !== ''
                || (! empty($evidence->file_exists) && (trim((string) ($evidence->nextcloud_path ?? '')) !== '' || trim((string) ($evidence->media_original_path ?? '')) !== ''))
            );

            return ['image_available' => (bool) $available, 'media_evidence_status' => 'catalog_reference',
                'suggested_person_name' => $this->safeText(trim(($evidence->given_name ?? '').' '.($evidence->surname ?? '')))];
        } catch (Throwable) {
            return ['image_available' => false, 'media_evidence_status' => 'not_verified'];
        }
    }

    private function citationFields(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : (preg_match('/^\s*[\[{]/', $value) ? [] : [$value]);
        }
        if (! is_array($value)) {
            return [];
        }
        if (! array_is_list($value)) {
            $value = [$value];
        }
        $citations = [];
        foreach (array_slice($value, 0, 20) as $citation) {
            if (is_string($citation) && $this->safeText($citation) !== '') {
                $citations[] = ['Citation' => $this->safeText($citation)];

                continue;
            }
            if (! is_array($citation)) {
                continue;
            }
            $fields = [];
            $citation['excerpt'] ??= $citation['supporting_excerpt'] ?? null;
            foreach (['Title' => 'title', 'Author' => 'author', 'Citation' => 'citation', 'Page' => 'page', 'Excerpt' => 'excerpt', 'Repository' => 'repository'] as $label => $key) {
                $text = $this->safeText($citation[$key] ?? $citation[$label] ?? '');
                if ($text !== '') {
                    $fields[$label] = $text;
                }
            }
            if ($url = $this->safeUrl($citation['url'] ?? $citation['URL'] ?? null)) {
                $fields['URL'] = $url;
            }
            if ($fields !== []) {
                $citations[] = $fields;
            }
        }

        return $citations;
    }

    private function verificationFields(mixed $value): array
    {
        $value = $this->objectArray($value);
        $fields = [];
        foreach (['Summary' => 'summary', 'Assessment' => 'status', 'Explanation' => 'explanation', 'Method' => 'method',
            'Verification status' => 'verification_status', 'Source basis' => 'source_basis',
            'Sources checked' => 'external_sources_checked', 'Sources confirmed' => 'external_sources_confirmed'] as $label => $key) {
            $text = $this->safeText($value[$key] ?? $value[$label] ?? '');
            if ($text !== '') {
                $fields[$label] = $text;
            }
        }

        return $fields;
    }

    public function researchEvidenceReady(array $fact): bool
    {
        if ($this->safeText($fact['context_snippet'] ?? '') !== '') {
            return true;
        }
        foreach ($this->citationFields($fact['source_citations'] ?? []) as $citation) {
            if (! empty($citation['Excerpt'])) {
                return true;
            }
        }

        return false;
    }

    private function objectArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private function textList(mixed $values): array
    {
        return array_values(array_filter(array_map(fn ($value) => $this->safeText($value), array_slice(is_array($values) ? $values : [], 0, 20)), fn ($value) => $value !== ''));
    }

    private function namedCounts(mixed $values): array
    {
        $result = [];
        foreach (array_slice(is_array($values) ? $values : [], 0, 20, true) as $name => $count) {
            if (is_string($name) && is_numeric($count)) {
                $result[] = $this->safeText(str_replace('_', ' ', $name)).': '.max(0, (int) $count);
            } elseif (is_string($count)) {
                $result[] = $this->safeText(str_replace('_', ' ', $count));
            }
        }

        return $result;
    }

    public function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)
            || ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            return null;
        }
        parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/token|secret|password|signature|api.?key|authorization/i', $key)) {
                return null;
            }
        }

        return $value;
    }

    public function matchesSelection(array $source, object $row): bool
    {
        foreach ($source['selection'] as $column => $values) {
            if (! in_array($row->{$column} ?? null, $values)) {
                return false;
            }
        }

        return true;
    }

    public function operatorPresentation(array $item, ?array $context, array $sourceData = []): array
    {
        $context ??= [];
        $type = $item['review_type'];
        $summary = $this->safeText($context['item']['summary'] ?? $sourceData['description'] ?? $sourceData['summary'] ?? '');
        $sections = [];
        $proposals = [];
        $missing = [];
        $state = ! empty($item['actionable']) ? 'ready' : 'source_review';
        $explanation = 'Review the exact scope in the source form; no blanket approval is available here.';
        $subject = $context['subject'] ?? $sourceData['subject'] ?? [];
        $addSection = function (string $title, array $fields) use (&$sections): void {
            $values = [];
            foreach ($fields as $label => $value) {
                $text = $this->safeText($value);
                if ($text !== '') {
                    $values[] = ['label' => $label, 'value' => $text];
                }
            }
            if ($values !== []) {
                $sections[] = ['title' => $title, 'fields' => $values];
            }
        };
        $addProposal = function (string $label, string $description, string $effect) use (&$proposals, &$item): void {
            $proposals[] = ['label' => $this->safeText($label), 'description' => $this->safeText($description),
                'effect' => $this->safeText($effect), 'source_url' => $item['source_url']];
        };
        if ($subject !== []) {
            $addSection('Subject', ['Person' => ($subject['person'] ?? '') ?: 'Person name unavailable', 'Tree' => ($subject['tree'] ?? '') ?: 'Tree name unavailable']);
            if (! empty($subject['person_url']) && preg_match('~^/genealogy\?person=[1-9][0-9]*$~', $subject['person_url'])) {
                $item['source_url'] = $subject['person_url'];
            }
        }
        foreach (['question', 'current', 'proposed', 'effect', 'title'] as $field) {
            if (isset($item[$field]) && is_scalar($item[$field])) {
                $item[$field] = $this->safeText($item[$field]);
            }
        }

        if ($type === GlobalFaceIdentityReviewService::REVIEW_TYPE) {
            $proposal = $context['global_identity_review'] ?? [];
            $decision = $proposal['global_identity_decision'] ?? [];
            $summary = $this->safeText($proposal['effect'] ?? 'The exact person registry proposal is unavailable.');
            $addSection('Person record', ['Canonical name' => $proposal['canonical_name'] ?? '',
                'Alias for this person' => $decision['alias'] ?? '',
                'Optional family tree' => isset($decision['tree_id']) ? (string) $decision['tree_id'] : 'No family tree mapping in this decision',
                'Selected tree person' => $proposal['selected_genealogy_person_name'] ?? '',
                'Tree person record' => isset($decision['genealogy_person_id']) ? (string) $decision['genealogy_person_id'] : '',
                'Picture associations' => 'Require separate current-source review']);
            if ($decision === []) {
                $state = 'needs_evidence';
                $missing[] = 'An exact person registry proposal is required.';
            }
        } elseif ($type === RegistryFaceReviewService::TYPE) {
            $summary = 'Choose the person you recognize in the highlighted face. No identity has been suggested.';
            $explanation = 'Your person selection stages a separate current-source approval. A family tree mapping is optional.';
            $state = 'source_review';
        } elseif ($type === 'face') {
            $face = $context['item'] ?? [];
            $hasSuggestion = ($sourceData['match_type'] ?? $face['match_type'] ?? '') !== 'no_match' && (array_key_exists('has_identity_suggestion', $item) ? $item['has_identity_suggestion']
                : (($sourceData['match_type'] ?? $face['match_type'] ?? '') !== 'no_match'
                    && (! empty($sourceData['suggested_person_id']) || ! empty($face['suggested_person_name']))));
            if (! $hasSuggestion) {
                $item['question'] = 'Who is this unidentified person?';
                $item['current'] = 'Identity unknown';
                $item['proposed'] = 'No identity has been suggested.';
                $item['effect'] = 'Research the photo and identify a supported candidate before requesting an identity decision.';
                $summary = 'No candidate identity is attached. There is no identity suggestion to approve or deny.';
                $item['actions'] = array_values(array_diff($item['actions'] ?? [], ['approve', 'reject']));
                $state = 'needs_research';
                $missing[] = 'A supported candidate identity is needed.';
            } else {
                $summary = 'The suggested identity is unconfirmed. Compare the detected region with the original photo.';
                $state = ! empty($context['face_review']['approval_ready']) && ! empty($item['actionable']) ? 'ready' : 'needs_evidence';
            }
            if (empty($face['image_available'])) {
                $missing[] = 'Image availability has not been verified; open the original photo before an identity decision.';
            }
            if (empty($face['face_region'])) {
                $missing[] = 'A valid detected face region is missing.';
            }
            $addSection('Identity evidence', ['Suggested identity' => $hasSuggestion ? ($face['suggested_person_name'] ?? 'Name unavailable') : 'None',
                'Photo' => empty($face['image_available']) ? 'Availability not verified' : 'Catalog image reference present; browser load must be verified']);
            if (in_array('refresh_region', $item['actions'] ?? [], true)) {
                $item['question'] = in_array('link', $item['actions'], true)
                    ? 'Who is in this saved face region?' : 'Select the current face for review';
                $item['effect'] = 'Saving a region preserves earlier claims and leaves identity unconfirmed. Linking a person requires your separate confirmation. Not a face closes only this queue entry.';
                $summary = 'Review the current photo, select the face, then choose a person you can confirm. Earlier labels remain historical claims.';
                $explanation = 'Current photo and region review are available here. Identity remains unconfirmed until you explicitly link a selected person.';
            }
        } elseif ($type === 'research') {
            $fact = $context['research_fact'] ?? $sourceData;
            $statement = $this->safeText($fact['statement'] ?? $fact['fact_statement'] ?? $item['title']);
            $urls = $this->objectArray($fact['source_urls'] ?? []);
            $urls = array_values(array_filter(array_map(fn ($url) => $this->safeUrl($url), array_slice($urls, 0, 20))));
            $summary = $statement;
            $item['question'] = 'What evidence supports this finding?';
            $item['current'] = 'An AI-generated finding awaiting source verification';
            $item['proposed'] = $statement;
            $addSection('Finding', ['Statement' => $statement, 'Context' => $context['item']['summary'] ?? $fact['context_snippet'] ?? '']);
            foreach ($urls as $url) {
                $addSection('Supporting source', ['URL' => $url]);
            }
            $citations = $fact['source_citations'] ?? [];
            if ($urls !== [] && ! $this->researchEvidenceReady($fact)) {
                $state = 'needs_evidence';
                $missing[] = 'Source links alone do not establish this finding; attach an excerpt or a supporting citation.';
                $item['actions'] = array_values(array_diff($item['actions'] ?? [], ['approve']));
                $item['bulk_eligible'] = false;
                $item['bulk_actions'] = [];
                $item['effect'] = 'Attach supporting evidence before approval; a source URL alone is not verified provenance.';
            }
            foreach ($this->citationFields($citations) as $citation) {
                $addSection('Citation', $citation);
            }
            $addSection('Verification', $this->verificationFields($fact['verification_summary'] ?? []));
            if (! empty($fact['source_count'])) {
                $addSection('Reported provenance', ['Reported source count' => (string) (int) $fact['source_count']]);
            }
            if ($urls === []) {
                $state = preg_match('/no (?:specific |relevant |matching )?(?:information|records|results|evidence)|unable to|could not find|consider (?:searching|checking)|further research/i', $statement)
                    ? 'needs_research' : 'needs_evidence';
                $missing[] = $this->citationFields($citations) === []
                    ? 'No usable source URL or citation is attached to support this finding.'
                    : 'Citations are attached but a verified direct source link is still required for a Desk decision.';
                $item['effect'] = 'Obtain supporting evidence before accepting this as a fact. No direct approval or rejection is available.';
                $item['actions'] = array_values(array_diff($item['actions'] ?? [], ['approve', 'reject']));
                $item['bulk_eligible'] = false;
                $item['bulk_actions'] = [];
            }
        } elseif ($type === 'research_hint') {
            $hint = $context['research_hint'] ?? $sourceData;
            $info = $this->objectArray($sourceData['source_info'] ?? []);
            $summary = $this->safeText($hint['description'] ?? '');
            $personLabel = $this->safeText($subject['person'] ?? '') ?: $this->safeText($item['title']);
            $item['question'] = 'Which record could resolve '.$personLabel.'?';
            $item['current'] = 'A research lead, not a verified genealogy change';
            $item['proposed'] = $this->safeText($hint['suggested_record_type'] ?? '') ?: 'Investigate the described lead and attach the original record.';
            $item['effect'] = 'Research the lead in the person’s genealogy workspace; no tree change is approved here.';
            $state = 'needs_research';
            $addSection('Research lead', ['Description' => $summary, 'Record type' => $hint['suggested_record_type'] ?? '',
                'Record collection' => $hint['record_source'] ?? '', 'Source title' => $hint['source_title'] ?? $info['title'] ?? '',
                'Excerpt' => $hint['source_excerpt'] ?? $info['excerpt'] ?? '']);
            $url = $this->safeUrl($hint['record_url'] ?? $hint['source_url'] ?? $info['url'] ?? null);
            if ($url) {
                $addSection('Record source', ['URL' => $url]);
            } else {
                $missing[] = 'The original record or a direct record link has not been attached.';
            }
            $addProposal('Research this lead', $summary ?: 'Obtain the underlying record and assess it against this person.', $item['effect']);
        } elseif ($type === 'intake') {
            $intake = $context['intake'] ?? $this->objectArray($sourceData['staged_snapshot'] ?? []);
            $runKey = $intake['run_key'] ?? $sourceData['run_key'] ?? null;
            if (is_string($runKey) && preg_match('/^[A-Za-z0-9_.:-]{1,200}$/', $runKey)) {
                $item['source_url'] = '/genealogy?intake_run='.rawurlencode($runKey);
            }
            $packets = is_array($intake['packets'] ?? null) ? $intake['packets'] : [];
            $summary = count($packets).' staged packets require evidence review before any individual genealogy change.';
            $item['title'] = $this->safeText($intake['label'] ?? '')
                ?: ($this->safeText($sourceData['packet_label'] ?? '')
                    ?: ($this->safeText($packets[0]['label'] ?? $packets[0]['packet_label'] ?? '') ?: 'Staged genealogy documents'));
            $item['question'] = 'What do the staged documents establish for '.$item['title'].'?';
            $item['current'] = 'Documents staged for research';
            $item['proposed'] = 'Review each packet and prepare separately supported changes.';
            $item['effect'] = 'Packet review does not approve generated changes or update the tree.';
            foreach (array_slice($packets, 0, 20) as $packet) {
                if (! is_array($packet)) {
                    continue;
                }
                $preview = $this->objectArray($packet['preview_state'] ?? []);
                $label = $this->safeText($packet['label'] ?? $packet['packet_label'] ?? '') ?: 'Staged packet';
                $packetSummary = $this->safeText($packet['summary'] ?? $preview['packet_summary'] ?? '');
                $addSection($label, ['Documents' => (string) max(0, (int) ($packet['file_count'] ?? 0)),
                    'Evidence summary' => $packetSummary ?: 'No evidence summary has been prepared.']);
                foreach ($this->textList($packet['questions'] ?? $preview['questions'] ?? []) as $question) {
                    $addSection('Open research question', ['Question' => $question]);
                }
                $addProposal('Review '.$label, $packetSummary ?: 'Inspect the originals and prepare an evidence-backed proposal.', $item['effect']);
            }
            $state = 'needs_research';
            $missing[] = 'Individual changes need original-record evidence and a separate proposal review.';
            if (! str_starts_with($item['source_url'], '/genealogy?intake_run=')) {
                $missing[] = 'The intake run reference is missing; open the Genealogy area and select the packet explicitly.';
            }
        } elseif ($type === 'ai_model_update') {
            $model = $context['ai_model_update'] ?? (new AiModelUpdateReviewPresentationService)->project($this->objectArray($sourceData['details'] ?? []));
            foreach (array_slice($model['actions'] ?? [], 0, 20) as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $label = trim(str_replace('_', ' ', $this->safeText($action['decision'] ?? '')).' '.$this->safeText($action['resource_id'] ?? ''));
                $addProposal($label, $this->safeText($action['benefit'] ?? ''), 'Inspection only. This link opens the portfolio review, whose approval records all listed resources for bounded validation. Request a scoped follow-up for an individual action; the Desk does not approve the portfolio.');
                $addSection($label, ['Benefit' => $action['benefit'] ?? '', 'Exact revision' => $action['exact_model_revision'] ?? '',
                    'License' => $action['license'] ?? '', 'Evidence' => implode('\n', $this->textList($action['evidence'] ?? [])),
                    'Risks' => implode('\n', $this->textList($action['risks'] ?? [])),
                    'Required validation' => implode('\n', $this->textList($action['required_validation'] ?? []))]);
                foreach (array_slice($action['source_urls'] ?? [], 0, 20) as $url) {
                    if ($safeUrl = $this->safeUrl($url)) {
                        $addSection('Action evidence', ['Source' => $safeUrl]);
                    }
                }
            }
            $summary = count($proposals).' separate proposed model actions require scoped review.';
            $item['question'] = 'Does this proposed model portfolio need scoped follow-up before native review?';
            $item['current'] = 'A portfolio proposal; current deployment is not revalidated by this report';
            $item['proposed'] = count($proposals).' individual actions, listed below';
            $item['effect'] = 'Native approval covers the portfolio for bounded validation, not one listed action. These actions are inspection-only here; request scoped follow-up when needed.';
            if ($proposals === []) {
                $missing[] = 'No concrete individual actions were attached.';
            }
        } elseif ($type === 'provider_health') {
            $health = $context['provider_health'] ?? $context['item']['details'] ?? $this->objectArray($sourceData['details'] ?? []);
            $observedAt = $this->safeText($health['observed_at'] ?? '');
            $summary = 'Historical provider observation'.($observedAt !== '' ? ' from '.$observedAt : ' with no recorded observation time').'; current health has not been rechecked.';
            $recommended = $this->safeText($health['recommended_action'] ?? '');
            $item['question'] = 'Does this provider observation still require attention?';
            $item['current'] = 'Historical diagnostic; present health is unverified';
            $item['proposed'] = $recommended ?: 'Verify current service health before choosing a corrective action.';
            $item['effect'] = 'Investigate current health; this review does not restart services or change routing.';
            $item = array_replace($item, $this->providerHealthObservation((object) $sourceData));
            $state = 'operational_followup';
            $addSection('Recorded observation', ['Provider' => $health['provider'] ?? '', 'Observed at' => $observedAt,
                'Recommended next step' => $recommended]);
            if (isset($item['evidence_summary'])) {
                $summary = $item['evidence_summary'];
                $addSection('Latest stored endpoint observation', ['Observation' => $item['current'],
                    'Interpretation' => $item['uncertainty'] ?? 'Current controller readiness is unverified.']);
            }
            $addProposal('Check current provider health', $item['proposed'], $item['effect']);
            $missing[] = $item['uncertainty'] ?? 'A current service/network/model probe is required; this historical report is not a fresh health check.';
        } elseif ($type === 'skill_optimization') {
            $report = $context['skill_optimization'] ?? [];
            if ($report === []) {
                $details = $context['item']['details'] ?? $this->objectArray($sourceData['details'] ?? []);
                $reportSummary = $this->objectArray($details['summary'] ?? []);
                $report = ['report_count' => $reportSummary['reports'] ?? 0, 'window_days' => $details['window_days'] ?? 0,
                    'proposal_types' => $this->namedCounts($reportSummary['proposal_types'] ?? []), 'samples' => []];
                foreach (array_slice($details['samples'] ?? [], 0, 20) as $sample) {
                    if (is_array($sample)) {
                        $report['samples'][] = ['area' => $sample['area'] ?? '', 'proposal_count' => $sample['proposal_count'] ?? 0,
                            'proposal_types' => $this->namedCounts($sample['proposal_types'] ?? [])];
                    }
                }
            }
            $summary = (int) ($report['report_count'] ?? 0).' preserved skill reports require review; acknowledgment does not apply skill changes.';
            $item['question'] = 'Which reported skill improvements justify a separately reviewed change?';
            $item['current'] = 'An analysis report, not an installed skill revision';
            $item['proposed'] = implode(', ', $this->textList($report['proposal_types'] ?? [])) ?: 'No exact skill edit is attached.';
            $item['effect'] = 'Review the report and request an exact proposed edit before changing skills, tools, memory, or tests.';
            $addSection('Report coverage', ['Reports' => (string) (int) ($report['report_count'] ?? 0),
                'Window in days' => (string) (int) ($report['window_days'] ?? 0), 'Proposal categories' => $item['proposed']]);
            foreach (array_slice($report['samples'] ?? [], 0, 20) as $sample) {
                $addSection('Report sample', ['Area' => str_replace('_', ' ', $sample['area'] ?? ''),
                    'Proposals' => (string) (int) ($sample['proposal_count'] ?? 0), 'Kinds' => implode(', ', $this->textList($sample['proposal_types'] ?? []))]);
            }
            $missing[] = 'Exact replacement content and validation results are needed for an executable skill change.';
            $addProposal('Review reported improvements', $item['proposed'], $item['effect']);
        } elseif ($type === 'privacy') {
            $privacy = $context['privacy_review'] ?? [];
            $subjectItem = $context['item'] ?? [];
            $privacyId = $subjectItem['id'] ?? $sourceData['id'] ?? null;
            if (ctype_digit((string) $privacyId) && (int) $privacyId > 0) {
                $item['source_url'] = '/data-removal?request='.(int) $privacyId;
            }
            $summary = 'Review the broker target and minimum personal-data categories in the specialized removal form.';
            $item['question'] = 'What removal scope is appropriate for '.($subjectItem['subject_name'] ?? 'this subject').'?';
            $item['current'] = 'A removal request awaiting exact scope review';
            $requiresReview = $subjectItem['requires_review'] ?? $sourceData['requires_review'] ?? null;
            if ($requiresReview !== null && ! (bool) $requiresReview) {
                $item['current'] = 'Source does not currently request operator review';
                $item['actions'] = array_values(array_diff($item['actions'] ?? [], ['approve', 'reject']));
                $state = 'source_review';
                $missing[] = 'The source does not request operator review. A pending source record is not necessarily a decision request.';
            }
            $item['proposed'] = 'Select only the personal-data categories required for this broker.';
            $item['effect'] = $privacy['approval_effect'] ?? 'Use the native removal form; no external submission occurs here.';
            $addSection('Removal target', ['Subject' => $subjectItem['subject_name'] ?? '', 'Broker' => $subjectItem['broker_name'] ?? '',
                'Profile' => $this->safeUrl($subjectItem['profile_url'] ?? null)]);
            $addSection('Data categories', ['Required' => implode(', ', $this->textList($privacy['required_fields'] ?? [])),
                'Available' => implode(', ', $this->textList($privacy['available_fields'] ?? [])),
                'Selected' => implode(', ', $this->textList($privacy['selected_fields'] ?? []))]);
            if ($privacy === []) {
                $addSection('Request evidence unavailable', ['Request ID' => is_scalar($privacyId) ? (string) $privacyId : 'Unavailable',
                    'Status' => $subjectItem['status'] ?? $sourceData['status'] ?? 'Not verified',
                    'Operator review requested' => $requiresReview === null ? 'Not verified' : ((bool) $requiresReview ? 'Yes' : 'No'),
                    'Diagnostic' => $context['source_diagnostic'] ?? 'The exact request context was not available; verify its related records and eligibility in the removal workspace.']);
                $item['question'] = 'Which evidence and target records are missing for this removal request?';
            }
            if ($privacy === [] || ! empty($privacy['blockers'])) {
                $missing[] = 'The exact subject, broker destination, and required data categories must be verified in the native form.';
            }
            if ($requiresReview !== null && ! (bool) $requiresReview) {
                $item['question'] = 'Why is this request visible in source diagnostics?';
                $item['proposed'] = 'No operator decision is currently requested.';
                $item['effect'] = 'Inspect the source request if needed; its review flag is unchanged and no decision or external submission occurs here.';
                $summary = 'This pending source record does not currently request an operator decision.';
            }
        } else {
            foreach (array_slice($context['comparison']['field_diffs'] ?? [], 0, 20) as $diff) {
                if (is_array($diff)) {
                    $addSection('Proposed field change', ['Field' => str_replace('_', ' ', $this->safeText($diff['field'] ?? '')),
                        'On file' => $diff['on_file'] ?? '', 'Proposed' => $diff['proposed'] ?? '']);
                }
            }
            $addSection('Review context', ['Summary' => $summary]);
            $missing[] = 'Use the specialized source form to verify the exact target, evidence, and effect.';
        }
        if ($summary === '') {
            $summary = 'No evidence summary was attached to '.$this->safeText($item['title']).'.';
            $missing[] = 'An evidence summary is missing.';
        }
        if (in_array($state, ['needs_evidence', 'needs_research', 'operational_followup'], true)) {
            $explanation = match ($state) {
                'needs_evidence' => 'Attach the supporting original evidence before an approval decision.',
                'needs_research' => 'Investigate the open question before preparing an evidence-backed decision.',
                default => 'Check current conditions before taking an operational action.',
            };
        } elseif ($state === 'ready') {
            $explanation = 'A supported individual decision is available; review its evidence and exact effect.';
        }
        if ($type === 'face' && in_array('refresh_region', $item['actions'] ?? [], true)) {
            $explanation = 'Current photo and region review are available here. Identity remains unconfirmed until you explicitly link a selected person.';
        }
        $labels = ['ready' => 'Ready to review', 'needs_evidence' => 'Needs evidence', 'needs_research' => 'Needs research',
            'operational_followup' => 'Operational follow-up', 'source_review' => 'Review in source'];
        $item['readiness'] = ['state' => $state, 'label' => $labels[$state], 'explanation' => $explanation];
        if (count($sections) > 100) {
            $missing[] = 'Showing the first 100 evidence sections; open the source review for the remaining '.(count($sections) - 100).' sections.';
        }
        $item['operator_view'] = ['schema' => 'plos.review_desk_operator.v1', 'summary' => $summary,
            'sections' => array_slice($sections, 0, 100), 'proposed_actions' => array_slice($proposals, 0, 20),
            'missing_evidence' => array_values(array_unique($missing))];
        if (! empty($observedAt)) {
            $item['operator_view']['observed_at'] = $observedAt;
        }
        $item['actionable'] = array_intersect(['approve', 'reject'], $item['actions'] ?? []) !== [];
        $item['requires_source_review'] = ! $item['actionable'] && ($item['status'] ?? '') !== 'history';

        return $item;
    }

    public function safeText(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return mb_substr((string) preg_replace([
            '~\bBearer\s+\S+~i', '~\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)[_-][A-Za-z0-9_=\-]{8,}\b~i',
            '~/(?:home|Users|root)/[^\s,"\x27\)\]}]+~', '~[A-Za-z]:\\\\[^\s,"\x27\)\]}]+~',
            '~\b(?:api[_-]?key|password|secret|access[_-]?token|authorization)\s*[:=]\s*[^\s,;]+~i',
            '~https?://[^\s/@]+:[^\s/@]+@[^\s]+~i',
            '~https?://[^\s]+[?&](?:token|secret|password|signature|api_?key|access_token)=[^\s]+~i',
        ], '[redacted]', $text), 0, 1200);
    }
}
