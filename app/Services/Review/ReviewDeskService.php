<?php

namespace App\Services\Review;

use App\Services\Files\ScanDateEstimateReviewDecisionService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\Genealogy\FaceMatchReviewDecisionService;
use App\Services\Research\ResearchReviewService;
use App\Services\ReviewTypeRegistryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ReviewDeskService
{
    private bool $readyOnlySyncScope = false;

    public function __construct(private ReviewDeskSourceService $sources, private ReviewTypeRegistryService $registry) {}

    public function items(array $filters = []): array
    {
        $hasQuery = array_key_exists('q', $filters) && $filters['q'] !== null && $filters['q'] !== '';
        $readyOnly = ($filters['readiness'] ?? (($filters['status'] ?? 'pending') === 'pending' ? 'ready' : 'all')) === 'ready'
            && ($filters['status'] ?? 'pending') === 'pending'
            && ! $hasQuery;
        $previousScope = $this->readyOnlySyncScope;
        $this->readyOnlySyncScope = $readyOnly;
        try {
            $availability = $this->synchronize();
        } finally {
            $this->readyOnlySyncScope = $previousScope;
        }
        $sourceCatalog = $this->sources->sources();
        $query = DB::table('review_desk_references');
        try {
            $query->whereNotIn('review_type', $this->registry->getDisabledTypeNames());
        } catch (Throwable) {
        }
        if (! empty($filters['type'])) {
            $query->where('review_type', $filters['type']);
        }
        if ($hasQuery) {
            $query->where(function ($search) use ($filters): void {
                $search->where('title', 'like', '%'.$filters['q'].'%')
                    ->orWhere('reference', $filters['q'])
                    ->orWhereIn('reference', DB::table('review_desk_aliases')->select('reference')
                        ->where('alias', $filters['q'])->where('ambiguous', false));
            });
        }
        $counts = array_fill_keys(['pending', 'followup', 'later', 'history'], 0);
        foreach ((clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get() as $count) {
            $counts[$count->status] = (int) $count->total;
        }
        $query->where('status', $filters['status'] ?? 'pending');
        $readinessCounts = array_fill_keys(['ready', 'needs_evidence', 'needs_research', 'operational_followup', 'source_review'], 0);
        $total = 0;
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 25)));
        $items = [];
        $readiness = $filters['readiness'] ?? (($filters['status'] ?? 'pending') === 'pending' ? 'ready' : 'all');
        if ($readiness === 'ready' && ($filters['status'] ?? 'pending') === 'pending') {
            $this->countInspectionOnlyReferences($query, $sourceCatalog, $readinessCounts, $filters['type'] ?? null, $readyOnly);
        }
        $query->orderBy('created_at')->orderBy('reference')->chunk(200, function ($records) use ($sourceCatalog, $readiness, $offset, $limit, &$readinessCounts, &$total, &$items): void {
            $evidence = $this->listEvidence($records->all(), $sourceCatalog);
            foreach ($records as $record) {
                $item = $this->present($record, false, sourceCatalog: $sourceCatalog, listEvidence: $evidence[$record->reference] ?? null);
                $lane = $item['readiness']['state'];
                $readinessCounts[$lane]++;
                if ($readiness !== 'all' && $readiness !== $lane) {
                    continue;
                }
                if ($total >= $offset && count($items) < $limit) {
                    $items[] = $item;
                }
                $total++;
            }
        });
        $types = [];
        foreach ($this->registry->getTypes() as $name => $type) {
            $types[$name] = ['name' => $name, 'label' => $type['label'] ?? Str::headline($name),
                'inspection_only' => ($type['ui_schema']['inspection_only'] ?? false) === true];
        }
        foreach (DB::table('review_desk_references')->distinct()->pluck('review_type') as $name) {
            $types[$name] ??= ['name' => $name, 'label' => Str::headline($name), 'inspection_only' => true];
        }

        return ['items' => $items, 'total' => $total, 'has_more' => $offset + $limit < $total,
            'counts' => $counts, 'counts_scope' => 'indexed_references',
            'readiness_counts' => $readinessCounts, 'readiness_filter' => $readiness, 'sources' => $availability, 'source_status' => $availability,
            'types' => array_values($types),
            'source_links' => array_map(fn ($source) => ['source' => $source['source'], 'label' => Str::headline($source['source']),
                'url' => $source['source_url'], 'source_url' => $source['source_url'], 'status' => $source['status']], $availability),
            'history_coverage' => 'Indexed references only; older closed source records are materialized when their exact legacy link is opened.',
            'warnings' => array_values(array_filter($availability, fn ($source) => $source['status'] === 'unavailable'))];
    }

    public function lookup(string $reference): ?array
    {
        // Photo discovery is SQL-only; refresh this exact library face on demand.
        // Canonical UUID bookmarks retain their historical generation.
        if (preg_match('/^registry_face:([1-9][0-9]*)$/D', $reference, $match)) {
            $source = $this->sources->sources()[RegistryFaceReviewService::SOURCE] ?? null;
            if ($source && $source['name'] === RegistryFaceReviewService::TYPE
                && ! in_array(RegistryFaceReviewService::TYPE, $source['disabled_types'], true)) {
                $row = $this->sources->read($source, $match[1]);
                if ($row) {
                    $this->remember($source, $row, true);
                }
            }
        }
        $record = $this->resolve($reference);

        return $record ? $this->present($record, true) : null;
    }

    public function synchronize(): array
    {
        $availability = [];
        try {
            DB::table('review_type_registry')->count();
        } catch (Throwable) {
            $availability[] = ['source' => 'review_type_registry', 'label' => 'Review type registry', 'source_url' => '/reviews/source',
                'status' => 'unavailable', 'error' => 'Registry could not be read; only known native sources may be available.'];
        }
        foreach ($this->sources->sources() as $source) {
            $report = ['source' => $source['name'], 'label' => Str::headline($source['name']), 'source_url' => $source['source_url'], 'blocker' => $source['blocker']];
            if ($source['table'] === null || $source['discovery_mode'] === 'native') {
                $availability[] = $report + ['status' => 'native_only'];

                continue;
            }
            try {
                $inspectionOnlyTypes = $this->readyOnlySyncScope ? $this->inspectionOnlyTypes($source) : [];
                $query = $this->sources->connection($source)->table($source['table']);
                $query->whereIn($source['status_column'], $source['pending_states']);
                if ($source['discovery_mode'] === 'on_demand') {
                    $query->whereRaw('1 = 0'); // New library faces are discovered through the paginated photo list.
                }
                if ($source['table'] === 'agent_review_queue') {
                    $query->whereNotIn('review_type', $source['disabled_types']);
                    if ($inspectionOnlyTypes !== []) {
                        $query->where(function ($include) use ($source, $inspectionOnlyTypes): void {
                            $include->whereNotIn('review_type', $inspectionOnlyTypes)
                                ->orWhereExists(fn ($held) => $held->selectRaw('1')->from('review_desk_references as held_reference')
                                    ->join('review_desk_receipts as held_receipt', 'held_receipt.reference', '=', 'held_reference.reference')
                                    ->where('held_reference.source', $source['key'])
                                    ->whereColumn('held_reference.source_id', 'agent_review_queue.id')
                                    ->whereColumn('held_reference.review_type', 'agent_review_queue.review_type')
                                    ->whereIn('held_receipt.state', ['processing', 'unknown'])
                                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_held')
                                        ->whereColumn('newer_held.source', 'held_reference.source')
                                        ->whereColumn('newer_held.source_id', 'held_reference.source_id')
                                        ->whereColumn('newer_held.generation', '>', 'held_reference.generation')))
                                ->orWhereExists(fn ($history) => $history->selectRaw('1')->from('review_desk_references as current_history')
                                    ->where('current_history.source', $source['key'])
                                    ->whereColumn('current_history.source_id', 'agent_review_queue.id')
                                    ->whereColumn('current_history.review_type', 'agent_review_queue.review_type')
                                    ->where('current_history.status', 'history')
                                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_history')
                                        ->whereColumn('newer_history.source', 'current_history.source')
                                        ->whereColumn('newer_history.source_id', 'current_history.source_id')
                                        ->whereColumn('newer_history.generation', '>', 'current_history.generation')));
                        });
                    }
                }
                foreach ($source['selection'] as $column => $values) {
                    $query->whereIn($column, $values);
                }
                if ($source['key'] === 'scan_date_estimate') {
                    $query->whereNotNull('date_taken')->whereNotNull('asset_uuid')->whereRaw('COALESCE(exif_written, 0) <> 1');
                }
                $query->chunkById(250, function ($rows) use ($source): void {
                    $latest = $this->latestReferences($source['key'], $rows->pluck($source['id_column'])->all());
                    foreach ($rows as $row) {
                        $this->remember($source, $row, knownExisting: $latest[$row->{$source['id_column']}] ?? null, existingKnown: true);
                    }
                }, $source['id_column']);
                $closure = DB::table('review_desk_references')->where('source', $source['key'])->whereIn('status', ['pending', 'followup', 'later']);
                if ($inspectionOnlyTypes !== []) {
                    $closure->whereNotIn('review_type', $inspectionOnlyTypes);
                }
                $closure->chunkById(250, function ($records) use ($source): void {
                    $rows = $this->sources->connection($source)->table($source['table'])
                        ->whereIn($source['id_column'], $records->pluck('source_id')->all())->get()->keyBy($source['id_column']);
                    $latest = $this->latestReferences($source['key'], $records->pluck('source_id')->all());
                    foreach ($records as $record) {
                        $row = $rows->get($record->source_id);
                        if ($row) {
                            $this->remember($source, $row, knownExisting: $latest[$record->source_id] ?? null, existingKnown: true);

                            continue;
                        }
                        DB::table('review_desk_references')->where('reference', $record->reference)
                            ->where('revision', $record->revision)->update([
                                'source_state' => 'unavailable', 'status' => 'history',
                                'revision' => $record->revision + 1, 'updated_at' => now(),
                            ]);
                    }
                }, 'reference');
                $availability[] = $report + ['status' => 'available'];
            } catch (Throwable) {
                $availability[] = $report + ['status' => 'unavailable', 'error' => 'Source could not be read; counts may be incomplete.'];
            }
        }

        return $availability;
    }

    private function latestReferences(string $source, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $latest = DB::table('review_desk_references')->select('source_id')
            ->selectRaw('MAX(generation) AS generation')->where('source', $source)
            ->whereIn('source_id', array_unique($ids))->groupBy('source_id');

        return DB::table('review_desk_references as reference')->joinSub($latest, 'latest', function ($join): void {
            $join->on('reference.source_id', '=', 'latest.source_id')->on('reference.generation', '=', 'latest.generation');
        })->where('reference.source', $source)->select([
            'reference.source_id', 'reference.source_revision', 'reference.generation',
        ])->get()->keyBy('source_id')->all();
    }

    private function listEvidence(array $records, array $sourceCatalog): array
    {
        $groups = [];
        foreach ($records as $record) {
            $groups[$record->source][] = $record;
        }
        $evidence = [];
        $policyTypes = array_intersect(array_unique(array_column($records, 'review_type')), ['face', 'research', 'scan_date_estimate', GlobalFaceIdentityReviewService::REVIEW_TYPE]);
        $policies = [];
        if ($policyTypes !== []) {
            foreach (DB::table('review_desk_policy')->whereIn('review_type', $policyTypes)->get() as $policy) {
                $policies[$policy->review_type][$policy->action] = $policy;
            }
        }
        foreach ($groups as $key => $group) {
            $ids = array_column($group, 'source_id');
            $latest = $this->latestReferences($key, $ids);
            $source = $sourceCatalog[$key] ?? null;
            $rows = [];
            $error = null;
            try {
                if ($source) {
                    $rows = $this->sources->connection($source)->table($source['table'])
                        ->whereIn($source['id_column'], $ids)->get()->keyBy($source['id_column'])->all();
                }
            } catch (Throwable $exception) {
                $error = $exception;
            }
            $held = DB::table('review_desk_receipts')->whereIn('reference', array_column($group, 'reference'))
                ->whereIn('state', ['processing', 'unknown'])->pluck('reference')->flip()->all();
            foreach ($group as $record) {
                $evidence[$record->reference] = [
                    'generation' => (int) ($latest[$record->source_id]->generation ?? 0),
                    'row' => $rows[$record->source_id] ?? null, 'error' => $error,
                    'unresolved' => array_key_exists($record->reference, $held), 'policies' => $policies,
                ];
            }
        }

        return $evidence;
    }

    private function inspectionOnlyTypes(array $source, ?bool &$hasExpiryColumn = null): array
    {
        $hasExpiryColumn = null;
        if ($source['table'] !== 'agent_review_queue' || $source['connection'] !== null) {
            return [];
        }

        $types = [];
        foreach ($source['types'] as $name => $type) {
            if (($type['ui_schema']['inspection_only'] ?? false) === true
                && ! in_array($name, $source['disabled_types'], true)) {
                $types[] = $name;
            }
        }
        if ($types === []) {
            return [];
        }
        try {
            $hasExpiryColumn = Schema::hasColumn($source['table'], 'expires_at');
            $decisions = DB::table('review_desk_policy')->whereIn('review_type', $types)
                ->where('enabled', true)->pluck('review_type')->all();
        } catch (Throwable) {
            $hasExpiryColumn = null;

            return [];
        }

        return array_values(array_diff($types, $decisions));
    }

    private function countInspectionOnlyReferences(Builder $query, array $sourceCatalog, array &$readinessCounts, ?string $filterType, bool $useSourceCounts): void
    {
        foreach ($sourceCatalog as $source) {
            $hasExpiryColumn = null;
            $types = $this->inspectionOnlyTypes($source, $hasExpiryColumn);
            if ($types === [] || $hasExpiryColumn === null) {
                continue;
            }
            if ($filterType !== null && $filterType !== '') {
                $types = array_values(array_intersect($types, [$filterType]));
            }
            if ($types === []) {
                continue;
            }

            if (! $useSourceCounts) {
                $inspection = (clone $query)->whereIn('review_type', $types)->where('source', $source['key'])
                    ->whereExists(fn ($rows) => $rows->selectRaw('1')->from($source['table'].' as inspection_source')
                        ->whereColumn('inspection_source.'.$source['id_column'], 'review_desk_references.source_id')
                        ->whereColumn('inspection_source.review_type', 'review_desk_references.review_type'))
                    ->whereNotExists(fn ($receipts) => $receipts->selectRaw('1')->from('review_desk_receipts as inspection_receipt')
                        ->whereColumn('inspection_receipt.reference', 'review_desk_references.reference')
                        ->whereIn('inspection_receipt.state', ['processing', 'unknown']))
                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_inspection')
                        ->whereColumn('newer_inspection.source', 'review_desk_references.source')
                        ->whereColumn('newer_inspection.source_id', 'review_desk_references.source_id')
                        ->whereColumn('newer_inspection.generation', '>', 'review_desk_references.generation'));
                try {
                    $readinessCounts['source_review'] += (clone $inspection)->count();
                    $query->whereNotIn('reference', (clone $inspection)->select('reference'));
                } catch (Throwable) {
                }

                continue;
            }

            $sourceRows = $this->inspectionSourceRows($source, $types, $hasExpiryColumn)->whereNotExists(function ($deskState) use ($source): void {
                $deskState->selectRaw('1')->from('review_desk_references as current_reference')
                    ->where('current_reference.source', $source['key'])
                    ->whereColumn('current_reference.source_id', 'inspection_source.id')
                    ->whereColumn('current_reference.review_type', 'inspection_source.review_type')
                    ->whereIn('current_reference.status', ['followup', 'later'])
                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_reference')
                        ->whereColumn('newer_reference.source', 'current_reference.source')
                        ->whereColumn('newer_reference.source_id', 'current_reference.source_id')
                        ->whereColumn('newer_reference.generation', '>', 'current_reference.generation'));
            })->whereNotExists(function ($retainedHistory) use ($source): void {
                $retainedHistory->selectRaw('1')->from('review_desk_references as unchanged_history')
                    ->where('unchanged_history.source', $source['key'])
                    ->whereColumn('unchanged_history.source_id', 'inspection_source.id')
                    ->whereColumn('unchanged_history.review_type', 'inspection_source.review_type')
                    ->where('unchanged_history.status', 'history')->where('unchanged_history.source_state', 'pending')
                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_history')
                        ->whereColumn('newer_history.source', 'unchanged_history.source')
                        ->whereColumn('newer_history.source_id', 'unchanged_history.source_id')
                        ->whereColumn('newer_history.generation', '>', 'unchanged_history.generation'));
            });
            try {
                $eligibleCount = (int) (clone $sourceRows)->count();
                $heldCount = (int) (clone $sourceRows)->whereExists(function ($held) use ($source): void {
                    $held->selectRaw('1')->from('review_desk_references as held_reference')
                        ->join('review_desk_receipts as held_receipt', 'held_receipt.reference', '=', 'held_reference.reference')
                        ->where('held_reference.source', $source['key'])
                        ->whereColumn('held_reference.source_id', 'inspection_source.id')
                        ->whereColumn('held_reference.review_type', 'inspection_source.review_type')
                        ->where('held_reference.status', 'pending')
                        ->whereIn('held_receipt.state', ['processing', 'unknown'])
                        ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_held')
                            ->whereColumn('newer_held.source', 'held_reference.source')
                            ->whereColumn('newer_held.source_id', 'held_reference.source_id')
                            ->whereColumn('newer_held.generation', '>', 'held_reference.generation'));
                })->count();
                $readinessCounts['source_review'] += max(0, $eligibleCount - $heldCount);
            } catch (Throwable) {
                continue;
            }

            $heldReferences = DB::table('review_desk_references as inspection_reference')
                ->where('inspection_reference.source', $source['key'])->whereIn('inspection_reference.review_type', $types)
                ->whereExists(fn ($receipt) => $receipt->selectRaw('1')->from('review_desk_receipts as live_receipt')
                    ->whereColumn('live_receipt.reference', 'inspection_reference.reference')
                    ->whereIn('live_receipt.state', ['processing', 'unknown']));
            $query->where(function ($eligible) use ($heldReferences, $types, $source, $hasExpiryColumn): void {
                $eligible->where('source', '<>', $source['key'])
                    ->orWhereNotIn('review_type', $types)
                    ->orWhereIn('reference', (clone $heldReferences)->select('reference'))
                    ->orWhere(function ($history) use ($source, $types, $hasExpiryColumn): void {
                        $history->whereExists(fn ($newer) => $newer->selectRaw('1')->from('review_desk_references as newer_inspection')
                            ->whereColumn('newer_inspection.source', 'review_desk_references.source')
                            ->whereColumn('newer_inspection.source_id', 'review_desk_references.source_id')
                            ->whereColumn('newer_inspection.generation', '>', 'review_desk_references.generation'))
                            ->whereExists(function ($current) use ($source, $types, $hasExpiryColumn): void {
                                $current->selectRaw('1')->from($source['table'].' as current_inspection')
                                    ->whereColumn('current_inspection.'.$source['id_column'], 'review_desk_references.source_id')
                                    ->whereColumn('current_inspection.review_type', 'review_desk_references.review_type')
                                    ->whereIn('current_inspection.'.$source['status_column'], $source['pending_states'])
                                    ->whereIn('current_inspection.review_type', $types);
                                foreach ($source['selection'] as $column => $values) {
                                    $current->whereIn('current_inspection.'.$column, $values);
                                }
                                if ($hasExpiryColumn) {
                                    $current->where(function ($expiry): void {
                                        $expiry->whereNull('current_inspection.expires_at')->orWhere('current_inspection.expires_at', '>', now());
                                    });
                                }
                            });
                    });
            });
        }
    }

    private function inspectionSourceRows(array $source, array $types, bool $hasExpiryColumn): Builder
    {
        $query = DB::table($source['table'].' as inspection_source')
            ->whereIn('inspection_source.'.$source['status_column'], $source['pending_states'])
            ->whereIn('inspection_source.review_type', $types);
        foreach ($source['selection'] as $column => $values) {
            $query->whereIn('inspection_source.'.$column, $values);
        }
        if ($hasExpiryColumn) {
            $query->where(function ($expiry): void {
                $expiry->whereNull('inspection_source.expires_at')->orWhere('inspection_source.expires_at', '>', now());
            });
        }

        return $query;
    }

    private function remember(array $source, object $row, bool $ensureAliases = false, ?object $knownExisting = null, bool $existingKnown = false): void
    {
        // Ordinary queue projections fingerprint the raw row. Compare before
        // display mapping, while retaining both fresh synchronization reads.
        // Provider health includes related live evidence in its fingerprint.
        if (! $ensureAliases && $existingKnown && $knownExisting && $source['table'] === 'agent_review_queue'
            && ($row->review_type ?? $source['name']) !== 'provider_health'
            && $knownExisting->source_revision === hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR))) {
            return;
        }
        $item = $this->sources->project($source, $row);
        $id = (string) $row->{$source['id_column']};
        $existing = $existingKnown ? $knownExisting : DB::table('review_desk_references')->where('source', $source['key'])
            ->where('source_id', $id)->orderByDesc('generation')->first();
        if (! $ensureAliases && $existing && $existing->source_revision === $item['_source_revision']) {
            return;
        }
        DB::transaction(function () use ($source, $id, $item): void {
            $record = DB::table('review_desk_references')->where('source', $source['key'])
                ->where('source_id', $id)->orderByDesc('generation')->lockForUpdate()->first();
            $reopened = $record && $record->status === 'history' && $item['status'] === 'pending'
                && ($record->source_state !== 'pending' || $record->source_revision !== $item['_source_revision']);
            if (! $record || $reopened) {
                $reference = (string) Str::uuid();
                DB::table('review_desk_references')->insertOrIgnore([
                    'reference' => $reference, 'source' => $source['key'], 'source_id' => $id,
                    'generation' => $record ? $record->generation + 1 : 1,
                    'previous_reference' => $record?->reference, 'revision' => 1,
                    'source_revision' => $item['_source_revision'], 'review_type' => $item['review_type'],
                    'source_state' => $item['source_state'], 'status' => $item['status'], 'title' => $item['title'],
                    'snapshot' => json_encode($this->snapshot($item), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $record = DB::table('review_desk_references')->where('source', $source['key'])
                    ->where('source_id', $id)->orderByDesc('generation')->lockForUpdate()->first();
            } elseif ($record->status !== 'history' && $record->source_revision !== $item['_source_revision']) {
                $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $snapshot = $item['status'] === 'history'
                    ? $this->retainedClosure($snapshot, $item['source_state'])
                    : array_replace($snapshot, $this->snapshot($item));
                DB::table('review_desk_references')->where('reference', $record->reference)->update([
                    'source_revision' => $item['_source_revision'], 'source_state' => $item['source_state'],
                    'status' => $item['status'] === 'history' ? 'history' : $record->status,
                    'revision' => $record->revision + 1, 'title' => $snapshot['title'],
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            foreach ($item['_aliases'] as $alias) {
                DB::table('review_desk_aliases')->insertOrIgnore(['alias' => $alias, 'reference' => $record->reference]);
                $existing = DB::table('review_desk_aliases')->where('alias', $alias)->first();
                if ($existing->reference !== $record->reference) {
                    $owner = DB::table('review_desk_references')->where('reference', $existing->reference)->first();
                    if (! $owner || $owner->source !== $source['key'] || $owner->source_id !== $id) {
                        DB::table('review_desk_aliases')->where('alias', $alias)->update(['ambiguous' => true]);
                    } elseif ($source['name'] === RegistryFaceReviewService::TYPE && ! $existing->ambiguous) {
                        DB::table('review_desk_aliases')->where('alias', $alias)->update(['reference' => $record->reference]);
                    }
                }
            }
        });
    }

    public function materializeReference(string $reference): ?array
    {
        if ($existing = $this->lookup($reference)) {
            return $existing;
        }
        if (! str_contains($reference, ':')) {
            return null;
        }
        [$typeName, $id] = explode(':', $reference, 2);
        foreach ($this->sources->sources() as $source) {
            $registeredName = isset($source['types'][$typeName]) ? $typeName : null;
            if ($registeredName === null) {
                foreach ($source['types'] as $name => $type) {
                    if (str_starts_with($type['field_mapping']['unified_id_template'] ?? '', $typeName.':')) {
                        if ($registeredName !== null) {
                            throw new HttpException(409, 'Ambiguous legacy review prefix.');
                        }
                        $registeredName = $name;
                    }
                }
            }
            if ($source['table'] === null || $source['discovery_mode'] === 'native'
                || in_array($typeName, $source['disabled_types'], true)
                || ($registeredName === null && $source['name'] !== $typeName)) {
                continue;
            }
            if (preg_match('/^target-[a-f0-9]{12}$/i', $id) && $source['table'] === 'agent_review_queue') {
                $this->sources->connection($source)->table($source['table'])->chunkById(250, function ($rows) use ($source, $typeName, $reference): bool {
                    foreach ($rows as $row) {
                        if (app(ReviewTargetReferenceService::class)->forReviewRow($row, $typeName) === $reference) {
                            $this->remember($source, $row, true);

                            return false;
                        }
                    }

                    return true;
                }, $source['id_column']);
            } else {
                $column = $source['id_column'];
                $template = $source['types'][$registeredName]['field_mapping']['unified_id_template'] ?? '';
                if (! ctype_digit($id) && str_contains($template, '{{token}}')) {
                    $column = 'token';
                }
                $row = $this->sources->connection($source)->table($source['table'])->where($column, $id)->first();
                if ($row) {
                    $this->remember($source, $row, true);
                }
            }

            return $this->lookup($reference);
        }

        return null;
    }

    private function snapshot(array $item): array
    {
        unset($item['_aliases'], $item['_source_revision']);

        return $item;
    }

    private function resolve(string $reference, bool $lock = false): ?object
    {
        $alias = DB::table('review_desk_aliases')->where('alias', $reference)->first();
        if ($alias?->ambiguous) {
            throw new HttpException(409, 'Ambiguous legacy review reference.');
        }
        $query = DB::table('review_desk_references')->where('reference', $alias?->reference ?? $reference);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function present(object $record, bool $detail, ?object $lockedSource = null, ?array $sourceCatalog = null, ?string $ownedReceiptId = null, ?array $listEvidence = null): array
    {
        $listEvidence = ! $detail ? $listEvidence : null;
        $listPolicies = $listEvidence['policies'] ?? null;
        $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $currentGeneration = $listEvidence['generation'] ?? (int) DB::table('review_desk_references')->where('source', $record->source)
            ->where('source_id', $record->source_id)->max('generation');
        $historical = $record->status === 'history' || $record->generation < $currentGeneration;
        $context = $snapshot['context'] ?? null;
        $sourceRevision = $record->source_revision;
        $available = true;
        try {
            $source = $historical ? null : (($sourceCatalog ?? $this->sources->sources())[$record->source] ?? null);
            if (! $historical && isset($listEvidence['error'])) {
                throw $listEvidence['error'];
            }
            $row = $source && ! $historical ? ($lockedSource ?? ($listEvidence !== null ? $listEvidence['row'] : $this->sources->read($source, $record->source_id))) : null;
            if ($row) {
                $item = $this->sources->project($source, $row);
                $sourceRevision = $item['_source_revision'];
                $item = $this->snapshot($item);
                if ($item['status'] === 'history') {
                    $item = $this->retainedClosure($snapshot, $item['source_state']);
                } else {
                    $item['status'] = $record->status === 'history' ? 'history' : $record->status;
                    $context = $this->sources->context($item, $source, $row, $detail);
                }
            } else {
                $item = $snapshot;
                $item['status'] = 'history';
                $item['source_state'] = $record->source_state;
                if (! $historical) {
                    $available = false;
                    $item['source_state'] = 'unavailable';
                    $item['blockers'] = ['Source no longer available; retained review history.'];
                }
            }
        } catch (Throwable) {
            $item = $snapshot;
            $item['status'] = $record->status;
            $item['blockers'] = ['Source or context unavailable; no decision is admitted.'];
            $available = false;
        }
        $item['reference'] = $record->reference;
        $item['source_links'] = [['label' => Str::headline($item['review_type']), 'url' => $item['source_url'], 'source_url' => $item['source_url']]];
        $item['previous_reference'] = $record->previous_reference;
        $item['revision'] = hash('sha256', json_encode([$record->reference, $record->revision, $sourceRevision,
            in_array($item['review_type'], ['face', 'research', GlobalFaceIdentityReviewService::REVIEW_TYPE], true) ? $context : null], JSON_THROW_ON_ERROR));
        $item['actions'] = [];
        if (isset($context['privacy_review']['approval_effect'])) {
            $item['effect'] = $context['privacy_review']['approval_effect'];
        }
        if (isset($context['global_identity_review'])) {
            $item['decision_scope'] = $context['global_identity_review']['decision_scope'] ?? null;
            $item['rejection_scope'] = $context['global_identity_review']['rejection_scope'] ?? null;
        }
        if (isset($context['face_review'])) {
            $item['decision_scope'] = $context['face_review']['decision_scope'];
            $item['rejection_scope'] = $context['face_review']['rejection_scope'];
        }
        if ($available && ($item['enabled'] ?? true) && ! $historical && in_array($item['status'], ['pending', 'later', 'followup'], true)) {
            $item['actions'] = $item['status'] === 'pending' ? ['skip', 'followup'] : ['reopen'];
            $face = $context['face_review'] ?? [];
            if ($item['review_type'] === 'face' && $item['status'] === 'pending' && $face !== []) {
                $item['question'] = $face['operator_question'];
                $item['current'] = 'Unconfirmed identity suggestion';
                $item['proposed'] = $context['item']['suggested_person_name'];
                $item['effect'] = ($face['approval_ready'] ?? false) ? $face['approval_effect'] : $face['rejection_effect'];
                $item['blockers'] = $face['blockers'];
                foreach (['approve', 'reject'] as $action) {
                    if (! empty($item['has_identity_suggestion']) && $this->admitted($item['review_type'], $action, policies: $listPolicies)
                        && ($action === 'reject' ? ($face['rejection_ready'] ?? in_array('reject', $context['item']['actions'] ?? [], true)) : ($face['approval_ready'] ?? false))) {
                        $item['actions'][] = $action;
                    }
                }
                foreach (['refresh_region', 'link', 'false_detection'] as $action) {
                    if ($this->admitted('face', $action, policies: $listPolicies) && ($action !== 'link' || ($face['region_reviewed'] ?? false))) {
                        $item['actions'][] = $action;
                    }
                }
            } elseif ($face !== []) {
                $item['blockers'] = $face['blockers'];
            }
            if ($item['review_type'] === GlobalFaceIdentityReviewService::REVIEW_TYPE && $item['status'] === 'pending') {
                $proposal = $context['global_identity_review'] ?? [];
                $item['title'] = $proposal['canonical_name'] ?? $item['title'];
                foreach (['question', 'current', 'proposed', 'effect', 'blockers'] as $field) {
                    $item[$field] = $proposal[$field] ?? $item[$field];
                }
                foreach (['approve', 'reject'] as $action) {
                    if (! empty($proposal['global_identity_decision']) && ($action !== 'approve' || ($proposal['approval_ready'] ?? false))
                        && $this->admitted($item['review_type'], $action, policies: $listPolicies)) {
                        $item['actions'][] = $action;
                    }
                }
                $item['sensitive'] = true;
                $item['bulk_eligible'] = false;
                $item['approve_label'] = ($proposal['global_identity_decision']['operation'] ?? null) === 'confirm_face'
                    ? 'Confirm this face' : 'Confirm person record';
            }
            if ($item['review_type'] === 'research' && $item['status'] === 'pending' && ! empty($context['research_fact']['source_urls'])) {
                $item['question'] = 'Review fact: '.$context['research_fact']['statement'];
                $item['current'] = 'Pending research fact';
                $item['proposed'] = $context['research_fact']['statement'];
                $item['bulk_actions'] = [];
                foreach (['approve', 'reject'] as $action) {
                    if ($this->admitted('research', $action, $item, $listPolicies)) {
                        $item['actions'][] = $action;
                        $policy = $listPolicies !== null ? ($listPolicies['research'][$action] ?? null)
                            : DB::table('review_desk_policy')->where('review_type', 'research')->where('action', $action)->first();
                        $item['sensitive'] = (bool) $policy->sensitive;
                        if (! $policy->sensitive && $policy->bulk_enabled) {
                            $item['bulk_actions'][] = $action;
                        }
                    }
                }
                $item['bulk_eligible'] = $item['bulk_actions'] !== [];
                $item['effect'] = in_array('approve', $item['actions'], true)
                    ? $context['research_fact']['approval_effect'].' Rejection suppresses future duplicate discoveries.'
                    : $context['research_fact']['rejection_effect'];
                $item['blockers'] = in_array('approve', $item['actions'], true) ? [] : ['Approval requires native research review and may index this fact.'];
            }
            if ($item['review_type'] === 'scan_date_estimate' && $item['status'] === 'pending') {
                foreach (['approve', 'reject'] as $action) {
                    if ($this->admitted('scan_date_estimate', $action, $item, $listPolicies)) {
                        $item['actions'][] = $action;
                    }
                }
                $item['decision_scope'] = 'catalog_date_only';
                $item['rejection_scope'] = 'reject_scan_date_suggestion';
                $item['approve_label'] = 'Accept catalog estimate';
                $item['sensitive'] = true;
                $item['bulk_eligible'] = false;
            }
        }
        if ($detail && $historical && ($item['review_type'] ?? null) === 'scan_date_estimate') {
            $retry = $this->scanDateWriteRetryCandidate($record, $item, $ownedReceiptId);
            if ($retry !== null && $this->admitted('scan_date_estimate', 'retry_scan_year_write', $item)) {
                $item['scan_date_write_retry'] = $retry;
                $item['actions'][] = 'retry_scan_year_write';
                $item['sensitive'] = true;
                $item['bulk_eligible'] = false;
            }
        }
        $item['actionable'] = array_intersect(['approve', 'reject', 'link'], $item['actions']) !== [];
        if (! $historical || ! isset($item['operator_view'], $item['readiness'])) {
            $item = $this->sources->operatorPresentation($item, $context, ! $historical && $item['status'] !== 'history' ? (array) ($row ?? []) : []);
        }
        $item['source_links'] = [['label' => Str::headline($item['review_type']), 'url' => $item['source_url'], 'source_url' => $item['source_url']]];
        $unresolved = $listEvidence['unresolved'] ?? DB::table('review_desk_receipts')->where('reference', $record->reference)
            ->when($ownedReceiptId !== null, fn ($query) => $query->where('id', '!=', $ownedReceiptId))
            ->whereIn('state', ['processing', 'unknown'])->exists();
        if ($unresolved) {
            $item['actions'] = [];
            $item['blockers'][] = 'A submitted decision requires reconciliation before another action.';
            $item['readiness'] = ['state' => 'operational_followup', 'label' => 'Operational follow-up',
                'explanation' => 'Reconcile the submitted decision before taking another action.'];
        }
        $item['actionable'] = array_intersect(['approve', 'reject', 'link', 'refresh_region', 'false_detection'], $item['actions']) !== [];
        $item['requires_source_review'] = ! $item['actionable'] && $item['status'] !== 'history' && ! $unresolved;
        if ($detail) {
            $item['context'] = $context;
            $item['receipts'] = DB::table('review_desk_receipts')->where('reference', $record->reference)
                ->orderBy('created_at')->orderBy('id')->get()->map(fn ($receipt) => $this->receipt($receipt))->all();
            $item['history'] = $item['receipts'];
            $item['feedback'] = DB::table('review_desk_feedback')->where('reference', $record->reference)
                ->get(['id', 'notes', 'queue', 'return_condition', 'status', 'created_at', 'updated_at'])->map(fn ($feedback) => (array) $feedback)->all();
        }

        return $item;
    }

    private function retainedClosure(array $snapshot, string $state): array
    {
        return array_replace($snapshot, [
            'status' => 'history', 'source_state' => $state,
            'blockers' => array_values(array_unique([
                ...($snapshot['blockers'] ?? []),
                'Source closed outside Review Desk; retained snapshot may predate that decision.',
            ])),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function scanDateWriteRetryCandidate(object $record, array $item, ?string $ownedReceiptId = null): ?array
    {
        if (($item['review_type'] ?? null) !== 'scan_date_estimate') {
            return null;
        }

        $authorizations = DB::table('review_desk_receipts')->where('reference', $record->reference)
            ->where('action', 'approve')->orderBy('created_at')->get();
        if ($authorizations->count() !== 1) {
            return null;
        }
        $authorization = $authorizations->first();
        $payload = json_decode((string) $authorization->payload, true);
        $binding = is_array($payload) ? ($payload['scan_date_estimate_write'] ?? null) : null;
        $decisionId = is_array($payload) ? (int) ($payload['date_decision_id'] ?? 0) : 0;
        if ($authorization->state !== 'completed' || ! is_array($payload) || ! is_array($binding)
            || ($payload['id'] ?? null) !== $authorization->id || ($payload['reference'] ?? null) !== $record->reference
            || ($payload['action'] ?? null) !== 'approve' || ! is_string($payload['actor'] ?? null) || trim($payload['actor']) === ''
            || ($payload['success'] ?? false) !== true || ($payload['decision_recorded'] ?? false) !== true
            || ($payload['decision_scope'] ?? null) !== 'write_scan_estimated_year'
            || ($payload['effect_status'] ?? null) !== 'catalog_accepted_write_held'
            || ($payload['metadata_file_written'] ?? null) !== false
            || ! is_string($payload['metadata_writeback_error'] ?? null) || trim($payload['metadata_writeback_error']) === ''
            || $decisionId < 1 || (int) ($binding['file_registry_id'] ?? 0) !== (int) $record->source_id
            || ! is_string($binding['source_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $binding['source_sha256']) !== 1
            || ! is_string($binding['asset_uuid'] ?? null) || ! is_string($binding['source_path'] ?? null)
            || ($binding['proposed_precision'] ?? null) !== 'year') {
            return null;
        }

        $decision = DB::table('file_date_review_decisions')->where('id', $decisionId)->first();
        $current = DB::table('file_registry')->where('id', $record->source_id)->first([
            'status', 'is_scan', 'date_needs_review', 'date_taken_source', 'date_taken', 'date_taken_precision',
            'asset_uuid', 'current_path', 'scan_date', 'exif_written',
        ]);
        if (! $decision || ! $current || $decision->decision !== 'approved'
            || ($decision->decision_scope ?? null) !== 'write_scan_estimated_year'
            || (int) $decision->file_registry_id !== (int) $record->source_id
            || (string) ($decision->authorization_receipt_id ?? '') !== (string) $authorization->id
            || ! $this->sameDate($decision->proposed_date ?? null, $binding['proposed_date'] ?? null)
            || ($decision->proposed_precision ?? 'year') !== 'year'
            || (string) ($decision->authorized_source_sha256 ?? '') !== $binding['source_sha256']
            || (string) ($decision->authorized_asset_uuid ?? '') !== $binding['asset_uuid']
            || (int) ($decision->metadata_file_written ?? 0) === 1
            || trim((string) ($decision->metadata_writeback_error ?? '')) === ''
            || $current->status !== 'active' || (int) $current->is_scan !== 1 || (int) $current->date_needs_review !== 0
            || $current->date_taken_source !== 'user_manual' || (int) ($current->exif_written ?? 0) === 1
            || $current->asset_uuid !== $binding['asset_uuid'] || $current->current_path !== $binding['source_path']
            || ($current->date_taken_precision ?? 'year') !== 'year'
            || ! $this->sameDate($current->date_taken, $binding['proposed_date'] ?? null)
            || ! $this->sameNullableDate($current->scan_date, $binding['scan_date'] ?? null)
            || $this->dateDecisionAfter((int) $record->source_id, $decisionId)) {
            return null;
        }

        $attempts = DB::table('review_desk_receipts')->where('reference', $record->reference)
            ->when($ownedReceiptId !== null, fn ($query) => $query->where('id', '!=', $ownedReceiptId))
            ->where('action', 'retry_scan_year_write')->get(['id', 'reference', 'state', 'payload']);
        foreach ($attempts as $attempt) {
            $attemptPayload = json_decode((string) $attempt->payload, true);
            $attemptBinding = is_array($attemptPayload) ? ($attemptPayload['scan_date_write_retry'] ?? null) : null;
            $knownFailed = $attempt->state === 'failed' && is_array($attemptPayload)
                && ($attemptPayload['id'] ?? null) === $attempt->id
                && ($attemptPayload['reference'] ?? null) === $attempt->reference
                && ($attemptPayload['action'] ?? null) === 'retry_scan_year_write'
                && ($attemptPayload['decision_scope'] ?? null) === 'retry_original_authorized_scan_estimated_year'
                && ($attemptPayload['reference'] ?? null) === $record->reference
                && is_array($attemptBinding) && (int) ($attemptBinding['date_decision_id'] ?? 0) === $decisionId
                && ($attemptBinding['authorization_receipt_id'] ?? null) === $authorization->id
                && ($attemptBinding['source_sha256'] ?? null) === $binding['source_sha256']
                && ($attemptBinding['asset_uuid'] ?? null) === $binding['asset_uuid']
                && is_string($attemptPayload['error'] ?? null) && is_int($attemptPayload['http_status'] ?? null);
            $knownCompletedHold = $attempt->state === 'completed' && is_array($attemptPayload)
                && ($attemptPayload['id'] ?? null) === $attempt->id
                && ($attemptPayload['reference'] ?? null) === $attempt->reference
                && ($attemptPayload['action'] ?? null) === 'retry_scan_year_write'
                && ($attemptPayload['decision_scope'] ?? null) === 'retry_original_authorized_scan_estimated_year'
                && ($attemptPayload['reference'] ?? null) === $record->reference
                && is_array($attemptBinding) && (int) ($attemptBinding['date_decision_id'] ?? 0) === $decisionId
                && ($attemptBinding['authorization_receipt_id'] ?? null) === $authorization->id
                && ($attemptBinding['source_sha256'] ?? null) === $binding['source_sha256']
                && ($attemptBinding['asset_uuid'] ?? null) === $binding['asset_uuid']
                && ($attemptPayload['success'] ?? false) === true
                && ($attemptPayload['metadata_file_written'] ?? null) === false
                && ($attemptPayload['effect_status'] ?? null) === 'retry_write_held'
                && is_string($attemptPayload['metadata_writeback_error'] ?? null)
                && trim($attemptPayload['metadata_writeback_error']) !== '';
            if (! is_array($attemptPayload) || in_array($attempt->state, ['processing', 'unknown'], true)
                || ($attemptPayload['metadata_file_written'] ?? false) === true
                || ! $knownFailed && ! $knownCompletedHold) {
                return null;
            }
        }

        return [
            'eligible' => true,
            'date_decision_id' => $decisionId,
            'authorization_receipt_id' => (string) $authorization->id,
            'source_sha256' => $binding['source_sha256'],
            'asset_uuid' => $binding['asset_uuid'],
            'proposed_date' => $binding['proposed_date'],
            'proposed_precision' => 'year',
            'authorization_created_at' => $authorization->created_at,
            'metadata_writeback_error' => $payload['metadata_writeback_error'],
        ];
    }

    private function dateDecisionAfter(int $fileRegistryId, int $decisionId): bool
    {
        return DB::table('file_date_review_decisions')->where('file_registry_id', $fileRegistryId)
            ->where('id', '>', $decisionId)->exists();
    }

    private function sameDate(mixed $left, mixed $right): bool
    {
        if (! is_string($left) || ! is_string($right)) {
            return false;
        }
        $leftTime = strtotime($left);
        $rightTime = strtotime($right);

        return $leftTime !== false && $rightTime !== false
            && date('Y-m-d H:i:s', $leftTime) === date('Y-m-d H:i:s', $rightTime);
    }

    private function sameNullableDate(mixed $left, mixed $right): bool
    {
        if ($left === null || $left === '') {
            return $right === null || $right === '';
        }

        return $this->sameDate($left, $right);
    }

    private function admitted(string $type, string $action, array $item = [], ?array $policies = null): bool
    {
        $policy = $policies !== null ? ($policies[$type][$action] ?? null)
            : DB::table('review_desk_policy')->where('review_type', $type)->where('action', $action)->first();
        $registered = $this->registry->getType($type);

        if ($type === 'research' && $policy && $policy->enabled) {
            if ($action === 'approve' && ! ($item['policy_facts']['indexed_to_rag'] ?? false)) {
                return false;
            }
            $conditions = json_decode($policy->conditions ?? '[]', true);
            if (! is_array($conditions) || empty($conditions['domain_category'])
                || ($action === 'approve' && empty($conditions['indexed_to_rag']))) {
                return false;
            }
            foreach ($conditions as $field => $values) {
                if (! is_array($values) || ! in_array($item['policy_facts'][$field] ?? null, $values, true)) {
                    return false;
                }
            }

            return $policy->service_class === ResearchReviewService::class
                && $policy->method === ($action === 'approve' ? 'approveFact' : 'rejectFact')
                && ($registered['source_table'] ?? null) === 'research_facts'
                && ($registered['source_connection'] ?? null) === 'pgsql_rag';
        }

        if ($type === GlobalFaceIdentityReviewService::REVIEW_TYPE) {
            $conditions = $policy ? json_decode($policy->conditions ?? '{}', true) : null;

            return in_array($action, ['approve', 'reject'], true) && $policy && $policy->enabled
                && $policy->sensitive && ! $policy->bulk_enabled
                && ($conditions['operator_only'] ?? false) === true
                && $policy->service_class === GlobalFaceIdentityReviewService::class && $policy->method === $action
                && ($registered['source_table'] ?? null) === 'agent_review_queue'
                && ($registered['source_connection'] ?? null) !== 'pgsql_rag'
                && ($registered['service_class'] ?? null) === $policy->service_class
                && ($registered[$action.'_method'] ?? null) === $action;
        }

        if ($type === 'scan_date_estimate') {
            $conditions = $policy ? json_decode($policy->conditions ?? '{}', true) : null;

            if ($action === 'retry_scan_year_write') {
                return $policy && $policy->enabled && $policy->sensitive && ! $policy->bulk_enabled
                    && ($conditions['operator_only'] ?? false) === true
                    && ($conditions['original_authorization_required'] ?? false) === true
                    && ($conditions['current_source_required'] ?? false) === true
                    && $policy->service_class === ScanDateEstimateReviewDecisionService::class
                    && $policy->method === 'retryApprovedWriteback'
                    && ($registered['source_table'] ?? null) === 'file_registry'
                    && ($registered['source_connection'] ?? null) !== 'pgsql_rag'
                    && ($registered['service_class'] ?? null) === $policy->service_class
                    && ! ($registered['batch_enabled'] ?? true);
            }

            return in_array($action, ['approve', 'reject'], true) && $policy && $policy->enabled
                && $policy->sensitive && ! $policy->bulk_enabled && ($conditions['operator_only'] ?? false) === true
                && ($conditions['current_source_required'] ?? false) === true
                && $policy->service_class === ScanDateEstimateReviewDecisionService::class && $policy->method === $action
                && ($registered['source_table'] ?? null) === 'file_registry'
                && ($registered['source_connection'] ?? null) !== 'pgsql_rag'
                && ($registered['service_class'] ?? null) === $policy->service_class
                && ($registered[$action.'_method'] ?? null) === $action && ! ($registered['batch_enabled'] ?? true);
        }

        if ($type === 'face' && in_array($action, ['refresh_region', 'link', 'false_detection'], true)) {
            $conditions = json_decode($policy->conditions ?? '{}', true);

            return $policy && $policy->enabled && ! $policy->bulk_enabled && $policy->sensitive
                && ($conditions['operator_only'] ?? false) === true && ($conditions['current_source_required'] ?? false) === true
                && ($action !== 'refresh_region' || ($conditions['materialize_current_source'] ?? false) === true)
                && $policy->service_class === FaceMatchReviewDecisionService::class
                && $policy->method === match ($action) {
                    'refresh_region' => 'refreshRegion', 'false_detection' => 'dismissFalseDetection', default => 'link'
                }
            && ($registered['source_table'] ?? null) === 'genealogy_face_match_queue'
            && ($registered['source_connection'] ?? null) !== 'pgsql_rag'
            && ($registered['service_class'] ?? null) === $policy->service_class;
        }

        return $type === 'face' && $policy && $policy->enabled
            && $policy->service_class === FaceMatchReviewDecisionService::class && $policy->method === $action
            && ($registered['source_table'] ?? null) === 'genealogy_face_match_queue'
            && ($registered['source_connection'] ?? null) !== 'pgsql_rag'
            && ($registered['service_class'] ?? null) === $policy->service_class
            && ($registered[$action.'_method'] ?? null) === $policy->method;
    }

    public function decide(string $reference, array $request, string $actor = 'operator', bool $batch = false): array
    {
        $action = $request['action'] ?? '';
        if (! in_array($action, ['approve', 'reject', 'skip', 'followup', 'reopen', 'refresh_region', 'link', 'false_detection', 'retry_scan_year_write'], true)
            || ! is_string($request['revision'] ?? null) || empty($request['idempotency_key'])) {
            throw new HttpException(422, 'Action, revision and idempotency_key are required.');
        }
        if ($batch && $action === 'retry_scan_year_write') {
            throw new HttpException(422, 'Scan-date physical write retries are individual decisions only.');
        }
        if ($action === 'followup' && trim((string) ($request['notes'] ?? '')) === '') {
            throw new HttpException(422, 'Follow-up requires a written next step.');
        }
        $digest = hash('sha256', json_encode($this->canonical($request), JSON_THROW_ON_ERROR));
        $claim = DB::transaction(function () use ($reference, $request, $actor, $action, $digest, $batch): array {
            $record = $this->resolve($reference, true);
            if (! $record) {
                throw new HttpException(404, 'Review reference not found.');
            }
            $prior = DB::table('review_desk_receipts')->where('reference', $record->reference)
                ->where('idempotency_key', $request['idempotency_key'])->first();
            if ($prior) {
                if (! hash_equals($prior->request_digest, $digest)) {
                    throw new HttpException(409, 'Idempotency key already binds a different request.');
                }

                return ['prior' => $prior];
            }
            $item = $this->present($record, true);
            $this->validateDecision($item, $request, $batch);
            $receiptId = (string) Str::uuid();
            $payload = [
                'id' => $receiptId, 'reference' => $record->reference, 'action' => $action,
                'revision' => $item['revision'], 'actor' => $actor, 'notes' => $request['notes'] ?? null,
                'reason_code' => $request['reason_code'] ?? null, 'decision_scope' => $request['decision_scope'] ?? null,
                'before' => $item['source_state'], 'after' => null, 'decision_recorded' => false,
                'effect_status' => 'not_started', 'success' => false,
                'person_id' => $request['person_id'] ?? null,
            ];
            if ($item['review_type'] === 'face' && $action === 'refresh_region') {
                $source = $this->sources->sources()[$record->source] ?? null;
                $queue = $source ? $this->sources->read($source, $record->source_id, true) : null;
                if (! $queue || $source['table'] !== 'genealogy_face_match_queue') {
                    throw new HttpException(409, 'Current face review source is unavailable.');
                }
                // validateDecision above compared the request with this exact live
                // queue/context; bind that current source, including after an earlier refresh.
                $record->source_revision = hash('sha256', json_encode((array) $queue, JSON_THROW_ON_ERROR));
                DB::table('review_desk_references')->where('reference', $record->reference)->update([
                    'source_revision' => $record->source_revision,
                ]);
                $payload['current_face_region_decision'] = [
                    'queue_id' => (int) $queue->id, 'tree_id' => (int) $queue->tree_id,
                    'source_revision' => $record->source_revision, 'desk_revision' => (int) $record->revision,
                    'media_id' => (int) ($queue->media_id ?? 0),
                    'source_sha256' => $request['source_sha256'] ?? null,
                    'region' => $request['region'] ?? null,
                    'face_region_convention' => $request['face_region_convention'] ?? null,
                    'coordinate_basis' => 'encoded_pixels',
                ];
            }
            if ($item['review_type'] === GlobalFaceIdentityReviewService::REVIEW_TYPE && in_array($action, ['approve', 'reject'], true)) {
                $payload['global_identity_decision'] = $item['context']['global_identity_review']['global_identity_decision'];
            }
            if ($item['review_type'] === 'scan_date_estimate' && $action === 'approve'
                && ($request['decision_scope'] ?? null) === 'write_scan_estimated_year') {
                $payload['scan_date_estimate_write'] = $this->scanDateWriteBinding($record, $request);
            }
            if ($item['review_type'] === 'scan_date_estimate' && $action === 'retry_scan_year_write') {
                $payload['scan_date_write_retry'] = [
                    'date_decision_id' => (int) $request['date_decision_id'],
                    'authorization_receipt_id' => $request['authorization_receipt_id'],
                    'asset_uuid' => $request['asset_uuid'],
                    'source_sha256' => $request['source_sha256'],
                ];
            }
            DB::table('review_desk_receipts')->insert([
                'id' => $receiptId, 'reference' => $record->reference, 'idempotency_key' => $request['idempotency_key'],
                'request_digest' => $digest, 'action' => $action, 'state' => 'processing',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => $receiptId, 'record' => $record, 'payload' => $payload];
        });
        if (isset($claim['prior'])) {
            $receipt = $this->receipt($claim['prior']);

            return ['success' => $receipt['success'], 'receipt' => $receipt, 'item' => $this->lookup($claim['prior']->reference)];
        }
        try {
            $receipt = DB::transaction(function () use ($claim, $request, $action, $batch, $actor): array {
                $record = $this->resolve($claim['record']->reference, true);
                $source = $this->sources->sources()[$record->source] ?? null;
                if (! $source) {
                    throw new HttpException(409, 'Review source is no longer available.');
                }
                $row = $this->sources->read($source, $record->source_id, $source['connection'] === null);
                if (! $row) {
                    throw new HttpException(409, 'Review source is no longer available.');
                }
                $item = $this->present($record, true, $row, ownedReceiptId: $claim['id']);
                $this->validateDecision($item, $request, $batch, true);
                $payload = $claim['payload'];
                $receiptState = 'completed';
                if (in_array($action, ['approve', 'reject', 'link', 'refresh_region', 'false_detection', 'retry_scan_year_write'], true)) {
                    $result = $this->applyCanonical($source, $record, $item, $request, $actor, $batch, $claim['id']);
                    if (! ($result['success'] ?? false)) {
                        throw new HttpException((int) ($result['http_status'] ?? 422), $result['error'] ?? 'Canonical decision handler declined the request.');
                    }
                    $status = $action === 'refresh_region' ? 'pending' : 'history';
                    $payload['effect_status'] = $action === 'refresh_region' ? 'evidence_refreshed' : 'completed';
                    if (array_key_exists('desk_source_state', $result)) {
                        $payload['after'] = $result['desk_source_state'];
                    } else {
                        $afterRow = $this->sources->read($source, $record->source_id);
                        $payload['after'] = $afterRow?->{$source['status_column']} ?? 'unavailable';
                    }
                    $expectedState = $action === 'refresh_region' ? 'pending' : (in_array($action, ['reject', 'false_detection'], true) ? 'rejected' : 'approved');
                    $payload['decision_recorded'] = $action !== 'refresh_region';
                    $payload['message'] = $result['message'] ?? null;
                    if ($item['review_type'] === 'scan_date_estimate') {
                        $payload['metadata_file_written'] = $result['metadata_file_written'] ?? false;
                        $payload['metadata_writeback_error'] = $result['metadata_writeback_error'] ?? null;
                        if ($action === 'retry_scan_year_write') {
                            $payload['date_decision_id'] = (int) $request['date_decision_id'];
                            $payload['authorization_receipt_id'] = $request['authorization_receipt_id'];
                            $payload['effect_status'] = $result['effect_status'] ?? (! empty($result['metadata_writeback_error']) ? 'retry_write_held' : 'retry_completed');
                        } else {
                            $payload['date_decision_id'] = $result['decision_id'] ?? null;
                            $payload['effect_status'] = ! empty($result['metadata_writeback_error']) ? 'catalog_accepted_write_held' : 'completed';
                        }
                    }
                    if (! empty($result['physical_write_outcome_unknown']) || ! empty($result['write_binding_conflict'])) {
                        $receiptState = 'unknown';
                        $payload['success'] = false;
                        $payload['effect_status'] = ! empty($result['write_binding_conflict'])
                            ? 'physical_write_source_conflict' : 'physical_write_outcome_unknown';
                        $payload['error'] = ! empty($result['write_binding_conflict'])
                            ? 'Physical metadata write completed, but the catalog changed before its receipt could be updated. Reconcile before any further attempt.'
                            : 'Physical write outcome requires reconciliation before any further attempt.';
                    }
                    if ($payload['after'] !== $expectedState) {
                        if ($item['review_type'] === 'scan_date_estimate' && ! empty($result['metadata_file_written'])) {
                            $receiptState = 'unknown';
                            $payload['success'] = false;
                            $payload['effect_status'] = 'physical_write_source_conflict';
                            $payload['error'] = 'Physical metadata write completed, but the current catalog no longer matches the authorized accepted-date state. Reconcile before any further attempt.';
                        } elseif ($receiptState !== 'unknown') {
                            throw new HttpException(409, 'Canonical handler did not close the expected source decision.');
                        }
                    }
                    if ($action === 'refresh_region') {
                        $payload['current_face_record_id'] = $result['file_registry_face_id'] ?? null;
                        $payload['operator_identity_decision'] = false;
                    }
                } else {
                    $status = match ($action) {
                        'skip' => 'later', 'followup' => 'followup', 'reopen' => 'pending'
                    };
                    $payload['effect_status'] = 'presentation_only';
                    $payload['after'] = $item['source_state'];
                    if ($action === 'followup') {
                        DB::table('review_desk_feedback')->insert([
                            'id' => (string) Str::uuid(), 'reference' => $record->reference, 'receipt_id' => $claim['id'],
                            'notes' => $request['notes'], 'return_condition' => $request['return_condition'] ?? $request['notes'],
                            'queue' => 'operator', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $payload['effect_status'] = 'followup_recorded';
                    }
                    if ($action === 'reopen') {
                        DB::table('review_desk_feedback')->where('reference', $record->reference)->where('status', 'open')
                            ->update(['status' => 'returned', 'updated_at' => now()]);
                    }
                }
                $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
                foreach (['question', 'current', 'proposed', 'effect', 'context', 'readiness', 'operator_view', 'title', 'source_url'] as $field) {
                    $snapshot[$field] = $item[$field] ?? null;
                }
                $snapshot['source_state'] = $payload['after'];
                $snapshot['status'] = $status;
                DB::table('review_desk_references')->where('reference', $record->reference)->update([
                    'status' => $status, 'source_state' => $payload['after'], 'revision' => $record->revision + 1,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                if ($receiptState === 'completed') {
                    $payload['success'] = true;
                }

                return $this->finish($claim['id'], $receiptState, $payload);
            });
        } catch (HttpException $exception) {
            $receipt = $this->finish($claim['id'], 'failed', $claim['payload'] + ['error' => $exception->getMessage(), 'http_status' => $exception->getStatusCode()]);
        } catch (Throwable) {
            $payload = $claim['payload'];
            $payload['effect_status'] = 'unknown';
            $payload['error'] = 'Outcome requires reconciliation; this request will not be executed again.';
            $receipt = $this->finish($claim['id'], 'unknown', $payload);
        }

        return ['success' => $receipt['success'], 'receipt' => $receipt, 'item' => $this->lookup($claim['record']->reference)];
    }

    private function validateDecision(array $item, array $request, bool $batch, bool $claimed = false): void
    {
        if (! ($item['enabled'] ?? true)) {
            throw new HttpException(409, 'Review type is disabled.');
        }
        if (! hash_equals($item['revision'], $request['revision'])) {
            throw new HttpException(409, 'Source or review revision changed; refresh before deciding.');
        }
        $action = $request['action'];
        if (in_array($action, ['refresh_region', 'link', 'false_detection'], true)) {
            if ($batch || $item['review_type'] !== 'face' || $item['status'] !== 'pending'
                || ! $this->admitted('face', $action)) {
                throw new HttpException(422, 'This action requires an individual pending picture review.');
            }
            $scope = match ($action) {
                'refresh_region' => 'review_current_face_region', 'false_detection' => 'dismiss_false_face_detection', default => 'confirm_face_identity_link'
            };
            if (($request['decision_scope'] ?? null) !== $scope || ($action === 'link' && (int) ($request['person_id'] ?? 0) <= 0)) {
                throw new HttpException(422, 'Explicit picture review scope and selected person are required.');
            }
        }
        if ($batch) {
            $policy = DB::table('review_desk_policy')->where('review_type', $item['review_type'])->where('action', $action)->first();
            if ($item['sensitive'] || ! $item['bulk_eligible'] || ! $policy?->bulk_enabled || ! $policy->enabled || $policy->sensitive) {
                throw new HttpException(422, 'This source/action is not admitted for bulk decisions.');
            }
        }
        if (! $claimed && ! in_array($action, $item['actions'], true)) {
            throw new HttpException(409, 'Action unavailable; use the source handoff or reconcile the prior receipt.');
        }
        if ($claimed && $action === 'retry_scan_year_write' && $item['status'] !== 'history') {
            throw new HttpException(409, 'The original accepted scan-date write is no longer available for retry.');
        }
        if ($claimed && $action !== 'retry_scan_year_write' && ! in_array($item['status'], ['pending', 'followup', 'later'], true)) {
            throw new HttpException(409, 'Source is no longer pending.');
        }
        if ($action === 'retry_scan_year_write') {
            $retry = $item['scan_date_write_retry'] ?? null;
            if ($batch || $item['review_type'] !== 'scan_date_estimate' || $item['status'] !== 'history'
                || ($request['decision_scope'] ?? null) !== 'retry_original_authorized_scan_estimated_year'
                || ! is_array($retry) || ($retry['eligible'] ?? false) !== true
                || (int) ($request['date_decision_id'] ?? 0) !== (int) $retry['date_decision_id']
                || ($request['authorization_receipt_id'] ?? null) !== $retry['authorization_receipt_id']
                || ($request['asset_uuid'] ?? null) !== $retry['asset_uuid']
                || ! is_string($request['source_sha256'] ?? null)
                || ! hash_equals($retry['source_sha256'], $request['source_sha256'])) {
                throw new HttpException(409, 'Retry requires the exact original physical approval, accepted year, and source bytes. Changed bytes require fresh physical consent.');
            }
            if (! $this->admitted('scan_date_estimate', $action, $item)) {
                throw new HttpException(422, 'Scan-date write retry is not admitted by policy.');
            }

            return;
        }
        if (in_array($action, ['approve', 'reject'], true)) {
            if (! $this->admitted($item['review_type'], $action, $item)) {
                throw new HttpException(422, 'Native review or current visible evidence is required.');
            }
            if ($item['review_type'] === 'scan_date_estimate') {
                $scope = $request['decision_scope'] ?? null;
                if ($batch || ($action === 'approve' ? ! in_array($scope, ['catalog_date_only', 'write_scan_estimated_year'], true)
                    : $scope !== 'reject_scan_date_suggestion')) {
                    throw new HttpException(422, 'Choose an explicit individual scan-date outcome.');
                }
                if ($scope === 'write_scan_estimated_year') {
                    if (! is_string($request['source_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $request['source_sha256'])
                        || ! is_string($request['asset_uuid'] ?? null) || ! Str::isUuid($request['asset_uuid'])) {
                        throw new HttpException(422, 'Current source SHA and asset UUID are required for a physical year write.');
                    }
                } elseif (isset($request['source_sha256']) || isset($request['asset_uuid'])) {
                    throw new HttpException(422, 'Catalog-only and rejection decisions do not authorize a physical file write.');
                }

                return;
            }
            if ($item['review_type'] === GlobalFaceIdentityReviewService::REVIEW_TYPE) {
                $proposal = $item['context']['global_identity_review'] ?? [];
                $scope = $action === 'approve' ? ($proposal['decision_scope'] ?? null) : ($proposal['rejection_scope'] ?? null);
                if ($batch || ! is_string($scope) || ($request['decision_scope'] ?? null) !== $scope
                    || empty($proposal['global_identity_decision']) || ($action === 'approve' && ! ($proposal['approval_ready'] ?? false))) {
                    throw new HttpException(422, 'Exact individual person registry proposal and operator scope required.');
                }

                return;
            }
            if ($item['review_type'] === 'research') {
                if (empty($item['context']['research_fact']['source_urls'])) {
                    throw new HttpException(422, 'Research fact evidence is required.');
                }
                if ($action === 'approve' && ! $this->sources->researchEvidenceReady($item['context']['research_fact'])) {
                    throw new HttpException(422, 'Supporting research excerpts or citations are required.');
                }

                return;
            }
            if (empty($item['has_identity_suggestion'])
                || ($action === 'reject' && ! ($item['context']['face_review']['rejection_ready'] ?? in_array('reject', $item['context']['item']['actions'] ?? [], true)))) {
                throw new HttpException(422, 'There is no identity suggestion eligible for this decision.');
            }
            if ($action === 'approve' && ! ($item['context']['face_review']['approval_ready'] ?? false)) {
                throw new HttpException(422, 'Current visible face evidence is required for approval.');
            }
            $scope = $action === 'approve' ? 'confirm_face_identity_link' : 'reject_face_identity_suggestion';
            if (($request['decision_scope'] ?? null) !== $scope) {
                throw new HttpException(422, 'Explicit face decision_scope is required.');
            }
        }
    }

    private function scanDateWriteBinding(object $record, array $request): array
    {
        if ($record->source !== 'scan_date_estimate') {
            throw new HttpException(409, 'Exact scan-date review source is required.');
        }
        $row = DB::table('file_registry')->where('id', $record->source_id)->lockForUpdate()->first();
        if (! $row || $row->status !== 'active' || (int) $row->is_scan !== 1 || (int) $row->date_needs_review !== 1
            || ($row->date_taken_precision ?? 'year') !== 'year' || ! $row->date_taken
            || $row->asset_uuid !== ($request['asset_uuid'] ?? null) || ! is_string($row->current_path)) {
            throw new HttpException(409, 'Current scanned photo and single-year proposal are required.');
        }
        $proof = app(FaceLinkBridgeService::class)->reviewSourcePath($row->current_path, $request['source_sha256'], true);
        if (! ($proof['success'] ?? false)) {
            throw new HttpException(409, 'Source photo changed or cannot be verified; reload its evidence before approval.');
        }

        return ['file_registry_id' => (int) $row->id, 'asset_uuid' => $row->asset_uuid, 'source_path' => $row->current_path,
            'source_sha256' => $proof['source_sha256'], 'proposed_date' => $row->date_taken,
            'proposed_precision' => $row->date_taken_precision ?? 'year', 'scan_date' => $row->scan_date];
    }

    private function applyCanonical(array $source, object $record, array $item, array $request, string $actor, bool $batch, string $receiptId): array
    {
        if ($item['review_type'] === 'scan_date_estimate') {
            $receipt = DB::table('review_desk_receipts')->where('id', $receiptId)->first();
            $payload = $receipt ? json_decode($receipt->payload, true) : [];
            $meta = array_replace($request, ['actor' => $actor, 'receipt_id' => $receiptId,
                'scan_date_estimate_write' => $payload['scan_date_estimate_write'] ?? null]);
            $service = app(ScanDateEstimateReviewDecisionService::class);
            if ($request['action'] === 'retry_scan_year_write') {
                $result = $service->retryApprovedWriteback((int) $record->source_id, $request['notes'] ?? null, $meta);
                if ($result['success'] ?? false) {
                    try {
                        $current = $this->sources->read($source, $record->source_id);
                        $result['desk_source_state'] = $current ? $this->sources->project($source, $current)['source_state'] : 'unavailable';
                    } catch (Throwable) {
                        if (empty($result['metadata_file_written']) && empty($result['physical_write_outcome_unknown'])) {
                            throw new HttpException(409, 'Accepted date source is unavailable after the physical-write attempt.');
                        }
                        $result['desk_source_state'] = 'unavailable';
                    }
                }

                return $result;
            }
            $result = $request['action'] === 'approve' ? $service->approve((int) $record->source_id, $request['notes'] ?? null, $meta)
                : $service->reject((int) $record->source_id, $request['notes'] ?? null, $meta);
            if ($result['success'] ?? false) {
                try {
                    $current = $this->sources->read($source, $record->source_id);
                    $result['desk_source_state'] = $current ? $this->sources->project($source, $current)['source_state'] : 'unavailable';
                } catch (Throwable) {
                    if (empty($result['metadata_file_written']) && empty($result['physical_write_outcome_unknown'])) {
                        throw new HttpException(409, 'Accepted date source is unavailable after the physical-write attempt.');
                    }
                    $result['desk_source_state'] = 'unavailable';
                }
            }

            return $result;
        }
        if ($item['review_type'] === GlobalFaceIdentityReviewService::REVIEW_TYPE) {
            $service = app(GlobalFaceIdentityReviewService::class);
            $meta = array_replace($request, ['actor' => $actor, 'receipt_id' => $receiptId]);

            return $request['action'] === 'approve'
                ? $service->approve((int) $record->source_id, $request['notes'] ?? null, $meta)
                : $service->reject((int) $record->source_id, $request['notes'] ?? null, $meta);
        }
        if ($item['review_type'] === 'face' && in_array($request['action'], ['refresh_region', 'link', 'false_detection'], true)) {
            $service = app(FaceMatchReviewDecisionService::class);
            $meta = array_replace($request, ['actor' => $actor, 'receipt_id' => $receiptId]);

            return match ($request['action']) {
                'refresh_region' => $service->refreshRegion((int) $record->source_id, $request['notes'] ?? null, $meta),
                'false_detection' => $service->dismissFalseDetection((int) $record->source_id, $request['notes'] ?? null, $meta),
                default => $service->link((int) $record->source_id, (int) $request['person_id'], $request['notes'] ?? null, $meta),
            };
        }
        if ($item['review_type'] === 'research') {
            return $this->sources->connection($source)->transaction(function () use ($source, $record, $request, $actor, $batch): array {
                $row = $this->sources->read($source, $record->source_id, true);
                if (! $row) {
                    throw new HttpException(409, 'Research fact no longer available.');
                }
                $this->sources->connection($source)->table('research_missions')->where('id', $row->mission_id)->lockForUpdate()->first();
                $this->validateDecision($this->present($record, true, $row), $request, $batch, true);
                $service = app(ResearchReviewService::class);
                $result = $request['action'] === 'approve'
                    ? $service->approveFact($record->source_id, $actor)
                    : $service->rejectFact($record->source_id, $request['notes'] ?? null, $actor);
                if (! ($result['success'] ?? false)) {
                    throw new HttpException(422, 'Canonical research handler declined the request.');
                }
                $expected = $request['action'] === 'approve' ? 'approved' : 'rejected';
                if ($this->sources->read($source, $record->source_id)->review_status !== $expected) {
                    throw new HttpException(409, 'Research handler did not close the expected decision.');
                }

                return $result + ['desk_source_state' => $expected];
            });
        }

        return $request['action'] === 'approve'
            ? $this->registry->approveItem($item['unified_id'], $request['notes'] ?? null, $request['reason_code'] ?? null, $request)
            : $this->registry->rejectItem($item['unified_id'], $request['notes'] ?? null, $request['reason_code'] ?? null, $request);
    }

    public function batch(array $request, string $actor = 'operator'): array
    {
        $results = [];
        foreach ($request['items'] as $item) {
            try {
                $results[] = ['reference' => $item['reference']] + $this->decide($item['reference'], [
                    'action' => $request['action'], 'revision' => $item['revision'],
                    'idempotency_key' => $item['idempotency_key'], 'notes' => $request['notes'] ?? null,
                ], $actor, true);
            } catch (HttpException $exception) {
                $results[] = ['reference' => $item['reference'], 'success' => false, 'receipt' => null,
                    'status' => $exception->getStatusCode(), 'error' => $exception->getMessage()];
            } catch (Throwable) {
                $results[] = ['reference' => $item['reference'], 'success' => false, 'receipt' => null,
                    'status' => 503, 'error' => 'Outcome unavailable; reconcile before retry.'];
            }
        }

        return ['results' => $results, 'success' => ! in_array(false, array_column($results, 'success'), true)];
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = $this->canonical($entry);
            }
        }

        return $value;
    }

    private function finish(string $id, string $state, array $payload): array
    {
        DB::table('review_desk_receipts')->where('id', $id)->update([
            'state' => $state, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);

        return $this->receipt(DB::table('review_desk_receipts')->where('id', $id)->first());
    }

    private function receipt(object $receipt): array
    {
        $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
        unset($payload['scan_date_estimate_write']['source_path']);

        return $payload + [
            'state' => $receipt->state, 'created_at' => $receipt->created_at, 'updated_at' => $receipt->updated_at,
        ];
    }
}
