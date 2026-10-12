<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GlobalFaceIdentityService;
use App\Services\Review\GlobalFaceIdentityReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Lookup confirmed individuals and stage operator intents; approval belongs to Review Desk. */
class GlobalFaceIdentityController extends Controller
{
    public function __construct(private readonly GlobalFaceIdentityReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'limit' => 'sometimes|integer|min:1|max:50']);
        abort_if(array_diff(array_keys($request->all()), ['q', 'limit']) !== [], 422, 'Unsupported lookup fields.');
        $query = DB::table('global_face_identities as identity')->where('identity.status', 'operator_confirmed');
        if (($filters['q'] ?? '') !== '') {
            $pattern = '%'.$filters['q'].'%';
            $query->where(function ($query) use ($pattern): void {
                $query->where('identity.canonical_name', 'like', $pattern)->orWhereExists(function ($aliases) use ($pattern): void {
                    $aliases->selectRaw('1')->from('global_face_identity_aliases as alias')
                        ->whereColumn('alias.identity_uuid', 'identity.identity_uuid')->where('alias.alias', 'like', $pattern);
                });
            });
        }
        $rows = $query->orderBy('identity.canonical_name')->orderBy('identity.identity_uuid')
            ->limit($filters['limit'] ?? 20)->get(['identity.identity_uuid', 'identity.canonical_name']);

        return response()->json(['identities' => $this->hydrate($rows->all())], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(string $uuid): JsonResponse
    {
        abort_unless(Str::isUuid($uuid), 422, 'A stable individual record identifier is required.');
        $identity = DB::table('global_face_identities')->where('identity_uuid', $uuid)->where('status', 'operator_confirmed')
            ->first(['identity_uuid', 'canonical_name']);
        abort_unless($identity, 404, 'Individual record unavailable.');

        return response()->json(['identity' => $this->hydrate([$identity])[0]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function stage(Request $request): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user, 401, 'Operator login required.');
        $actor = is_string($user->email ?? null) && trim($user->email) !== '' ? $user->email : 'operator:'.$user->getAuthIdentifier();
        $base = $request->validate(['operation' => 'required|in:create_identity,add_alias,map_genealogy,confirm_face', 'request_key' => 'required|uuid']);
        $operation = $base['operation'];
        $fields = match ($operation) {
            'create_identity' => ['canonical_name'], 'add_alias' => ['identity_uuid', 'alias'],
            'map_genealogy' => ['identity_uuid', 'tree_id', 'genealogy_person_id'],
            'confirm_face' => ['identity_uuid', 'face_id', 'source_sha256', 'region', 'face_region_convention', 'coordinate_basis'],
        };
        abort_if(array_diff(array_keys($request->all()), ['operation', 'request_key', ...$fields]) !== [], 422, 'Unsupported proposal fields.');
        $name = ['required', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u',
            function (string $field, mixed $value, \Closure $fail): void {
                if (! is_string($value) || trim($value) === '' || trim($value) !== $value) {
                    $fail('Choose a nonempty name without surrounding whitespace.');
                }
            }];
        $rules = match ($operation) {
            'create_identity' => ['canonical_name' => $name],
            'add_alias' => ['identity_uuid' => 'required|uuid', 'alias' => $name],
            'map_genealogy' => ['identity_uuid' => 'required|uuid', 'tree_id' => 'required|integer|min:1', 'genealogy_person_id' => 'required|integer|min:1'],
            'confirm_face' => ['identity_uuid' => 'required|uuid', 'face_id' => 'required|integer|min:1',
                'source_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'region' => 'required|array:x,y,w,h',
                'region.x' => 'required|numeric', 'region.y' => 'required|numeric', 'region.w' => 'required|numeric', 'region.h' => 'required|numeric',
                'face_region_convention' => 'required|in:normalized_top_left', 'coordinate_basis' => 'required|in:encoded_pixels'],
        };
        $decision = ['operation' => $operation, ...$request->validate($rules)];
        if ($operation === 'create_identity') {
            $prior = DB::table('agent_review_queue')->where('token', $base['request_key'])
                ->where('review_type', GlobalFaceIdentityReviewService::REVIEW_TYPE)->first(['details']);
            $priorDetails = $prior ? json_decode($prior->details, true) : [];
            $priorDecision = ($priorDetails['staged_by'] ?? null) === $actor ? ($priorDetails['global_identity_decision'] ?? []) : [];
            $priorUuid = $priorDecision['identity_uuid'] ?? null;
            $decision['identity_uuid'] = ($priorDecision['operation'] ?? null) === 'create_identity'
                && is_string($priorUuid) && Str::isUuid($priorUuid) ? $priorUuid : (string) Str::uuid();
        } else {
            abort_unless(DB::table('global_face_identities')->where('identity_uuid', $decision['identity_uuid'])
                ->where('status', 'operator_confirmed')->exists(), 404, 'Selected individual record unavailable.');
        }
        if ($operation === 'map_genealogy') {
            $decision['tree_id'] = (int) $decision['tree_id'];
            $decision['genealogy_person_id'] = (int) $decision['genealogy_person_id'];
            abort_unless(DB::table('genealogy_persons')->where('id', $decision['genealogy_person_id'])
                ->where('tree_id', $decision['tree_id'])->exists(), 422, 'Choose a person from the selected family tree.');
            $priorRequest = DB::table('agent_review_queue')->where('token', $base['request_key'])
                ->where('review_type', GlobalFaceIdentityReviewService::REVIEW_TYPE)->exists();
            abort_if(! $priorRequest && DB::table('global_face_identity_genealogy_links')->where('tree_id', $decision['tree_id'])
                ->where('genealogy_person_id', $decision['genealogy_person_id'])->exists(), 409, 'This tree person already has a mapping; review the existing record.');
        }
        if ($operation === 'confirm_face') {
            $decision['face_id'] = (int) $decision['face_id'];
            // Coordinate types remain exact: validateIntent rejects strings and nonfinite numbers.
        }
        try {
            $result = $this->reviews->stage($decision, GlobalFaceIdentityService::SCOPES[$operation], $actor, $base['request_key']);
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json($result + ['review_url' => '/reviews?ref='.rawurlencode($result['unified_id'])], 201,
            ['Cache-Control' => 'private, no-store']);
    }

    private function hydrate(array $rows): array
    {
        $uuids = array_map(fn ($row) => $row->identity_uuid, $rows);
        if ($uuids === []) {
            return [];
        }
        $aliases = DB::table('global_face_identity_aliases')->whereIn('identity_uuid', $uuids)->orderBy('alias')->get()->groupBy('identity_uuid');
        $mappings = DB::table('global_face_identity_genealogy_links as mapping')
            ->leftJoin('genealogy_trees as tree', 'tree.id', '=', 'mapping.tree_id')
            ->leftJoin('genealogy_persons as person', function ($join): void {
                $join->on('person.id', '=', 'mapping.genealogy_person_id')->on('person.tree_id', '=', 'mapping.tree_id');
            })->whereIn('mapping.identity_uuid', $uuids)->orderBy('mapping.tree_id')->orderBy('mapping.genealogy_person_id')
            ->get(['mapping.identity_uuid', 'mapping.tree_id', 'mapping.genealogy_person_id', 'tree.name as tree_name', 'person.given_name', 'person.surname'])
            ->groupBy('identity_uuid');

        return array_map(fn ($row) => ['identity_uuid' => $row->identity_uuid, 'canonical_name' => $row->canonical_name,
            'aliases' => ($aliases[$row->identity_uuid] ?? collect())->pluck('alias')->all(),
            'mappings' => ($mappings[$row->identity_uuid] ?? collect())->map(fn ($mapping) => [
                'tree_id' => (int) $mapping->tree_id, 'tree_name' => $mapping->tree_name,
                'genealogy_person_id' => (int) $mapping->genealogy_person_id,
                'person_name' => trim(($mapping->given_name ?? '').' '.($mapping->surname ?? '')),
            ])->all()], $rows);
    }
}
