<?php

namespace App\Services\Review;

use App\Services\Genealogy\FaceLinkBridgeService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Discovery and evidence only. Identity changes use the existing operator proposal workflow. */
class RegistryFaceReviewService
{
    public const TYPE = 'registry_face';

    public const SOURCE = 'default:file_registry_faces';

    public function pending(): Builder
    {
        // DISTINCT forces one derived proposal set per query. Keep the JSON value's
        // type and compare it exactly as before; casting IDs or NOT IN changes null
        // and malformed-value behavior. This also avoids scanning unrelated pending
        // agent reviews once for every anonymous face.
        $proposals = DB::table('agent_review_queue as proposal')->distinct()
            ->selectRaw("JSON_EXTRACT(proposal.details, '$.global_identity_decision.face_id') AS face_id")
            ->where('proposal.review_type', GlobalFaceIdentityReviewService::REVIEW_TYPE)->where('proposal.status', 'pending')
            ->where(fn ($expiry) => $expiry->whereNull('proposal.expires_at')->orWhere('proposal.expires_at', '>', now()))
            ->whereRaw("JSON_EXTRACT(proposal.details, '$.global_identity_decision.operation') = ?", ['confirm_face']);

        return DB::table('file_registry_faces as face')->join('file_registry as file', 'file.id', '=', 'face.file_registry_id')
            ->leftJoinSub($proposals, 'pending_face_proposals', fn ($join) => $join->on('pending_face_proposals.face_id', '=', 'face.id'))
            ->whereNull('pending_face_proposals.face_id')
            ->where('file.status', 'active')->where('face.source_current', 1)->whereNull('face.superseded_at')
            ->whereRaw('COALESCE(face.hidden, 0) = 0')->whereRaw('COALESCE(face.verified, 0) = 0')
            ->whereRaw("COALESCE(TRIM(face.person_name), '') = ''")->whereNull('face.global_identity_uuid')
            ->whereNull('face.global_identity_approval_receipt_id')->whereNull('face.genealogy_person_id')
            ->whereRaw("LENGTH(face.source_sha256) = 64 AND face.source_sha256 REGEXP '^[a-f0-9]{64}$'")
            ->whereRaw('CAST(face.source_sha256 AS BINARY) = CAST(LOWER(face.source_sha256) AS BINARY)')
            ->where('face.face_region_convention', 'normalized_top_left')
            ->where('face.region_x', '>=', 0)->where('face.region_y', '>=', 0)
            ->where('face.region_w', '>', 0)->where('face.region_h', '>', 0)
            ->whereRaw('face.region_x + face.region_w <= 1')->whereRaw('face.region_y + face.region_h <= 1')
            ->whereNotNull('file.current_path')->whereRaw("TRIM(file.current_path) <> ''")
            ->whereNotExists(function (Builder $dismissed): void {
                $dismissed->selectRaw('1')->from('genealogy_face_match_queue as dismissed')
                    ->whereColumn('dismissed.file_registry_face_id', 'face.id')
                    ->whereExists(function (Builder $hits): void {
                        $json = "CASE WHEN JSON_VALID(dismissed.match_details) THEN dismissed.match_details ELSE '{}' END";
                        if (DB::connection()->getDriverName() === 'sqlite') {
                            $hits->fromRaw("json_each($json, '$.false_detection_reviews') as dismissal_hit")
                                ->whereRaw("JSON_EXTRACT(dismissal_hit.value, '$.current_evidence.source_sha256') = face.source_sha256");
                            foreach (['x', 'y', 'w', 'h'] as $axis) {
                                $hits->whereRaw("ABS(JSON_EXTRACT(dismissal_hit.value, '$.current_evidence.region.$axis') - face.region_$axis) <= CAST(? AS DECIMAL(20,12))", [0.00000001]);
                            }
                        } else {
                            $columns = ["source_sha256 VARCHAR(64) PATH '$.current_evidence.source_sha256'"];
                            foreach (['x', 'y', 'w', 'h'] as $axis) {
                                $columns[] = "region_$axis DOUBLE PATH '$.current_evidence.region.$axis' NULL ON ERROR";
                            }
                            $hits->fromRaw("JSON_TABLE($json, '$.false_detection_reviews[*]' COLUMNS (".implode(', ', $columns).')) as dismissal_hit')
                                ->whereColumn('dismissal_hit.source_sha256', 'face.source_sha256');
                            foreach (['x', 'y', 'w', 'h'] as $axis) {
                                $hits->whereRaw("ABS(dismissal_hit.region_$axis - face.region_$axis) <= CAST(? AS DECIMAL(20,12))", [0.00000001]);
                            }
                        }
                        $hits->selectRaw('1');
                    });
            })
            // Existing family-tree work retains its own region review and identity gates.
            ->whereNotExists(fn (Builder $queue) => $queue->selectRaw('1')->from('genealogy_face_match_queue as queue')
                ->whereColumn('queue.file_registry_face_id', 'face.id')->where('queue.status', 'pending'));
    }

    public function excludeDeferred(Builder $query, string $source, string $idColumn): Builder
    {
        return $query->whereNotExists(fn (Builder $held) => $held->selectRaw('1')->from('review_desk_references as desk')
            ->where('desk.source', $source)->whereRaw('desk.source_id = CAST('.$idColumn.' AS CHAR)')
            ->whereIn('desk.status', ['later', 'followup'])
            ->whereNotExists(fn (Builder $newer) => $newer->selectRaw('1')->from('review_desk_references as newer')
                ->whereColumn('newer.source', 'desk.source')->whereColumn('newer.source_id', 'desk.source_id')
                ->whereColumn('newer.generation', '>', 'desk.generation')));
    }

    public function proof(int $id): array
    {
        $face = $this->pending()->where('face.id', $id)->select('face.*', 'file.status as file_status', 'file.current_path')->first();
        if (! $face) {
            throw new RuntimeException('This face has changed or already has a naming proposal.');
        }
        $sources = app(FaceLinkBridgeService::class);
        $evidence = $sources->validateFaceSourceEvidence($face);
        if (! ($evidence['success'] ?? false)) {
            throw new RuntimeException('The current face and source photo could not be verified.');
        }
        $sources->assertFaceRegionNotDismissed($face);
        $proof = $sources->reviewSourcePath($face->current_path, $evidence['source_sha256'], true);
        if (! ($proof['success'] ?? false)) {
            throw new RuntimeException('The current source photo is unavailable.');
        }
        // Detect replacement during the source read, including changes to the selected box.
        $current = $this->pending()->where('face.id', $id)->select('face.*', 'file.current_path')->first();
        if (! $current || $current->current_path !== $face->current_path || $current->source_sha256 !== $face->source_sha256) {
            throw new RuntimeException('The face or source changed during review.');
        }
        foreach (['x', 'y', 'w', 'h', 'convention'] as $axis) {
            $column = $axis === 'convention' ? 'face_region_convention' : 'region_'.$axis;
            if ($current->{$column} !== $face->{$column}) {
                throw new RuntimeException('The selected face changed during review.');
            }
        }
        $region = $evidence['region'];
        $hash = $evidence['source_sha256'];
        $fingerprint = hash('sha256', $hash.json_encode($region, JSON_THROW_ON_ERROR));
        $globalFace = ['face_id' => $id, 'file_status' => 'active', 'source_current' => true, 'superseded_at' => null,
            'hidden' => false, 'source_sha256' => $hash, 'face_region_convention' => 'normalized_top_left',
            'global_face_evidence' => ['face_id' => $id, 'source_sha256' => $hash, 'region' => $region,
                'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
                'region_fingerprint' => $fingerprint, 'crop_url' => '/api/media/faces/registry-crop/'.$id.'?source_sha256='.$hash.'&region_fingerprint='.$fingerprint]];

        return ['success' => true, 'face_id' => $id, 'source_sha256' => $hash, 'source_dimensions' => $proof['source_dimensions'],
            'coordinate_basis' => 'encoded_pixels', 'face_region_convention' => 'normalized_top_left', 'region' => $region,
            'preview_url' => '/api/media/photo-evidence/preview?'.http_build_query(['face_id' => $id, 'source_sha256' => $hash]),
            'global_face' => $globalFace];
    }
}
