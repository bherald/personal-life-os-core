<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteGenealogyFaceImportScan;
use App\Jobs\ExecuteGenealogyFolderScan;
use App\Jobs\ExecuteGenealogyMediaPathSync;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\Genealogy\FANClusterService;
use App\Services\Genealogy\GedcomExportService;
use App\Services\Genealogy\GenealogyAIResearchService;
use App\Services\Genealogy\GenealogyIntakeApprovalApplyService;
use App\Services\Genealogy\GenealogyIntakeApprovalApplySummaryService;
use App\Services\Genealogy\GenealogyIntakeApprovalDraftPreviewService;
use App\Services\Genealogy\GenealogyIntakeGeneratedProposalQueryService;
use App\Services\Genealogy\GenealogyIntakeProposalDraftService;
use App\Services\Genealogy\GenealogyIntakeProposalGenerationPersistenceService;
use App\Services\Genealogy\GenealogyIntakeProposalGenerationSummaryService;
use App\Services\Genealogy\GenealogyIntakeProposalQueueService;
use App\Services\Genealogy\GenealogyIntakeRunStageService;
use App\Services\Genealogy\GenealogyIntakeRunStoreService;
use App\Services\Genealogy\GenealogyIntakeRunSummaryService;
use App\Services\Genealogy\GenealogyIntakeSelectedPacketComposer;
use App\Services\Genealogy\GenealogyIntakeWorkspaceOverviewService;
use App\Services\Genealogy\GenealogyIntakeWorkspaceService;
use App\Services\Genealogy\GenealogyMediaEnrichmentService;
use App\Services\Genealogy\GenealogyMediaService;
use App\Services\Genealogy\GenealogyPdfService;
use App\Services\Genealogy\GenealogyResearchService;
use App\Services\Genealogy\GenealogyService;
use App\Services\Genealogy\GenealogyStagedPacketPreviewService;
use App\Services\Genealogy\NewspaperSearchService;
use App\Services\Genealogy\Providers\GenealogyProviderManager;
use App\Services\Genealogy\SourceAudit\SourceAuditWorkbookService;
use App\Services\RAGService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * E20: Genealogy Controller
 *
 * API endpoints for family tree management.
 * Supports multiple trees, GEDCOM import, person/family CRUD, and media management.
 *
 * @see docs/future-enhancements.md E20
 */
class GenealogyController extends Controller
{
    private GenealogyService $genealogyService;

    private GenealogyMediaService $mediaService;

    private GenealogyPdfService $pdfService;

    private GedcomExportService $gedcomExportService;

    private FANClusterService $fanClusterService;

    public function __construct(
        GenealogyService $genealogyService,
        GenealogyMediaService $mediaService,
        GenealogyPdfService $pdfService,
        GedcomExportService $gedcomExportService,
        FANClusterService $fanClusterService
    ) {
        $this->genealogyService = $genealogyService;
        $this->mediaService = $mediaService;
        $this->pdfService = $pdfService;
        $this->gedcomExportService = $gedcomExportService;
        $this->fanClusterService = $fanClusterService;
    }

    // ========================================================================
    // TREE MANAGEMENT
    // ========================================================================

    /**
     * Get all family trees
     */
    public function listTrees(): JsonResponse
    {
        try {
            $trees = $this->genealogyService->listTrees();

            return response()->json([
                'success' => true,
                'data' => [
                    'trees' => $trees,
                    'count' => count($trees),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list trees', $e);
        }
    }

    /**
     * Get a single tree with statistics
     */
    public function getTree(int $id): JsonResponse
    {
        try {
            $tree = $this->genealogyService->getTree($id);

            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            $stats = $this->genealogyService->getTreeStatistics($id);
            $mediaStatus = $this->mediaService->getImportStatus($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'tree' => $tree,
                    'statistics' => $stats,
                    'media_status' => $mediaStatus,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get tree', $e);
        }
    }

    /**
     * Create a new tree
     */
    public function createTree(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $treeId = $this->genealogyService->createTree(
                $request->input('name'),
                $request->input('description')
            );

            $tree = $this->genealogyService->getTree($treeId);

            return response()->json([
                'success' => true,
                'data' => ['tree' => $tree],
                'message' => 'Tree created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create tree', $e);
        }
    }

    /**
     * Update a tree
     */
    public function updateTree(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
            ]);

            $updated = $this->genealogyService->updateTree($id, $request->only(['name', 'description']));

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found or no changes made'],
                ], 404);
            }

            $tree = $this->genealogyService->getTree($id);

            return response()->json([
                'success' => true,
                'data' => ['tree' => $tree],
                'message' => 'Tree updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update tree', $e);
        }
    }

    /**
     * Delete a tree and all its data
     */
    public function deleteTree(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteTree($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tree deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete tree', $e);
        }
    }

    // ========================================================================
    // GEDCOM IMPORT
    // ========================================================================

    /**
     * Import a GEDCOM file
     */
    public function importGedcom(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:ged,txt|max:102400', // 100MB max
                'tree_id' => 'nullable|integer|exists:genealogy_trees,id',
                'tree_name' => 'nullable|string|max:255',
            ]);

            // Store uploaded file temporarily
            $file = $request->file('file');
            $tempPath = $file->store('temp/gedcom');
            $fullPath = storage_path('app/'.$tempPath);

            try {
                $result = $this->genealogyService->importGedcom(
                    $fullPath,
                    $request->input('tree_id'),
                    $request->input('tree_name')
                );

                return response()->json([
                    'success' => $result['success'],
                    'data' => $result,
                    'message' => $result['success']
                        ? 'GEDCOM imported successfully'
                        : 'GEDCOM import completed with errors',
                ]);
            } finally {
                // Clean up temp file
                @unlink($fullPath);
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to import GEDCOM', $e);
        }
    }

    /**
     * Export a tree to GEDCOM format
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportGedcom(Request $request, int $treeId)
    {
        try {
            $tree = $this->genealogyService->getTree($treeId);

            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            $options = [
                'include_media' => $request->boolean('include_media', true),
                'include_sources' => $request->boolean('include_sources', true),
                'include_notes' => $request->boolean('include_notes', true),
                'submitter_name' => $request->input('submitter_name', 'PLOS Genealogy Export'),
                'submitter_address' => $request->input('submitter_address'),
                'gedcom_version' => $request->input('gedcom_version', '5.5.1'), // GEN-4: '5.5.1' or '7.0'
            ] + $this->exportPrivacyOptions($request);

            $userId = Auth::id();

            // Check if user wants file download or JSON content
            if ($request->boolean('download', true)) {
                // Return as file download
                $content = $this->gedcomExportService->exportTree($treeId, $userId, $options);
                $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tree->name).'_'.date('Y-m-d').'.ged';

                return response($content)
                    ->header('Content-Type', 'application/x-gedcom')
                    ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                    ->header('Content-Length', strlen($content));
            }

            // Return as JSON with content
            $content = $this->gedcomExportService->exportTree($treeId, $userId, $options);

            return response()->json([
                'success' => true,
                'data' => [
                    'tree_id' => $treeId,
                    'tree_name' => $tree->name,
                    'content' => $content,
                    'filename' => preg_replace('/[^a-zA-Z0-9_-]/', '_', $tree->name).'.ged',
                    'size' => strlen($content),
                    'privacy_context' => $options['privacy_context'],
                    'include_living' => $options['include_living'],
                    'privacy_label' => $options['include_living']
                        ? 'Private full-detail export'
                        : 'Public redacted export',
                ],
                'message' => 'GEDCOM exported successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to export GEDCOM', $e);
        }
    }

    /**
     * N76: Export tree as GEDZip (.gdz) — bundled GEDCOM + media
     */
    public function exportGedZip(Request $request, int $treeId)
    {
        try {
            $tree = $this->genealogyService->getTree($treeId);

            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            $options = [
                'include_media' => $request->boolean('include_media', true),
                'include_sources' => $request->boolean('include_sources', true),
                'include_notes' => $request->boolean('include_notes', true),
                'submitter_name' => $request->input('submitter_name', 'PLOS Genealogy Export'),
            ] + $this->exportPrivacyOptions($request);

            $zipPath = $this->gedcomExportService->exportToGedZip($treeId, null, $options);

            return response()->download($zipPath, basename($zipPath), [
                'Content-Type' => 'application/zip',
            ])->deleteFileAfterSend(true);

        } catch (Exception $e) {
            return $this->errorResponse('Failed to export GEDZip', $e);
        }
    }

    private function exportPrivacyOptions(Request $request): array
    {
        $privacyContext = $this->normalizeExportPrivacyContext($request->input('privacy_context'));
        $redactLiving = $request->has('redact_living')
            ? $request->boolean('redact_living')
            : null;

        if ($privacyContext === null) {
            $privacyContext = $redactLiving === true ? 'public_export' : 'private_local';
        }

        if ($request->has('include_living')) {
            $includeLiving = $request->boolean('include_living');
        } elseif ($redactLiving !== null) {
            $includeLiving = ! $redactLiving;
        } else {
            $includeLiving = $privacyContext === 'public_export' ? false : true;
        }

        return [
            'privacy_context' => $privacyContext,
            'include_living' => $includeLiving,
        ];
    }

    private function normalizeExportPrivacyContext(mixed $privacyContext): ?string
    {
        return match ($privacyContext) {
            'public_export', 'private_local' => $privacyContext,
            default => null,
        };
    }

    /**
     * Import media files for a tree from Windows
     */
    public function importMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $tree = $this->genealogyService->getTree($treeId);

            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            $result = $this->mediaService->importTreeMedia(
                $treeId,
                $tree->name,
                $request->input('windows_base_path')
            );

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
                'message' => "Imported {$result['imported']} of {$result['total']} media files",
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to import media', $e);
        }
    }

    // ========================================================================
    // PERSON ENDPOINTS
    // ========================================================================

    /**
     * Search persons in a tree
     */
    public function searchPersons(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'q' => 'required|string|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $persons = $this->genealogyService->searchPersons(
                $treeId,
                $request->input('q'),
                $request->input('limit', 50)
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'persons' => $persons,
                    'count' => count($persons),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search persons', $e);
        }
    }

    /**
     * Get surname list for a tree
     */
    public function getSurnames(int $treeId): JsonResponse
    {
        try {
            $surnames = $this->genealogyService->getSurnameList($treeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'surnames' => $surnames,
                    'count' => count($surnames),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get surnames', $e);
        }
    }

    /**
     * Get persons by surname
     */
    public function getPersonsBySurname(int $treeId, string $surname): JsonResponse
    {
        try {
            $persons = $this->genealogyService->listPersonsBySurname($treeId, $surname);

            return response()->json([
                'success' => true,
                'data' => [
                    'surname' => $surname,
                    'persons' => $persons,
                    'count' => count($persons),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get persons', $e);
        }
    }

    /**
     * Get a single person with full details
     */
    public function getPerson(int $id): JsonResponse
    {
        try {
            $person = $this->genealogyService->getPerson($id);

            if (! $person) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['person' => $person],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get person', $e);
        }
    }

    /**
     * Create a new person
     */
    public function createPerson(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'given_name' => 'nullable|string|max:255',
                'surname' => 'nullable|string|max:255',
                'sex' => 'nullable|string|in:M,F,U',
                'birth_date' => 'nullable|string|max:50',
                'birth_place' => 'nullable|string',
                'death_date' => 'nullable|string|max:50',
                'death_place' => 'nullable|string',
            ]);

            $personId = $this->genealogyService->createPerson($treeId, $request->all());
            $person = $this->genealogyService->getPerson($personId);

            return response()->json([
                'success' => true,
                'data' => ['person' => $person],
                'message' => 'Person created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create person', $e);
        }
    }

    /**
     * Update a person
     */
    public function updatePerson(Request $request, int $id): JsonResponse
    {
        try {
            $updated = $this->genealogyService->updatePerson($id, $request->all());

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found or no changes made'],
                ], 404);
            }

            $person = $this->genealogyService->getPerson($id);

            return response()->json([
                'success' => true,
                'data' => ['person' => $person],
                'message' => 'Person updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update person', $e);
        }
    }

    /**
     * Delete a person
     */
    public function deletePerson(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deletePerson($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Person deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete person', $e);
        }
    }

    /**
     * Set or unset primary photo for a person
     */
    public function setPersonPrimaryPhoto(Request $request, int $personId): JsonResponse
    {
        try {
            // media_id can be null to unset primary photo
            $mediaId = $request->input('media_id');

            $result = $this->genealogyService->setPersonPrimaryPhoto($personId, $mediaId);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => ['person' => $result['person']],
                'message' => $result['message'],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to set primary photo', $e);
        }
    }

    // ========================================================================
    // EVENT ENDPOINTS (Phase 2.1 - GEDCOM Life Events)
    // ========================================================================

    /**
     * Get event types list (for dropdown)
     */
    public function getEventTypes(): JsonResponse
    {
        try {
            $types = $this->genealogyService->getEventTypes();

            return response()->json([
                'success' => true,
                'data' => ['event_types' => $types],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get event types', $e);
        }
    }

    /**
     * Get events for a person
     */
    public function getPersonEvents(int $personId): JsonResponse
    {
        try {
            $events = $this->genealogyService->getPersonEvents($personId);

            return response()->json([
                'success' => true,
                'data' => [
                    'events' => $events,
                    'count' => count($events),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get events', $e);
        }
    }

    /**
     * Get a single event
     */
    public function getEvent(int $id): JsonResponse
    {
        try {
            $event = $this->genealogyService->getEvent($id);

            if (! $event) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Event not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['event' => $event],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get event', $e);
        }
    }

    /**
     * Create a new event for a person
     */
    public function createEvent(Request $request, int $personId): JsonResponse
    {
        try {
            $request->validate([
                'event_type' => 'required|string|max:50',
                'event_date' => 'nullable|string|max:50',
                'event_place' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'description' => 'nullable|string',
                'source_id' => 'nullable|integer|exists:genealogy_sources,id',
            ]);

            $eventId = $this->genealogyService->createEvent($personId, $request->all());
            $event = $this->genealogyService->getEvent($eventId);

            return response()->json([
                'success' => true,
                'data' => ['event' => $event],
                'message' => 'Event created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create event', $e);
        }
    }

    /**
     * Update an event
     */
    public function updateEvent(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'event_type' => 'sometimes|string|max:50',
                'event_date' => 'nullable|string|max:50',
                'event_place' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'description' => 'nullable|string',
                'source_id' => 'nullable|integer|exists:genealogy_sources,id',
            ]);

            $updated = $this->genealogyService->updateEvent($id, $request->all());

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Event not found or no changes made'],
                ], 404);
            }

            $event = $this->genealogyService->getEvent($id);

            return response()->json([
                'success' => true,
                'data' => ['event' => $event],
                'message' => 'Event updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update event', $e);
        }
    }

    /**
     * Delete an event
     */
    public function deleteEvent(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteEvent($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Event not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Event deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete event', $e);
        }
    }

    // ========================================================================
    // RESIDENCE ENDPOINTS
    // ========================================================================

    /**
     * Get all residences for a person
     */
    public function getPersonResidences(int $personId): JsonResponse
    {
        try {
            $residences = $this->genealogyService->getPersonResidences($personId);

            return response()->json([
                'success' => true,
                'data' => ['residences' => $residences],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch residences', $e);
        }
    }

    /**
     * Get a single residence
     */
    public function getResidence(int $id): JsonResponse
    {
        try {
            $residence = $this->genealogyService->getResidence($id);

            if (! $residence) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Residence not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['residence' => $residence],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch residence', $e);
        }
    }

    /**
     * Create a new residence for a person
     */
    public function createResidence(Request $request, int $personId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'residence_date' => 'nullable|string|max:50',
                'place' => 'required|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'source_id' => 'nullable|integer|exists:genealogy_sources,id',
            ]);

            $residence = $this->genealogyService->createResidence($personId, $validated);

            return response()->json([
                'success' => true,
                'data' => ['residence' => $residence],
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create residence', $e);
        }
    }

    /**
     * Update a residence
     */
    public function updateResidence(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'residence_date' => 'nullable|string|max:50',
                'place' => 'required|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'source_id' => 'nullable|integer|exists:genealogy_sources,id',
            ]);

            $residence = $this->genealogyService->updateResidence($id, $validated);

            if (! $residence) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Residence not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['residence' => $residence],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update residence', $e);
        }
    }

    /**
     * Delete a residence
     */
    public function deleteResidence(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteResidence($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Residence not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Residence deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete residence', $e);
        }
    }

    /**
     * Get all media for a person with full details
     */
    public function getPersonMedia(int $personId): JsonResponse
    {
        try {
            $media = $this->genealogyService->getPersonMedia($personId);

            return response()->json([
                'success' => true,
                'data' => ['media' => $media],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch person media', $e);
        }
    }

    /**
     * Get sources linked to a person
     */
    public function getPersonSources(int $personId): JsonResponse
    {
        try {
            $sources = $this->genealogyService->getPersonSources($personId);

            return response()->json([
                'success' => true,
                'data' => $sources,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch person sources', $e);
        }
    }

    /**
     * Link a source to a person
     */
    public function linkPersonSource(Request $request, int $personId): JsonResponse
    {
        try {
            $sourceId = $request->input('source_id');
            $page = $request->input('page');
            $quality = $request->input('quality');

            if (! $sourceId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'source_id is required'],
                ], 400);
            }

            $this->genealogyService->linkPersonSourcePublic($personId, $sourceId, $page, $quality);

            return response()->json([
                'success' => true,
                'message' => 'Source linked to person successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to link source to person', $e);
        }
    }

    /**
     * Unlink a source from a person
     */
    public function unlinkPersonSource(int $personId, int $sourceId): JsonResponse
    {
        try {
            $this->genealogyService->unlinkPersonSource($personId, $sourceId);

            return response()->json([
                'success' => true,
                'message' => 'Source unlinked from person successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to unlink source from person', $e);
        }
    }

    // ========================================================================
    // FAMILY ENDPOINTS
    // ========================================================================

    /**
     * Get a family with all members
     */
    public function getFamily(int $id): JsonResponse
    {
        try {
            $family = $this->genealogyService->getFamily($id);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Family not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['family' => $family],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get family', $e);
        }
    }

    /**
     * Get all families for a tree
     */
    public function getFamilies(int $treeId): JsonResponse
    {
        try {
            $families = $this->genealogyService->getFamilies($treeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'families' => $families,
                    'count' => count($families),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get families', $e);
        }
    }

    /**
     * Create a new family
     */
    public function createFamily(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'husband_id' => 'nullable|integer|exists:genealogy_persons,id',
                'wife_id' => 'nullable|integer|exists:genealogy_persons,id',
                'marriage_date' => 'nullable|string|max:50',
                'marriage_place' => 'nullable|string',
            ]);

            $familyId = $this->genealogyService->createFamily($treeId, $request->all());
            $family = $this->genealogyService->getFamily($familyId);

            return response()->json([
                'success' => true,
                'data' => ['family' => $family],
                'message' => 'Family created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create family', $e);
        }
    }

    /**
     * Update a family
     */
    public function updateFamily(Request $request, int $id): JsonResponse
    {
        try {
            // Update family basic fields
            $this->genealogyService->updateFamily($id, $request->all());

            // Handle child_ids synchronization if provided
            if ($request->has('child_ids')) {
                $this->genealogyService->syncFamilyChildren($id, $request->input('child_ids', []));
            }

            $family = $this->genealogyService->getFamily($id);

            return response()->json([
                'success' => true,
                'data' => ['family' => $family],
                'message' => 'Family updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update family', $e);
        }
    }

    /**
     * Delete a family
     */
    public function deleteFamily(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteFamily($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Family not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete family', $e);
        }
    }

    /**
     * Add a child to a family
     */
    public function addChild(Request $request, int $familyId): JsonResponse
    {
        try {
            $request->validate([
                'person_id' => 'required|integer|exists:genealogy_persons,id',
                'birth_order' => 'nullable|integer',
                'father_relationship' => 'nullable|string|in:Natural,Adopted,Step,Foster,Unknown',
                'mother_relationship' => 'nullable|string|in:Natural,Adopted,Step,Foster,Unknown',
            ]);

            $this->genealogyService->addChildToFamily($familyId, $request->input('person_id'), $request->all());
            $family = $this->genealogyService->getFamily($familyId);

            return response()->json([
                'success' => true,
                'data' => ['family' => $family],
                'message' => 'Child added to family',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add child', $e);
        }
    }

    /**
     * Remove a child from a family
     */
    public function removeChild(int $familyId, int $personId): JsonResponse
    {
        try {
            $removed = $this->genealogyService->removeChildFromFamily($familyId, $personId);

            if (! $removed) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Child not found in family'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Child removed from family',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to remove child', $e);
        }
    }

    // ========================================================================
    // FAMILY EVENT ENDPOINTS (Phase 2.3 - GEDCOM Family Events)
    // ========================================================================

    /**
     * Get family event types list (for dropdown)
     */
    public function getFamilyEventTypes(): JsonResponse
    {
        try {
            $types = $this->genealogyService->getFamilyEventTypes();

            return response()->json([
                'success' => true,
                'data' => ['event_types' => $types],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get family event types', $e);
        }
    }

    /**
     * Get events for a family
     */
    public function getFamilyEvents(int $familyId): JsonResponse
    {
        try {
            $events = $this->genealogyService->getFamilyEvents($familyId);

            return response()->json([
                'success' => true,
                'data' => [
                    'events' => $events,
                    'count' => count($events),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get family events', $e);
        }
    }

    /**
     * Get a single family event
     */
    public function getFamilyEvent(int $id): JsonResponse
    {
        try {
            $event = $this->genealogyService->getFamilyEvent($id);

            if (! $event) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Family event not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['event' => $event],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get family event', $e);
        }
    }

    /**
     * Create a new family event
     */
    public function createFamilyEvent(Request $request, int $familyId): JsonResponse
    {
        try {
            $request->validate([
                'event_type' => 'required|string|max:50',
                'event_date' => 'nullable|string|max:50',
                'event_place' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'description' => 'nullable|string',
                'source_id' => 'nullable|integer|exists:genealogy_sources,id',
            ]);

            $eventId = $this->genealogyService->createFamilyEvent($familyId, $request->all());
            $event = $this->genealogyService->getFamilyEvent($eventId);

            return response()->json([
                'success' => true,
                'data' => ['event' => $event],
                'message' => 'Family event created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create family event', $e);
        }
    }

    /**
     * Update a family event
     */
    public function updateFamilyEvent(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'event_type' => 'sometimes|string|max:50',
                'event_date' => 'nullable|string|max:50',
                'event_place' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'description' => 'nullable|string',
                'source_id' => 'nullable|integer|exists:genealogy_sources,id',
            ]);

            $updated = $this->genealogyService->updateFamilyEvent($id, $request->all());

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Family event not found or no changes made'],
                ], 404);
            }

            $event = $this->genealogyService->getFamilyEvent($id);

            return response()->json([
                'success' => true,
                'data' => ['event' => $event],
                'message' => 'Family event updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update family event', $e);
        }
    }

    /**
     * Delete a family event
     */
    public function deleteFamilyEvent(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteFamilyEvent($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Family event not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family event deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete family event', $e);
        }
    }

    // ========================================================================
    // SOURCE ENDPOINTS (Phase 2.4 - GEDCOM Source Management)
    // ========================================================================

    /**
     * Get sources for a tree
     */
    public function getSources(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);
            $sources = $this->genealogyService->getSources($treeId, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'sources' => $sources,
                    'count' => count($sources),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get sources', $e);
        }
    }

    /**
     * Search sources in a tree
     */
    public function searchSources(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'q' => 'required|string|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $sources = $this->genealogyService->searchSources(
                $treeId,
                $request->input('q'),
                $request->input('limit', 50)
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'sources' => $sources,
                    'count' => count($sources),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search sources', $e);
        }
    }

    /**
     * Get a single source with full details
     */
    public function getSource(int $id): JsonResponse
    {
        try {
            $source = $this->genealogyService->getSource($id);

            if (! $source) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Source not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['source' => $source],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get source', $e);
        }
    }

    /**
     * Create a new source
     */
    public function createSource(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'required|string|max:500',
                'author' => 'nullable|string|max:500',
                'publication' => 'nullable|string',
                'repository' => 'nullable|string|max:255',
                'repository_address' => 'nullable|string',
                'call_number' => 'nullable|string|max:100',
                'url' => 'nullable|url|max:500',
                'notes' => 'nullable|string',
            ]);

            $sourceId = $this->genealogyService->createSource($treeId, $request->all());
            $source = $this->genealogyService->getSource($sourceId);

            return response()->json([
                'success' => true,
                'data' => ['source' => $source],
                'message' => 'Source created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create source', $e);
        }
    }

    /**
     * Update a source
     */
    public function updateSource(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'sometimes|string|max:500',
                'author' => 'nullable|string|max:500',
                'publication' => 'nullable|string',
                'repository' => 'nullable|string|max:255',
                'repository_address' => 'nullable|string',
                'call_number' => 'nullable|string|max:100',
                'url' => 'nullable|url|max:500',
                'notes' => 'nullable|string',
            ]);

            $updated = $this->genealogyService->updateSource($id, $request->all());

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Source not found or no changes made'],
                ], 404);
            }

            $source = $this->genealogyService->getSource($id);

            return response()->json([
                'success' => true,
                'data' => ['source' => $source],
                'message' => 'Source updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update source', $e);
        }
    }

    /**
     * Delete a source
     */
    public function deleteSource(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteSource($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Source not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Source deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete source', $e);
        }
    }

    // ========================================================================
    // CITATION ENDPOINTS (Phase 2.5)
    // ========================================================================

    /**
     * Get citation fact types
     */
    public function getCitationFactTypes(): JsonResponse
    {
        try {
            $factTypes = $this->genealogyService->getCitationFactTypes();

            return response()->json([
                'success' => true,
                'data' => ['fact_types' => $factTypes],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get citation fact types', $e);
        }
    }

    /**
     * Get citation quality levels
     */
    public function getCitationQualityLevels(): JsonResponse
    {
        try {
            $qualityLevels = $this->genealogyService->getCitationQualityLevels();

            return response()->json([
                'success' => true,
                'data' => ['quality_levels' => $qualityLevels],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get citation quality levels', $e);
        }
    }

    /**
     * Get citations for a person
     */
    public function getPersonCitations(int $personId): JsonResponse
    {
        try {
            $citations = $this->genealogyService->getPersonCitations($personId);

            return response()->json([
                'success' => true,
                'data' => ['citations' => $citations],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get person citations', $e);
        }
    }

    /**
     * Get citations for a family
     */
    public function getFamilyCitations(int $familyId): JsonResponse
    {
        try {
            $citations = $this->genealogyService->getFamilyCitations($familyId);

            return response()->json([
                'success' => true,
                'data' => ['citations' => $citations],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get family citations', $e);
        }
    }

    /**
     * Get citations for a source
     */
    public function getSourceCitations(int $sourceId): JsonResponse
    {
        try {
            $citations = $this->genealogyService->getSourceCitations($sourceId);

            return response()->json([
                'success' => true,
                'data' => ['citations' => $citations],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get source citations', $e);
        }
    }

    /**
     * Get a single citation
     */
    public function getCitation(int $id): JsonResponse
    {
        try {
            $citation = $this->genealogyService->getCitation($id);

            if (! $citation) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Citation not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['citation' => $citation],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get citation', $e);
        }
    }

    /**
     * Create a citation
     */
    public function createCitation(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'source_id' => 'required|integer',
                'person_id' => 'nullable|integer',
                'family_id' => 'nullable|integer',
                'media_id' => 'nullable|integer',
                'fact_type' => 'nullable|string|max:50',
                'page' => 'nullable|string|max:255',
                'quality' => 'nullable|integer|min:0|max:3',
                'text' => 'nullable|string',
            ]);

            // Must have at least one of person_id, family_id, or media_id
            if (! $request->person_id && ! $request->family_id && ! $request->media_id) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Citation must be linked to a person, family, or media item'],
                ], 422);
            }

            $citationId = $this->genealogyService->createCitation($request->all());

            return response()->json([
                'success' => true,
                'data' => ['citation_id' => $citationId],
                'message' => 'Citation created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create citation', $e);
        }
    }

    /**
     * Update a citation
     */
    public function updateCitation(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'source_id' => 'required|integer',
                'person_id' => 'nullable|integer',
                'family_id' => 'nullable|integer',
                'media_id' => 'nullable|integer',
                'fact_type' => 'nullable|string|max:50',
                'page' => 'nullable|string|max:255',
                'quality' => 'nullable|integer|min:0|max:3',
                'text' => 'nullable|string',
            ]);

            // Must have at least one of person_id, family_id, or media_id
            if (! $request->person_id && ! $request->family_id && ! $request->media_id) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Citation must be linked to a person, family, or media item'],
                ], 422);
            }

            $updated = $this->genealogyService->updateCitation($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Citation updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update citation', $e);
        }
    }

    /**
     * Delete a citation
     */
    public function deleteCitation(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteCitation($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Citation not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Citation deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete citation', $e);
        }
    }

    // ========================================================================
    // REPOSITORY ENDPOINTS (Phase 2.6)
    // ========================================================================

    /**
     * Get repositories for a tree
     */
    public function getRepositories(int $treeId): JsonResponse
    {
        try {
            $repositories = $this->genealogyService->getRepositories($treeId);

            return response()->json([
                'success' => true,
                'data' => ['repositories' => $repositories],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get repositories', $e);
        }
    }

    /**
     * Search repositories
     */
    public function searchRepositories(Request $request, int $treeId): JsonResponse
    {
        try {
            $query = $request->input('q', '');

            if (strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => ['repositories' => []],
                ]);
            }

            $repositories = $this->genealogyService->searchRepositories($treeId, $query);

            return response()->json([
                'success' => true,
                'data' => ['repositories' => $repositories],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search repositories', $e);
        }
    }

    /**
     * Get a single repository
     */
    public function getRepository(int $id): JsonResponse
    {
        try {
            $repository = $this->genealogyService->getRepository($id);

            if (! $repository) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Repository not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ['repository' => $repository],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get repository', $e);
        }
    }

    /**
     * Create a repository
     */
    public function createRepository(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:500',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'url' => 'nullable|url|max:500',
                'notes' => 'nullable|string',
            ]);

            $repositoryId = $this->genealogyService->createRepository($treeId, $request->all());

            return response()->json([
                'success' => true,
                'data' => ['repository_id' => $repositoryId],
                'message' => 'Repository created successfully',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create repository', $e);
        }
    }

    /**
     * Update a repository
     */
    public function updateRepository(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:500',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'url' => 'nullable|url|max:500',
                'notes' => 'nullable|string',
            ]);

            $updated = $this->genealogyService->updateRepository($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Repository updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update repository', $e);
        }
    }

    /**
     * Delete a repository
     */
    public function deleteRepository(int $id): JsonResponse
    {
        try {
            $deleted = $this->genealogyService->deleteRepository($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Repository not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Repository deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete repository', $e);
        }
    }

    // ========================================================================
    // REPORTS ENDPOINTS (Phase 2.7)
    // ========================================================================

    /**
     * Get available missing data report types
     */
    public function getMissingDataTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'types' => GenealogyService::MISSING_DATA_TYPES,
            ],
        ]);
    }

    /**
     * Get missing data report for a tree
     */
    public function getMissingDataReport(Request $request, int $treeId): JsonResponse
    {
        try {
            // Get optional report types filter from query
            $reportTypes = $request->input('types');
            if ($reportTypes && is_string($reportTypes)) {
                $reportTypes = explode(',', $reportTypes);
            }

            $report = $this->genealogyService->getMissingDataReport($treeId, $reportTypes);

            return response()->json([
                'success' => true,
                'data' => [
                    'report' => $report,
                    'tree_id' => $treeId,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate missing data report', $e);
        }
    }

    /**
     * Get missing data summary statistics for a tree
     */
    public function getMissingDataSummary(int $treeId): JsonResponse
    {
        try {
            $summary = $this->genealogyService->getMissingDataSummary($treeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'tree_id' => $treeId,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate missing data summary', $e);
        }
    }

    /**
     * Generate a source-audit workbook manifest or CSV package for a tree.
     */
    public function sourceAuditWorkbook(Request $request, int $treeId, SourceAuditWorkbookService $service): JsonResponse
    {
        try {
            $result = $service->generate(
                treeId: $treeId,
                format: (string) $request->input('format', 'manifest'),
                privacyMode: (string) $request->input('privacy_mode', 'private_local'),
                dryRun: $request->boolean('dry_run', true),
                confirm: $request->boolean('confirm', false),
                actor: 'ui:genealogy-source-audit-workbook',
                layoutProfile: (string) $request->input('layout_profile', 'dense_audit_v1'),
                includeSources: $request->boolean('include_sources', true),
                includeMedia: $request->boolean('include_media', true),
                includeIssues: $request->boolean('include_issues', true),
                prelabelCount: (int) $request->input('prelabel_count', 0),
                shardMode: (string) $request->input('shard_mode', 'none'),
                branchPersonId: $request->filled('branch_person_id') ? (int) $request->input('branch_person_id') : null,
                branchMode: (string) $request->input('branch_mode', 'descendants')
            );

            return response()->json([
                'success' => (bool) ($result['success'] ?? false),
                'data' => $result,
            ], ($result['success'] ?? false) ? 200 : 422);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate source-audit workbook', $e);
        }
    }

    /**
     * Create or preview a genealogy review packet from a source-audit workbook row.
     */
    public function sourceAuditWorkbookReviewPacket(Request $request, int $treeId, SourceAuditWorkbookService $service): JsonResponse
    {
        $validated = $request->validate([
            'tag' => 'nullable|string|max:64',
            'record_type' => 'nullable|string|in:person,family',
            'record_id' => 'nullable|integer|min:1',
            'dry_run' => 'nullable|boolean',
            'confirm' => 'nullable|boolean',
        ]);

        try {
            $result = $service->createReviewPacketFromWorkbookRow(
                treeId: $treeId,
                tag: $validated['tag'] ?? null,
                recordType: $validated['record_type'] ?? null,
                recordId: isset($validated['record_id']) ? (int) $validated['record_id'] : null,
                dryRun: $request->boolean('dry_run', true),
                confirm: $request->boolean('confirm', false),
                actor: 'ui:source-audit-workbook-review-packet'
            );

            return response()->json([
                'success' => (bool) ($result['success'] ?? false),
                'data' => $result,
            ], ($result['success'] ?? false) ? 200 : 422);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create source-audit workbook review packet', $e);
        }
    }

    /**
     * Download a generated source-audit workbook artifact from the tree report folder.
     */
    public function sourceAuditWorkbookDownload(Request $request, int $treeId, SourceAuditWorkbookService $service)
    {
        try {
            $path = $service->resolveReportDownloadPath($treeId, (string) $request->query('path', ''));

            return response()->download($path, basename($path));
        } catch (Exception $e) {
            return $this->errorResponse('Failed to download source-audit workbook file', $e, 404);
        }
    }

    // ========================================================================
    // MEDIA ENDPOINTS
    // ========================================================================

    /**
     * Get media for a tree
     */
    public function getTreeMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);
            $offset = $request->input('offset', 0);
            $mediaType = $request->input('type'); // Phase 3.6: category filter

            $media = $this->genealogyService->getTreeMedia($treeId, $limit, $offset, $mediaType);

            // Phase 3.6: Get category counts
            $categoryCounts = $this->genealogyService->getMediaCategoryCounts($treeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'media' => $media,
                    'count' => count($media),
                    'limit' => $limit,
                    'offset' => $offset,
                    'category_counts' => $categoryCounts, // Phase 3.6
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get media', $e);
        }
    }

    /**
     * Update media type/category (Phase 3.6)
     */
    public function updateMediaType(Request $request, int $mediaId): JsonResponse
    {
        $request->validate([
            'media_type' => 'required|string|in:photo,document,certificate,census,military,obituary,headstone,other',
        ]);

        try {
            $success = $this->genealogyService->updateMediaType($mediaId, $request->input('media_type'));

            if (! $success) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to update media type'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Media type updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update media type', $e);
        }
    }

    /**
     * Update media transcription (Phase 3.7)
     */
    public function updateMediaTranscription(Request $request, int $mediaId): JsonResponse
    {
        $request->validate([
            'transcription' => 'required|string',
            'source' => 'nullable|string|in:manual,ocr,ai',
        ]);

        try {
            $source = $request->input('source', 'manual');
            $success = $this->genealogyService->updateMediaTranscription(
                $mediaId,
                $request->input('transcription'),
                $source
            );

            if (! $success) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to update transcription'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transcription updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update transcription', $e);
        }
    }

    /**
     * Get media items needing transcription (Phase 3.7)
     */
    public function getMediaNeedingTranscription(int $treeId): JsonResponse
    {
        try {
            $media = $this->genealogyService->getMediaNeedingTranscription($treeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'media' => $media,
                    'count' => count($media),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get media needing transcription', $e);
        }
    }

    /**
     * Get Windows media paths needing import (Phase 3.8)
     */
    public function getWindowsMediaPaths(int $treeId): JsonResponse
    {
        try {
            $paths = $this->genealogyService->getWindowsMediaPaths($treeId);

            return response()->json([
                'success' => true,
                'data' => $paths,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get Windows media paths', $e);
        }
    }

    /**
     * Generate SCP commands for Windows import (Phase 3.8)
     */
    public function generateScpCommands(Request $request, int $treeId): JsonResponse
    {
        $request->validate([
            'remote_host' => 'required|string',
            'remote_path' => 'required|string',
        ]);

        try {
            $commands = $this->genealogyService->generateScpCommands(
                $treeId,
                $request->input('remote_host'),
                $request->input('remote_path')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'commands' => $commands,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate SCP commands', $e);
        }
    }

    /**
     * Get a single media item
     */
    public function getMedia(int $id): JsonResponse
    {
        try {
            $media = $this->genealogyService->getMedia($id);

            if (! $media) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Media not found'],
                ], 404);
            }

            // Add download URL
            $media['download_url'] = $this->mediaService->getMediaUrl($id);

            return response()->json([
                'success' => true,
                'data' => ['media' => $media],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get media', $e);
        }
    }

    /**
     * Build a read-only intake preview for a genealogy media record.
     */
    public function previewMediaIntake(int $id, GenealogyMediaEnrichmentService $enrichmentService): JsonResponse
    {
        try {
            $preview = $enrichmentService->previewMediaIntakePacket($id);

            if (! ($preview['success'] ?? false)) {
                $status = ($preview['reason'] ?? '') === 'not_found' ? 404 : 422;

                return response()->json([
                    'success' => false,
                    'error' => ['message' => $preview['reason'] ?? 'Failed to build intake preview'],
                ], $status);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'preview' => $preview,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to build media intake preview', $e);
        }
    }

    /**
     * List saved genealogy intake runs.
     */
    public function listIntakeRuns(
        Request $request,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeRunSummaryService $summaryService
    ): JsonResponse {
        try {
            $treeId = $request->query('tree_id');
            $limit = (int) $request->query('limit', 25);
            $result = $runStore->listRuns($treeId !== null ? (int) $treeId : null, $limit);

            $runs = $result['runs'] ?? [];
            foreach ($runs as &$run) {
                $run['summary'] = $summaryService->summarizeRunListItem($run);
            }
            unset($run);

            return response()->json([
                'success' => true,
                'data' => [
                    'runs' => $runs,
                    'count' => count($runs),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list intake runs', $e);
        }
    }

    /**
     * Stage documents from an arbitrary intake root and save a resumable intake run.
     */
    public function stageIntakeRun(
        Request $request,
        GenealogyIntakeRunStageService $stageService,
        GenealogyIntakeRunSummaryService $summaryService
    ): JsonResponse {
        $validated = $request->validate([
            'tree_id' => 'required|integer|min:1',
            'root_path' => 'required|string|max:2000',
            'limit' => 'nullable|integer|min:1|max:500',
            'packet_label' => 'nullable|string|max:255',
            'workbook_tag' => 'nullable|string|max:64',
            'unprocessed_only' => 'nullable|boolean',
        ]);

        try {
            $result = $stageService->stage(
                (int) $validated['tree_id'],
                (string) $validated['root_path'],
                (int) ($validated['limit'] ?? 100),
                [
                    'packet_label' => $validated['packet_label'] ?? null,
                    'workbook_tag' => $validated['workbook_tag'] ?? null,
                    'unprocessed_only' => (bool) ($validated['unprocessed_only'] ?? false),
                ]
            );

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error'] ?? 'stage_failed'],
                ], 422);
            }

            $run = (array) ($result['run'] ?? []);
            $staged = (array) ($result['staged'] ?? []);

            return response()->json([
                'success' => true,
                'data' => [
                    'run' => $run,
                    'summary' => $summaryService->summarizeRun($run),
                    'staged' => [
                        'file_count' => (int) ($staged['file_count'] ?? 0),
                        'packet_count' => (int) ($staged['packet_count'] ?? 0),
                        'root_path' => (string) ($staged['root_path'] ?? ($run['root_path'] ?? '')),
                        'packet_label' => $staged['packet_label'] ?? ($run['packet_label'] ?? null),
                        'workbook_tag' => $staged['workbook_tag'] ?? null,
                        'workbook_target' => $staged['workbook_target'] ?? null,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to stage intake run', $e);
        }
    }

    /**
     * Get one saved intake run with optional packet preview.
     */
    public function getIntakeRun(
        Request $request,
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyStagedPacketPreviewService $packetPreview,
        GenealogyIntakeRunSummaryService $summaryService,
        GenealogyIntakeWorkspaceService $workspaceService,
        GenealogyIntakeSelectedPacketComposer $selectedPacketComposer
    ): JsonResponse {
        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);
            $previewLabel = trim((string) $request->query('preview_packet', ''));
            $shouldGeneratePreview = $previewLabel !== '';
            $selectedPacket = $packetPreview->selectPacket($run, $shouldGeneratePreview ? $previewLabel : null);
            $selectedPacketKey = (string) ($selectedPacket['packet_key'] ?? '');
            $selectedPacketLabel = (string) ($selectedPacket['packet_label'] ?? '');

            $previewResult = $selectedPacket ? self::buildStoredIntakePacketPreview($selectedPacket) : null;
            if ($shouldGeneratePreview && $selectedPacket) {
                try {
                    $previewResult = $packetPreview->previewPacket($selectedPacket);
                    $selectedPacketKey = (string) ($previewResult['packet_key'] ?? $selectedPacketKey);
                    $selectedPacketLabel = (string) ($previewResult['packet_label'] ?? $selectedPacketLabel);

                    // Persist preview state into the saved snapshot (skips if unchanged)
                    $saveBack = $runStore->recordPacketPreviewState($runKey, $previewResult);
                    if (($saveBack['success'] ?? false) && empty($saveBack['skipped'])) {
                        // Re-read the run so the summary reflects the updated preview_state
                        $reloaded = $runStore->getRun($runKey);
                        if ($reloaded['success'] ?? false) {
                            $run = (array) ($reloaded['run'] ?? []);
                        }
                    }
                } catch (Throwable $previewError) {
                    Log::warning('Genealogy intake packet preview failed', [
                        'run_key' => $runKey,
                        'preview_packet' => $previewLabel,
                        'message' => $previewError->getMessage(),
                    ]);
                    $previewResult = self::buildFailedIntakePacketPreview($selectedPacket);
                }
            }

            $workspace = $workspaceService->buildWorkspace($run);
            $selectedPacket = $selectedPacketComposer->compose(
                $run,
                $workspace,
                $selectedPacketKey !== '' ? $selectedPacketKey : null,
                $selectedPacketLabel !== '' ? $selectedPacketLabel : null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'run' => $run,
                    'summary' => $summaryService->summarizeRun($run),
                    'packet_preview' => $previewResult,
                    'packet_stage' => $selectedPacket['stage'] ?? null,
                    'packet_presentation' => $selectedPacket['presentation'] ?? null,
                    'packet_action' => $selectedPacket['action'] ?? null,
                    'selected_packet' => $selectedPacket,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to load intake run', $e);
        }
    }

    private static function buildStoredIntakePacketPreview(array $packet): array
    {
        $documents = array_values((array) ($packet['documents'] ?? []));
        $previewState = (array) ($packet['preview_state'] ?? []);
        $mediaSummary = self::summarizeStoredIntakePacketDocuments($documents);
        $packetLabel = (string) ($packet['packet_label'] ?? 'Untitled packet');
        $packetKey = $packet['packet_key'] ?? null;

        $preview = [
            'status' => (string) ($previewState['status'] ?? 'not_generated'),
            'proposal_ready' => ! empty($previewState['proposal_ready']),
            'packet_summary' => (string) ($previewState['packet_summary'] ?? ''),
            'page_anchors' => array_values((array) ($previewState['page_anchors'] ?? [])),
            'person_candidates' => array_values((array) ($previewState['person_candidates'] ?? [])),
            'questions' => array_values((array) ($previewState['questions'] ?? [])),
        ];

        if (! empty($previewState['structured_facts'])) {
            $preview['structured_facts'] = array_values((array) $previewState['structured_facts']);
        }

        return [
            'packet_label' => $packetLabel,
            'packet_key' => $packetKey,
            'document_count' => count($documents),
            'page_count' => (int) ($mediaSummary['page_count'] ?? 0),
            'registration' => [
                'packet_label' => $packetLabel,
                'packet_key' => $packetKey,
                'copy_status' => self::deriveStoredIntakePacketCopyStatus($packet, $documents),
                'documents' => $documents,
            ],
            'media_summary' => $mediaSummary,
            'preview' => $preview,
            'preview_source' => $previewState !== [] ? 'saved_preview_state' : 'stored_packet',
        ];
    }

    private static function buildFailedIntakePacketPreview(array $packet): array
    {
        $preview = self::buildStoredIntakePacketPreview($packet);
        $preview['preview']['status'] = 'preview_failed';
        $preview['preview']['proposal_ready'] = false;
        $preview['preview']['packet_summary'] = $preview['preview']['packet_summary']
            ?: 'Packet preview could not be generated right now. Saved packet details remain available.';
        $preview['preview_source'] = 'preview_failed';
        $preview['error'] = ['message' => 'preview_failed'];

        return $preview;
    }

    private static function summarizeStoredIntakePacketDocuments(array $documents): array
    {
        $typeCounts = [];
        $pageCount = 0;

        foreach ($documents as $document) {
            $document = (array) $document;
            $type = (string) ($document['document_type'] ?? $document['type'] ?? 'document');
            $type = $type !== '' ? $type : 'document';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            $pageCount += max(1, (int) ($document['page_count'] ?? count((array) ($document['pages'] ?? [])) ?: 1));
        }

        ksort($typeCounts);

        return [
            'document_type_counts' => $typeCounts,
            'document_types' => array_keys($typeCounts),
            'is_mixed_media' => count($typeCounts) > 1,
            'page_count' => $pageCount,
        ];
    }

    private static function deriveStoredIntakePacketCopyStatus(array $packet, array $documents): string
    {
        $summary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
        if ((int) ($summary['failed'] ?? 0) > 0) {
            return 'failed';
        }
        if ((int) ($summary['blocked_conflicts'] ?? 0) > 0) {
            return 'conflict';
        }
        if ((int) ($summary['copied'] ?? 0) > 0 || (int) ($summary['already_in_place'] ?? 0) > 0) {
            return 'ready';
        }
        if ($documents === []) {
            return 'empty';
        }

        return 'stored';
    }

    /**
     * Record a packet-level human review decision on a saved intake run.
     */
    public function recordIntakeRunReviewDecision(
        Request $request,
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeRunSummaryService $summaryService
    ): JsonResponse {
        $validated = $request->validate([
            'decision' => 'required|string|in:approved,deferred,rejected,needs_followup',
            'packet_label' => 'required_without:packet_key|nullable|string',
            'packet_key' => 'required_without:packet_label|nullable|string',
            'notes' => 'nullable|string|max:2000',
            'reviewed_by' => 'nullable|string|max:255',
        ]);

        try {
            $result = $runStore->recordPacketReviewDecision($runKey, $validated);
            if (! ($result['success'] ?? false)) {
                $status = match ($result['error'] ?? '') {
                    'run_not_found', 'invalid_snapshot' => 404,
                    'packet_not_found' => 404,
                    default => 422,
                };

                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error'] ?? 'unknown_error'],
                ], $status);
            }

            // Re-read run for updated summary
            $loaded = $runStore->getRun($runKey);
            $run = ($loaded['success'] ?? false) ? (array) ($loaded['run'] ?? []) : [];

            return response()->json([
                'success' => true,
                'data' => [
                    'review_decision' => $result['review_decision'],
                    'summary' => $run !== [] ? $summaryService->summarizeRun($run) : null,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to record review decision', $e);
        }
    }

    /**
     * Get the proposal queue for a saved intake run.
     */
    public function getIntakeRunProposalQueue(
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeRunSummaryService $summaryService,
        GenealogyIntakeProposalQueueService $queueService
    ): JsonResponse {
        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);

            return response()->json([
                'success' => true,
                'data' => [
                    'run_key' => $runKey,
                    'summary' => $summaryService->summarizeRun($run),
                    'queue' => $queueService->buildQueue($run),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to load proposal queue', $e);
        }
    }

    /**
     * Get the proposal draft plan for a saved intake run.
     */
    public function getIntakeRunProposalDraft(
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeRunSummaryService $summaryService,
        GenealogyIntakeProposalDraftService $draftService
    ): JsonResponse {
        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);

            return response()->json([
                'success' => true,
                'data' => [
                    'run_key' => $runKey,
                    'summary' => $summaryService->summarizeRun($run),
                    'draft_plan' => $draftService->plan($run),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to load proposal draft plan', $e);
        }
    }

    /**
     * Get the composed intake workspace payload for a saved run.
     */
    public function getIntakeRunWorkspace(
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeWorkspaceService $workspaceService,
        GenealogyIntakeWorkspaceOverviewService $overviewService
    ): JsonResponse {
        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);

            $workspace = $workspaceService->buildWorkspace($run);

            return response()->json([
                'success' => true,
                'data' => $workspace + [
                    'overview' => $overviewService->buildOverview($run, $workspace),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to load intake workspace', $e);
        }
    }

    /**
     * Build a read-only approval-draft preview for one selected intake packet.
     */
    public function previewIntakeRunApprovalDraft(
        Request $request,
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeWorkspaceService $workspaceService,
        GenealogyIntakeSelectedPacketComposer $selectedPacketComposer,
        GenealogyIntakeApprovalDraftPreviewService $approvalDraftPreviewService
    ): JsonResponse {
        $validated = $request->validate([
            'packet_label' => 'required_without:packet_key|nullable|string',
            'packet_key' => 'required_without:packet_label|nullable|string',
            'approved_sections' => 'nullable|array',
            'approved_sections.*' => 'string|in:identity,relationships,events,sources,notes',
            'person_id' => 'nullable|integer|min:1',
            'tree_id' => 'nullable|integer|min:1',
            'relationship_type' => 'nullable|string|max:255',
            'related_person_id' => 'nullable|integer|min:1',
        ]);

        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);
            $workspace = $workspaceService->buildWorkspace($run);
            $selectedPacket = $selectedPacketComposer->compose(
                $run,
                $workspace,
                $validated['packet_key'] ?? null,
                $validated['packet_label'] ?? null
            );

            if ($selectedPacket === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_found'],
                ], 404);
            }

            $draftInput = (array) ($selectedPacket['draft_entry']['draft_input'] ?? []);
            $proposalPreview = self::findProposalPreviewForPacket($workspace, $selectedPacket);

            if ($draftInput === [] || $proposalPreview === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_ready_for_approval_preview'],
                ], 422);
            }

            $context = [
                'approved_sections' => (array) ($validated['approved_sections'] ?? []),
                'person_id' => $validated['person_id'] ?? null,
                'tree_id' => $validated['tree_id'] ?? ($run['tree_id'] ?? null),
                'relationship_type' => $validated['relationship_type'] ?? null,
                'related_person_id' => $validated['related_person_id'] ?? null,
            ];
            $contextValidation = $this->validateApprovalDraftContext($run, $context);
            if (! ($contextValidation['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $contextValidation['error'] ?? 'invalid_approval_draft_context'],
                ], 422);
            }

            $approvalDraftPreview = $approvalDraftPreviewService->preview($proposalPreview, $draftInput, $context);
            $approvalDraftPreview['plan_hash'] = GenealogyIntakeRunStoreService::computeApprovalApplyPlanHash($approvalDraftPreview);

            return response()->json([
                'success' => true,
                'data' => [
                    'run_key' => $runKey,
                    'packet' => [
                        'packet_key' => (string) ($selectedPacket['packet_key'] ?? ''),
                        'packet_label' => (string) ($selectedPacket['packet_label'] ?? ''),
                    ],
                    'approval_draft_preview' => $approvalDraftPreview,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to build approval draft preview', $e);
        }
    }

    /**
     * Apply currently supported intake approval items through existing genealogy proposal/apply seams.
     */
    public function applyIntakeRunApprovalDraft(
        Request $request,
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeWorkspaceService $workspaceService,
        GenealogyIntakeSelectedPacketComposer $selectedPacketComposer,
        GenealogyIntakeApprovalDraftPreviewService $approvalDraftPreviewService,
        GenealogyIntakeApprovalApplyService $approvalApplyService,
        GenealogyIntakeApprovalApplySummaryService $approvalApplySummaryService
    ): JsonResponse {
        $validated = $request->validate([
            'packet_label' => 'required_without:packet_key|nullable|string',
            'packet_key' => 'required_without:packet_label|nullable|string',
            'approved_sections' => 'nullable|array',
            'approved_sections.*' => 'string|in:identity,relationships,events,sources,notes',
            'person_id' => 'nullable|integer|min:1',
            'tree_id' => 'nullable|integer|min:1',
            'relationship_type' => 'nullable|string|max:255',
            'related_person_id' => 'nullable|integer|min:1',
        ]);

        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);
            $workspace = $workspaceService->buildWorkspace($run);
            $selectedPacket = $selectedPacketComposer->compose(
                $run,
                $workspace,
                $validated['packet_key'] ?? null,
                $validated['packet_label'] ?? null
            );

            if ($selectedPacket === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_found'],
                ], 404);
            }

            $draftInput = (array) ($selectedPacket['draft_entry']['draft_input'] ?? []);
            $proposalPreview = self::findProposalPreviewForPacket($workspace, $selectedPacket);

            if ($draftInput === [] || $proposalPreview === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_ready_for_approval_preview'],
                ], 422);
            }

            $context = [
                'approved_sections' => (array) ($validated['approved_sections'] ?? []),
                'person_id' => $validated['person_id'] ?? null,
                'tree_id' => $validated['tree_id'] ?? ($run['tree_id'] ?? null),
                'relationship_type' => $validated['relationship_type'] ?? null,
                'related_person_id' => $validated['related_person_id'] ?? null,
            ];
            $contextValidation = $this->validateApprovalDraftContext($run, $context);
            if (! ($contextValidation['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $contextValidation['error'] ?? 'invalid_approval_draft_context'],
                ], 422);
            }

            $approvalDraftPreview = $approvalDraftPreviewService->preview($proposalPreview, $draftInput, $context);
            $planHash = GenealogyIntakeRunStoreService::computeApprovalApplyPlanHash($approvalDraftPreview);
            $approvalDraftPreview['plan_hash'] = $planHash;
            $existingApplyState = (array) (($selectedPacket['packet']['approval_apply_state'] ?? []));
            if (($existingApplyState['plan_hash'] ?? '') === $planHash && ! empty($existingApplyState['success'])) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'run_key' => $runKey,
                        'packet' => [
                            'packet_key' => (string) ($selectedPacket['packet_key'] ?? ''),
                            'packet_label' => (string) ($selectedPacket['packet_label'] ?? ''),
                        ],
                        'approval_draft_preview' => $approvalDraftPreview,
                        'apply_result' => [
                            'success' => true,
                            'applied_person_changes' => [],
                            'applied_relationships' => [],
                            'failed' => [],
                            'skipped' => [['type' => 'already_applied_current_plan']],
                            'errors' => [],
                            'audit' => ['packet_key' => (string) ($selectedPacket['packet_key'] ?? '')],
                        ],
                        'apply_summary' => [
                            'status' => 'success',
                            'summary' => 'This approval draft plan was already applied for the current packet state.',
                            'counts' => [
                                'applied_person_changes' => 0,
                                'applied_relationships' => 0,
                                'failed' => 0,
                                'skipped' => 1,
                            ],
                            'highlights' => ['already_applied_current_plan'],
                            'next_action' => 'Review the saved apply state before making further changes.',
                        ],
                    ],
                ]);
            }

            $applyResult = $approvalApplyService->apply($approvalDraftPreview);
            $applySummary = $approvalApplySummaryService->summarize($applyResult);
            $runStore->recordPacketApprovalApplyState(
                $runKey,
                [
                    'packet_key' => (string) ($selectedPacket['packet_key'] ?? ''),
                    'packet_label' => (string) ($selectedPacket['packet_label'] ?? ''),
                ],
                $approvalDraftPreview,
                $applyResult,
                $applySummary
            );

            return response()->json([
                'success' => $applyResult['success'],
                'data' => [
                    'run_key' => $runKey,
                    'packet' => [
                        'packet_key' => (string) ($selectedPacket['packet_key'] ?? ''),
                        'packet_label' => (string) ($selectedPacket['packet_label'] ?? ''),
                    ],
                    'approval_draft_preview' => $approvalDraftPreview,
                    'apply_result' => $applyResult,
                    'apply_summary' => $applySummary,
                ],
            ], $applyResult['success'] ? 200 : 422);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to apply intake approval draft', $e);
        }
    }

    /**
     * Generate pending genealogy proposal rows from a ready intake approval-draft plan.
     */
    public function generateIntakeRunProposals(
        Request $request,
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeWorkspaceService $workspaceService,
        GenealogyIntakeSelectedPacketComposer $selectedPacketComposer,
        GenealogyIntakeApprovalDraftPreviewService $approvalDraftPreviewService,
        GenealogyIntakeProposalGenerationPersistenceService $proposalGenerationService,
        GenealogyIntakeProposalGenerationSummaryService $proposalGenerationSummaryService
    ): JsonResponse {
        $validated = $request->validate([
            'packet_label' => 'required_without:packet_key|nullable|string',
            'packet_key' => 'required_without:packet_label|nullable|string',
            'approved_sections' => 'nullable|array',
            'approved_sections.*' => 'string|in:identity,relationships,events,sources,notes',
            'person_id' => 'nullable|integer|min:1',
            'tree_id' => 'nullable|integer|min:1',
            'relationship_type' => 'nullable|string|max:255',
            'related_person_id' => 'nullable|integer|min:1',
        ]);

        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);
            $workspace = $workspaceService->buildWorkspace($run);
            $selectedPacket = $selectedPacketComposer->compose(
                $run,
                $workspace,
                $validated['packet_key'] ?? null,
                $validated['packet_label'] ?? null
            );

            if ($selectedPacket === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_found'],
                ], 404);
            }

            $draftInput = (array) ($selectedPacket['draft_entry']['draft_input'] ?? []);
            $proposalPreview = self::findProposalPreviewForPacket($workspace, $selectedPacket);

            if ($draftInput === [] || $proposalPreview === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_ready_for_approval_preview'],
                ], 422);
            }

            $context = [
                'approved_sections' => (array) ($validated['approved_sections'] ?? []),
                'person_id' => $validated['person_id'] ?? null,
                'tree_id' => $validated['tree_id'] ?? ($run['tree_id'] ?? null),
                'relationship_type' => $validated['relationship_type'] ?? null,
                'related_person_id' => $validated['related_person_id'] ?? null,
            ];
            $contextValidation = $this->validateApprovalDraftContext($run, $context);
            if (! ($contextValidation['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $contextValidation['error'] ?? 'invalid_approval_draft_context'],
                ], 422);
            }

            $approvalDraftPreview = $approvalDraftPreviewService->preview($proposalPreview, $draftInput, $context);
            $approvalDraftPreview['plan_hash'] = GenealogyIntakeRunStoreService::computeApprovalApplyPlanHash($approvalDraftPreview);

            $generationResult = $proposalGenerationService->persist($approvalDraftPreview);
            $generationSummary = $proposalGenerationSummaryService->summarize($generationResult);
            $runStore->recordPacketProposalGenerationState(
                $runKey,
                [
                    'packet_key' => (string) ($selectedPacket['packet_key'] ?? ''),
                    'packet_label' => (string) ($selectedPacket['packet_label'] ?? ''),
                ],
                $approvalDraftPreview,
                $generationResult,
                $generationSummary
            );

            return response()->json([
                'success' => $generationResult['success'],
                'data' => [
                    'run_key' => $runKey,
                    'packet' => [
                        'packet_key' => (string) ($selectedPacket['packet_key'] ?? ''),
                        'packet_label' => (string) ($selectedPacket['packet_label'] ?? ''),
                    ],
                    'approval_draft_preview' => $approvalDraftPreview,
                    'generation_result' => $generationResult,
                    'generation_summary' => $generationSummary,
                ],
            ], $generationResult['success'] ? 200 : 422);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate intake proposals', $e);
        }
    }

    /**
     * Load persisted proposal rows linked to a packet's saved proposal_generation_state.
     */
    public function getIntakeRunGeneratedProposals(
        Request $request,
        string $runKey,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeWorkspaceService $workspaceService,
        GenealogyIntakeSelectedPacketComposer $selectedPacketComposer,
        GenealogyIntakeGeneratedProposalQueryService $queryService
    ): JsonResponse {
        $validated = $request->validate([
            'packet_label' => 'required_without:packet_key|nullable|string',
            'packet_key' => 'required_without:packet_label|nullable|string',
        ]);

        try {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $loaded['error'] ?? 'run_not_found'],
                ], 404);
            }

            $run = (array) ($loaded['run'] ?? []);
            $workspace = $workspaceService->buildWorkspace($run);
            $selectedPacket = $selectedPacketComposer->compose(
                $run,
                $workspace,
                $validated['packet_key'] ?? null,
                $validated['packet_label'] ?? null
            );

            if ($selectedPacket === null) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'packet_not_found'],
                ], 404);
            }

            $packet = (array) ($selectedPacket['packet'] ?? []);
            $generationState = (array) ($packet['proposal_generation_state'] ?? []);
            if ($generationState === []) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'no_generated_proposals_for_packet'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'run_key' => $runKey,
                    'review' => $queryService->buildPacketProposalReview($run, $packet),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to load intake-generated proposals', $e);
        }
    }

    private static function findProposalPreviewForPacket(array $workspace, array $selectedPacket): ?array
    {
        $packetKey = trim(mb_strtolower((string) ($selectedPacket['packet_key'] ?? '')));
        $packetLabel = trim(mb_strtolower((string) ($selectedPacket['packet_label'] ?? '')));

        foreach ((array) ($workspace['proposal_previews']['ready_packets'] ?? []) as $entry) {
            $entryKey = trim(mb_strtolower((string) ($entry['packet_key'] ?? '')));
            $entryLabel = trim(mb_strtolower((string) ($entry['packet_label'] ?? '')));

            if ($packetKey !== '' && $entryKey !== '' && $packetKey === $entryKey) {
                return (array) ($entry['preview'] ?? []);
            }

            if ($packetLabel !== '' && $packetLabel === $entryLabel) {
                return (array) ($entry['preview'] ?? []);
            }
        }

        return null;
    }

    private function validateApprovalDraftContext(array $run, array $context): array
    {
        $runTreeId = (int) ($run['tree_id'] ?? 0);
        $contextTreeId = (int) ($context['tree_id'] ?? 0);

        if ($runTreeId > 0 && $contextTreeId > 0 && $runTreeId !== $contextTreeId) {
            return ['success' => false, 'error' => 'approval_draft_tree_mismatch'];
        }

        foreach (['person_id', 'related_person_id'] as $key) {
            $personId = (int) ($context[$key] ?? 0);
            if ($personId < 1 || $runTreeId < 1) {
                continue;
            }

            $person = DB::selectOne(
                'SELECT id FROM genealogy_persons WHERE id = ? AND tree_id = ? LIMIT 1',
                [$personId, $runTreeId]
            );

            if (! $person) {
                return ['success' => false, 'error' => $key.'_tree_mismatch'];
            }
        }

        return ['success' => true];
    }

    /**
     * Get media thumbnail - uses ThumbnailService when file is in registry, falls back to Nextcloud
     */
    public function getMediaThumbnail(int $id, Request $request)
    {
        $size = $request->query('size', 'medium');
        $placeholder = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        try {
            // Get the media record to find the Nextcloud path
            $media = $this->genealogyService->getMedia($id);

            if (! $media || empty($media['nextcloud_path']) || ! $media['file_exists']) {
                return response($placeholder, 200)
                    ->header('Content-Type', 'image/gif')
                    ->header('Cache-Control', 'public, max-age=86400');
            }

            // Try to find this file in file_registry and use ThumbnailService
            $fileRecord = DB::selectOne(
                "SELECT asset_uuid, mime_type FROM file_registry WHERE current_path = ? AND status = 'active' LIMIT 1",
                [$media['nextcloud_path']]
            );

            if ($fileRecord && $fileRecord->asset_uuid) {
                $thumbnailService = app(\App\Services\ThumbnailService::class);

                // Check if mime type is supported for thumbnails
                if ($thumbnailService->isSupportedMimeType($fileRecord->mime_type ?? '')) {
                    $result = $thumbnailService->getThumbnail($fileRecord->asset_uuid, $size);

                    if ($result['success'] && file_exists($result['path'])) {
                        return response()->file($result['path'], [
                            'Content-Type' => 'image/jpeg',
                            'Cache-Control' => 'public, max-age=86400',
                        ]);
                    }
                }
            }

            // Fallback only for browser-renderable image types. Documents and archival
            // formats should show a placeholder instead of forcing raw downloads into <img>.
            if (! $this->isBrowserRenderableThumbnailFallback($media, $fileRecord->mime_type ?? null)) {
                return response($placeholder, 200)
                    ->header('Content-Type', 'image/gif')
                    ->header('Cache-Control', 'public, max-age=300');
            }

            // Fallback: Proxy the full browser-renderable image from Nextcloud.
            $downloadResult = app(\App\Services\NextcloudFileApiService::class)->downloadFile($media['nextcloud_path']);

            if (! $downloadResult['success']) {
                return response($placeholder, 200)
                    ->header('Content-Type', 'image/gif')
                    ->header('Cache-Control', 'public, max-age=300');
            }

            return response($downloadResult['content'], 200)
                ->header('Content-Type', $downloadResult['mime_type'] ?? 'image/jpeg')
                ->header('Cache-Control', 'public, max-age=86400')
                ->header('Content-Length', $downloadResult['size']);

        } catch (Exception $e) {
            Log::warning('Genealogy thumbnail error', ['id' => $id, 'error' => $e->getMessage()]);

            return response($placeholder, 200)
                ->header('Content-Type', 'image/gif')
                ->header('Cache-Control', 'public, max-age=300');
        }
    }

    private function isBrowserRenderableThumbnailFallback(array $media, ?string $registryMimeType = null): bool
    {
        $mimeType = strtolower((string) ($registryMimeType ?: ($media['mime_type'] ?? '')));
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true)) {
            return true;
        }

        $extension = strtolower((string) ($media['file_format'] ?? ''));
        if ($extension === '') {
            $path = (string) ($media['local_filename'] ?? $media['nextcloud_path'] ?? $media['original_path'] ?? '');
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }

        return in_array($extension, ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'bmp'], true);
    }

    /**
     * Upload a new media file
     */
    public function uploadMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:102400', // 100MB max
                'title' => 'nullable|string|max:500',
                'description' => 'nullable|string',
                'date' => 'nullable|string|max:50',
                'person_id' => 'nullable|integer|exists:genealogy_persons,id',
            ]);

            $tree = $this->genealogyService->getTree($treeId);
            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            $file = $request->file('file');
            $tempPath = $file->store('temp/genealogy');
            $fullPath = Storage::path($tempPath);

            try {
                $result = $this->mediaService->uploadMedia(
                    $treeId,
                    $tree->name,
                    $fullPath,
                    [
                        'filename' => $file->getClientOriginalName(),
                        'title' => $request->input('title'),
                        'description' => $request->input('description'),
                        'date' => $request->input('date'),
                    ]
                );

                if (! $result['success']) {
                    return response()->json([
                        'success' => false,
                        'error' => ['message' => $result['error']],
                    ], 400);
                }

                // Link to person if specified
                $personId = $request->input('person_id');
                if ($personId) {
                    $this->genealogyService->linkPersonToMedia($personId, $result['media_id'], []);
                }

                $media = $this->genealogyService->getMedia($result['media_id']);

                return response()->json([
                    'success' => true,
                    'data' => ['media' => $media],
                    'message' => 'Media uploaded successfully',
                ], 201);
            } finally {
                @unlink($fullPath);
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to upload media', $e);
        }
    }

    /**
     * Link a person to media
     */
    public function linkPersonToMedia(Request $request, int $mediaId): JsonResponse
    {
        try {
            $request->validate([
                'person_id' => 'required|integer|exists:genealogy_persons,id',
                'is_primary' => 'nullable|boolean',
                'face_region_x' => 'nullable|numeric|min:0|max:1',
                'face_region_y' => 'nullable|numeric|min:0|max:1',
                'face_region_w' => 'nullable|numeric|min:0|max:1',
                'face_region_h' => 'nullable|numeric|min:0|max:1',
            ]);

            $this->genealogyService->linkPersonToMedia(
                $request->input('person_id'),
                $mediaId,
                $request->all()
            );

            $media = $this->genealogyService->getMedia($mediaId);

            return response()->json([
                'success' => true,
                'data' => ['media' => $media],
                'message' => 'Person linked to media',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to link person to media', $e);
        }
    }

    /**
     * Unlink a person from media
     */
    public function unlinkPersonFromMedia(int $mediaId, int $personId): JsonResponse
    {
        try {
            $unlinked = $this->genealogyService->unlinkPersonFromMedia($personId, $mediaId);

            if (! $unlinked) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Link not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Person unlinked from media',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to unlink person from media', $e);
        }
    }

    /**
     * Link a family to media (Phase 3.3)
     */
    public function linkFamilyToMedia(Request $request, int $mediaId): JsonResponse
    {
        try {
            $request->validate([
                'family_id' => 'required|integer|exists:genealogy_families,id',
            ]);

            $this->genealogyService->linkFamilyToMedia(
                $request->input('family_id'),
                $mediaId
            );

            $media = $this->genealogyService->getMedia($mediaId);

            return response()->json([
                'success' => true,
                'data' => ['media' => $media],
                'message' => 'Family linked to media',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to link family to media', $e);
        }
    }

    /**
     * Unlink a family from media (Phase 3.3)
     */
    public function unlinkFamilyFromMedia(int $mediaId, int $familyId): JsonResponse
    {
        try {
            $unlinked = $this->genealogyService->unlinkFamilyFromMedia($familyId, $mediaId);

            if (! $unlinked) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Link not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family unlinked from media',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to unlink family from media', $e);
        }
    }

    /**
     * Delete media
     */
    public function deleteMedia(int $id): JsonResponse
    {
        try {
            $deleted = $this->mediaService->deleteMedia($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to delete media'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Media deleted successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete media', $e);
        }
    }

    // ========================================================================
    // AI MEDIA ANALYSIS ENDPOINTS
    // ========================================================================

    /**
     * Analyze a single media item with AI
     *
     * Extracts EXIF data, generates AI description, and extracts subject tags.
     */
    public function analyzeMedia(int $mediaId): JsonResponse
    {
        try {
            $result = $this->mediaService->analyzeMedia($mediaId);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error'] ?? 'Analysis failed'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Media analyzed successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to analyze media', $e);
        }
    }

    /**
     * Analyze all pending media for a tree
     */
    public function analyzeTreeMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);

            $results = $this->mediaService->analyzeTreeMedia($treeId, $limit);

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "Analyzed {$results['success']}/{$results['total']} media items",
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to analyze tree media', $e);
        }
    }

    /**
     * Get AI analysis status for a tree
     */
    public function getAnalysisStatus(int $treeId): JsonResponse
    {
        try {
            $status = $this->mediaService->getAnalysisStatus($treeId);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get analysis status', $e);
        }
    }

    /**
     * Reset analysis status for retry
     */
    public function resetAnalysisStatus(int $mediaId): JsonResponse
    {
        try {
            $success = $this->mediaService->resetAnalysisStatus($mediaId);

            if (! $success) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to reset analysis status'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Analysis status reset to pending',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reset analysis status', $e);
        }
    }

    /**
     * Reset all failed analyses for a tree
     */
    public function resetFailedAnalyses(int $treeId): JsonResponse
    {
        try {
            $count = $this->mediaService->resetFailedAnalyses($treeId);

            return response()->json([
                'success' => true,
                'data' => ['reset_count' => $count],
                'message' => "Reset {$count} failed analyses to pending",
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reset failed analyses', $e);
        }
    }

    /**
     * Scan a Nextcloud folder for media files and import them
     */
    public function scanNextcloudFolder(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'folder' => 'required|string|max:500',
                'recursive' => 'nullable|boolean',
                'filter_for_matches' => 'nullable|boolean',
            ]);

            $folder = $request->input('folder');
            $recursive = $request->input('recursive', true);
            $filterForMatches = $request->input('filter_for_matches', false);

            ExecuteGenealogyFolderScan::dispatch($treeId, $folder, $recursive, $filterForMatches);

            return response()->json([
                'success' => true,
                'data' => [
                    'tree_id' => $treeId,
                    'folder' => $folder,
                    'recursive' => $recursive,
                    'filter_for_matches' => $filterForMatches,
                    'queued' => true,
                    'queue' => 'long-running',
                ],
                'message' => "Nextcloud folder scan queued for {$folder}",
            ], 202);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to scan Nextcloud folder', $e);
        }
    }

    /**
     * List available Nextcloud folders for scanning
     */
    public function listNextcloudFolders(): JsonResponse
    {
        try {
            $folders = $this->mediaService->listAvailableNextcloudFolders();

            return response()->json([
                'success' => true,
                'data' => ['folders' => $folders],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list Nextcloud folders', $e);
        }
    }

    /**
     * Scan Nextcloud folder and import ONLY files with face EXIF metadata
     */
    public function scanNextcloudFolderWithFaces(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'folder' => 'required|string|max:500',
                'recursive' => 'nullable|boolean',
            ]);

            $folder = $request->input('folder');
            $recursive = $request->input('recursive', true);

            ExecuteGenealogyFaceImportScan::dispatch($treeId, $folder, $recursive);

            return response()->json([
                'success' => true,
                'data' => [
                    'tree_id' => $treeId,
                    'folder' => $folder,
                    'recursive' => $recursive,
                    'queued' => true,
                    'queue' => 'long-running',
                ],
                'message' => "Face import scan queued for {$folder}",
            ], 202);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to scan Nextcloud folder for faces', $e);
        }
    }

    /**
     * Start background face scan job (for long-running scans)
     */
    public function startBackgroundFaceScan(Request $request, int $treeId): JsonResponse
    {
        try {
            $request->validate([
                'folder' => 'required|string|max:500',
                'recursive' => 'nullable|boolean',
            ]);

            $folder = $request->input('folder');
            $recursive = $request->input('recursive', true);

            // Dispatch the job
            \App\Jobs\GenealogyFaceScanJob::dispatch($treeId, $folder, $recursive);

            return response()->json([
                'success' => true,
                'message' => "Background face scan started for folder: {$folder}",
                'data' => [
                    'tree_id' => $treeId,
                    'folder' => $folder,
                    'recursive' => $recursive,
                    'status_url' => "/api/genealogy/trees/{$treeId}/media/face-scan-status",
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to start background face scan', $e);
        }
    }

    /**
     * Get face scan job status
     */
    public function getFaceScanStatus(int $treeId): JsonResponse
    {
        try {
            $cacheKey = \App\Jobs\GenealogyFaceScanJob::getStatusCacheKey($treeId);
            $status = \Illuminate\Support\Facades\Cache::get($cacheKey);

            if (! $status) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'status' => 'no_job',
                        'message' => 'No face scan job found or job has expired',
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get face scan status', $e);
        }
    }

    /**
     * Confirm or reject a face tag (Phase 3.5)
     */
    public function confirmFaceTag(Request $request, int $mediaId, int $personId): JsonResponse
    {
        try {
            $request->validate([
                'confirmed' => 'required|boolean',
            ]);

            $this->genealogyService->confirmFaceTag(
                $personId,
                $mediaId,
                $request->input('confirmed')
            );

            $media = $this->genealogyService->getMedia($mediaId);

            return response()->json([
                'success' => true,
                'data' => ['media' => $media],
                'message' => $request->input('confirmed') ? 'Face confirmed' : 'Face rejected',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to confirm face', $e);
        }
    }

    /**
     * Get unconfirmed faces for review (Phase 3.5)
     */
    public function getUnconfirmedFaces(int $treeId): JsonResponse
    {
        try {
            $faces = $this->genealogyService->getUnconfirmedFaces($treeId);
            $count = $this->genealogyService->getUnconfirmedFaceCount($treeId);

            // Add download URLs
            foreach ($faces as &$face) {
                if ($face->media_id) {
                    $face->download_url = $this->mediaService->getMediaUrl($face->media_id);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'faces' => $faces,
                    'count' => $count,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get unconfirmed faces', $e);
        }
    }

    /**
     * Re-sync media paths from GEDCOM file
     * This is useful when media was imported without file paths
     */
    public function syncMediaPaths(Request $request, int $treeId): JsonResponse
    {
        try {
            $tree = $this->genealogyService->getTree($treeId);
            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            // Look for the GEDCOM file in the imports directory
            $gedcomPath = $request->input('gedcom_path');
            if (! $gedcomPath) {
                // Try to find it automatically
                $importDir = storage_path('app/imports/genealogy');
                $pattern = '*'.str_replace(' ', '*', $tree->name).'*.ged';
                $files = glob($importDir.'/'.$pattern);

                if (empty($files)) {
                    return response()->json([
                        'success' => false,
                        'error' => ['message' => 'No GEDCOM file found. Please provide gedcom_path parameter.'],
                    ], 400);
                }

                // Use most recent file
                usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));
                $gedcomPath = $files[0];
            }

            if (! file_exists($gedcomPath)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "GEDCOM file not found: {$gedcomPath}"],
                ], 404);
            }

            ExecuteGenealogyMediaPathSync::dispatch($treeId, $gedcomPath);

            return response()->json([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'queue' => 'long-running',
                    'tree_id' => $treeId,
                    'gedcom_file' => basename($gedcomPath),
                ],
                'message' => 'Media path sync queued',
            ], 202);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to sync media paths', $e);
        }
    }

    /**
     * Get import media status for a tree
     */
    public function getMediaImportStatus(int $treeId): JsonResponse
    {
        try {
            $status = $this->mediaService->getImportStatus($treeId);

            return response()->json([
                'success' => true,
                'data' => ['status' => $status],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get media import status', $e);
        }
    }

    /**
     * Import media files from Windows
     */
    public function importTreeMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $tree = $this->genealogyService->getTree($treeId);
            if (! $tree) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Tree not found'],
                ], 404);
            }

            $windowsBasePath = $request->input('windows_base_path');
            $results = $this->mediaService->importTreeMedia($treeId, $tree->name, $windowsBasePath);

            return response()->json([
                'success' => true,
                'data' => ['results' => $results],
                'message' => "Linked {$results['linked']} of {$results['total']} media files",
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to import media', $e);
        }
    }

    // ========================================================================
    // STATUS & UTILITIES
    // ========================================================================

    /**
     * Get service status
     */
    public function getStatus(): JsonResponse
    {
        try {
            $mediaStatus = $this->mediaService->getStatus();

            return response()->json([
                'success' => true,
                'data' => [
                    'service' => 'operational',
                    'media' => $mediaStatus,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get status', $e);
        }
    }

    /**
     * Get all persons in a tree (for dropdown/autocomplete)
     */
    public function listPersons(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);
            $persons = $this->genealogyService->listPersons($treeId, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'persons' => $persons,
                    'count' => count($persons),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list persons', $e);
        }
    }

    /**
     * Get tree data for visualization (persons and families as maps)
     *
     * Supports configurable generations for performance optimization.
     * Returns metadata indicating persons with more data available for lazy loading.
     *
     * @return JsonResponse
     *
     * Query parameters:
     * - person_id (required): Starting person for tree visualization
     * - mode (optional): 'hourglass' (default), 'ancestors', or 'descendants'
     * - generations (optional): Number of generations to load (1-20, default 5)
     */
    public function getTreeData(Request $request, int $treeId): JsonResponse
    {
        try {
            $personId = $request->input('person_id');
            $mode = $request->input('mode', 'hourglass');
            $generations = (int) $request->input('generations', 5);

            if (! $personId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'person_id is required'],
                ], 400);
            }

            $data = $this->genealogyService->getTreeVisualizationData($treeId, (int) $personId, $mode, $generations);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get tree data', $e);
        }
    }

    /**
     * Get recent additions to a tree
     */
    public function getRecentAdditions(int $treeId): JsonResponse
    {
        try {
            $recent = $this->genealogyService->getRecentAdditions($treeId);

            return response()->json([
                'success' => true,
                'data' => ['recent' => $recent],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get recent additions', $e);
        }
    }

    // ========================================================================
    // PHASE 4: EXPORT, BACKUP & DATA INTEGRITY
    // ========================================================================

    /**
     * Validate tree data integrity
     */
    public function validateTree(int $treeId): JsonResponse
    {
        try {
            $validation = $this->genealogyService->validateTreeIntegrity($treeId);

            return response()->json([
                'success' => true,
                'data' => $validation,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to validate tree', $e);
        }
    }

    /**
     * Get detailed tree statistics
     */
    public function getTreeStatistics(int $treeId): JsonResponse
    {
        try {
            $statistics = $this->genealogyService->getDetailedTreeStatistics($treeId);

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get tree statistics', $e);
        }
    }

    /**
     * Get backup status for a tree
     */
    public function getBackupStatus(int $treeId): JsonResponse
    {
        try {
            $status = $this->genealogyService->getBackupStatus($treeId);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get backup status', $e);
        }
    }

    // ========================================================================
    // PHASE 4.3-4.4: DUPLICATE DETECTION & MERGE
    // ========================================================================

    /**
     * Find potential duplicate persons in a tree
     */
    public function findDuplicates(Request $request, int $treeId): JsonResponse
    {
        try {
            $options = [
                'minScore' => (float) $request->input('min_score', 0.6),
                'limit' => (int) $request->input('limit', 100),
                'includeResolved' => (bool) $request->input('include_resolved', false),
            ];

            $duplicates = $this->genealogyService->findDuplicatePersons($treeId, $options);

            return response()->json([
                'success' => true,
                'data' => $duplicates,
                'meta' => [
                    'count' => count($duplicates),
                    'options' => $options,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to find duplicates', $e);
        }
    }

    /**
     * Get duplicate detection statistics
     */
    public function getDuplicateStats(int $treeId): JsonResponse
    {
        try {
            $stats = $this->genealogyService->getDuplicateStats($treeId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get duplicate stats', $e);
        }
    }

    /**
     * Resolve a duplicate pair (mark as rejected/not duplicates)
     */
    public function resolveDuplicate(Request $request, int $treeId): JsonResponse
    {
        try {
            $person1Id = (int) $request->input('person1_id');
            $person2Id = (int) $request->input('person2_id');
            $status = $request->input('status', 'rejected'); // rejected or pending_merge

            if (! $person1Id || ! $person2Id) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Both person IDs are required'],
                ], 400);
            }

            $result = $this->genealogyService->resolveDuplicatePair($treeId, $person1Id, $person2Id, $status);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Duplicate pair resolved' : 'Failed to resolve',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to resolve duplicate', $e);
        }
    }

    /**
     * Merge two persons into one
     */
    public function mergePersons(Request $request, int $treeId): JsonResponse
    {
        try {
            $primaryId = (int) $request->input('primary_id');
            $secondaryId = (int) $request->input('secondary_id');

            if (! $primaryId || ! $secondaryId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Both primary_id and secondary_id are required'],
                ], 400);
            }

            $options = [
                'keepSecondaryNames' => (bool) $request->input('keep_secondary_names', true),
                'fillMissingDates' => (bool) $request->input('fill_missing_dates', true),
            ];

            $result = $this->genealogyService->mergePersons($treeId, $primaryId, $secondaryId, $options);

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
            ], $result['success'] ? 200 : 400);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to merge persons', $e);
        }
    }

    // ========================================================================
    // PHASE 5: ADVANCED VISUALIZATION & ANALYSIS
    // ========================================================================

    /**
     * Get person timeline
     */
    public function getPersonTimeline(int $personId): JsonResponse
    {
        try {
            $timeline = $this->genealogyService->getPersonTimeline($personId);

            return response()->json([
                'success' => true,
                'data' => $timeline,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get person timeline', $e);
        }
    }

    /**
     * Get tree-wide timeline (Priority 4.2)
     * Returns chronological view of all family events across the entire tree
     *
     * Query params: start_year, end_year, event_types (comma-separated), surname, limit
     */
    public function getTreeTimeline(Request $request, int $treeId): JsonResponse
    {
        try {
            $options = [
                'start_year' => $request->input('start_year') ? (int) $request->input('start_year') : null,
                'end_year' => $request->input('end_year') ? (int) $request->input('end_year') : null,
                'event_types' => $request->input('event_types') ? explode(',', $request->input('event_types')) : null,
                'surname' => $request->input('surname'),
                'limit' => $request->input('limit') ? (int) $request->input('limit') : 500,
            ];

            $timeline = $this->genealogyService->getTreeTimeline($treeId, $options);

            return response()->json([
                'success' => true,
                'data' => $timeline,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get tree timeline', $e);
        }
    }

    /**
     * Get extended person timeline with family context options
     * Uses GenealogyTimelineService for comprehensive timeline data
     */
    public function getPersonTimelineExtended(Request $request, int $personId): JsonResponse
    {
        try {
            $options = [
                'include_family' => $request->boolean('include_family', true),
                'include_parents' => $request->boolean('include_parents', true),
                'include_siblings' => $request->boolean('include_siblings', false),
                'event_types' => $request->input('event_types'),
                'start_year' => $request->input('start_year') ? (int) $request->input('start_year') : null,
                'end_year' => $request->input('end_year') ? (int) $request->input('end_year') : null,
            ];

            $timelineService = new \App\Services\Genealogy\GenealogyTimelineService;
            $timeline = $timelineService->getPersonTimeline($personId, $options);

            return response()->json($timeline);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get person timeline', $e);
        }
    }

    /**
     * Search places with autocomplete
     * Uses PlaceAuthorityService
     */
    public function searchPlaces(Request $request): JsonResponse
    {
        try {
            $query = $request->input('q', '');
            $limit = min($request->input('limit', 20), 100);
            $placeType = $request->input('place_type');

            if (strlen($query) < 2) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $placeService = new \App\Services\Genealogy\PlaceAuthorityService;
            $results = $placeService->searchPlaces($query, [
                'limit' => $limit,
                'place_type' => $placeType,
            ]);

            // Format results with hierarchy path
            $formatted = array_map(function ($place) use ($placeService) {
                $hierarchy = $placeService->getPlaceWithHierarchy($place->id);

                return [
                    'id' => $place->id,
                    'name' => $place->name,
                    'short_name' => $place->short_name,
                    'place_type' => $place->place_type,
                    'usage_count' => $place->usage_count ?? 0,
                    'hierarchy_path' => $hierarchy['full_path'] ?? $place->name,
                    'latitude' => $place->latitude,
                    'longitude' => $place->longitude,
                ];
            }, $results);

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search places', $e);
        }
    }

    /**
     * Get place details with hierarchy
     */
    public function getPlace(int $placeId): JsonResponse
    {
        try {
            $placeService = new \App\Services\Genealogy\PlaceAuthorityService;
            $result = $placeService->getPlaceWithHierarchy($placeId);

            if (! $result) {
                return response()->json(['success' => false, 'error' => 'Place not found'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get place', $e);
        }
    }

    /**
     * Normalize existing place text to authority records
     */
    public function normalizePlaces(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);

            $placeService = new \App\Services\Genealogy\PlaceAuthorityService;
            $results = $placeService->backfillEventPlaces($limit);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to normalize places', $e);
        }
    }

    /**
     * Search Find A Grave for burial records (Priority 4.4)
     */
    public function searchFindAGrave(Request $request): JsonResponse
    {
        try {
            $criteria = [
                'given_name' => $request->input('given_name'),
                'surname' => $request->input('surname'),
                'birth_year' => $request->input('birth_year'),
                'death_year' => $request->input('death_year'),
                'cemetery' => $request->input('cemetery'),
                'city' => $request->input('city'),
                'state' => $request->input('state'),
                'country' => $request->input('country'),
            ];

            $options = [
                'year_range' => $request->input('year_range', 'on'),
                'page' => $request->input('page', 1),
            ];

            $provider = new \App\Services\Genealogy\Providers\FindAGraveProvider;
            $results = $provider->searchRecords($criteria, $options);

            return response()->json([
                'success' => $results['success'] ?? false,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search Find A Grave', $e);
        }
    }

    /**
     * Get Find A Grave memorial details (Priority 4.4)
     */
    public function getFindAGraveMemorial(string $memorialId): JsonResponse
    {
        try {
            $provider = new \App\Services\Genealogy\Providers\FindAGraveProvider;
            $memorial = $provider->getRecord($memorialId);

            if (! $memorial) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Memorial not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $memorial,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get memorial details', $e);
        }
    }

    /**
     * Calculate relationship between two persons
     */
    public function calculateRelationship(Request $request, int $treeId): JsonResponse
    {
        try {
            $personId1 = (int) $request->input('person1_id');
            $personId2 = (int) $request->input('person2_id');

            if (! $personId1 || ! $personId2) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Both person IDs are required'],
                ], 400);
            }

            $relationship = $this->genealogyService->calculateRelationship($personId1, $personId2, $treeId);

            return response()->json([
                'success' => true,
                'data' => $relationship,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to calculate relationship', $e);
        }
    }

    /**
     * Get geographic distribution of places
     */
    public function getGeographicDistribution(int $treeId): JsonResponse
    {
        try {
            $distribution = $this->genealogyService->getGeographicDistribution($treeId);

            return response()->json([
                'success' => true,
                'data' => $distribution,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get geographic distribution', $e);
        }
    }

    // ========================================================================
    // Phase 6: Reports & Printing
    // ========================================================================

    /**
     * Get Family Group Sheet report
     */
    public function getFamilyGroupSheet(int $familyId): JsonResponse
    {
        try {
            $report = $this->genealogyService->getFamilyGroupSheet($familyId);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate family group sheet', $e);
        }
    }

    /**
     * Get Pedigree Chart report
     */
    public function getPedigreeChart(Request $request, int $personId): JsonResponse
    {
        try {
            $generations = $request->input('generations', 4);
            $report = $this->genealogyService->getPedigreeChart($personId, min($generations, 10));

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate pedigree chart', $e);
        }
    }

    /**
     * Get Descendant Report
     */
    public function getDescendantReport(Request $request, int $personId): JsonResponse
    {
        try {
            $generations = $request->input('generations', 10);
            $report = $this->genealogyService->getDescendantReport($personId, min($generations, 15));

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate descendant report', $e);
        }
    }

    /**
     * Get Ahnentafel Report (ancestor numbering system)
     *
     * E.2 Advanced Reports - Ahnentafel uses standard genealogical numbering:
     * 1=Subject, 2=Father, 3=Mother, 4=Pat.Grandfather, 5=Pat.Grandmother, etc.
     */
    public function getAhnentafelReport(Request $request, int $personId): JsonResponse
    {
        try {
            $generations = $request->input('generations', 10);
            $report = $this->genealogyService->getAhnentafelReport($personId, min($generations, 15));

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate ahnentafel report', $e);
        }
    }

    /**
     * Get Individual Summary Report
     */
    public function getIndividualSummary(int $personId): JsonResponse
    {
        try {
            $report = $this->genealogyService->getIndividualSummary($personId);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate individual summary', $e);
        }
    }

    // ========================================================================
    // PHASE 6: PDF REPORTS (TCPDF)
    // ========================================================================

    /**
     * Generate Family Group Sheet PDF
     */
    public function getFamilyGroupSheetPdf(int $familyId)
    {
        try {
            $pdfContent = $this->pdfService->generateFamilyGroupSheet($familyId);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="family_group_'.$familyId.'.pdf"');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate Family Group Sheet PDF', $e);
        }
    }

    /**
     * Generate Pedigree Chart PDF
     */
    public function getPedigreeChartPdf(Request $request, int $personId)
    {
        try {
            $generations = (int) $request->input('generations', 4);
            $pdfContent = $this->pdfService->generatePedigreeChart($personId, $generations);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="pedigree_'.$personId.'.pdf"');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate Pedigree Chart PDF', $e);
        }
    }

    /**
     * Generate Descendant Report PDF
     */
    public function getDescendantReportPdf(Request $request, int $personId)
    {
        try {
            $generations = (int) $request->input('generations', 4);
            $pdfContent = $this->pdfService->generateDescendantReport($personId, $generations);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="descendants_'.$personId.'.pdf"');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate Descendant Report PDF', $e);
        }
    }

    /**
     * Generate Ahnentafel Report PDF
     */
    public function getAhnentafelReportPdf(Request $request, int $personId)
    {
        try {
            $generations = (int) $request->input('generations', 8);
            $pdfContent = $this->pdfService->generateAhnentafelReport($personId, $generations);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="ahnentafel_'.$personId.'.pdf"');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate Ahnentafel Report PDF', $e);
        }
    }

    /**
     * Generate Individual Summary PDF
     */
    public function getIndividualSummaryPdf(int $personId)
    {
        try {
            $pdfContent = $this->pdfService->generateIndividualSummary($personId);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="individual_'.$personId.'.pdf"');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate Individual Summary PDF', $e);
        }
    }

    // ========================================================================
    // PHASE 7: Privacy & Collaboration
    // ========================================================================

    /**
     * Get tree privacy settings
     */
    public function getTreePrivacySettings(int $treeId): JsonResponse
    {
        try {
            $settings = $this->genealogyService->getTreePrivacySettings($treeId);
            if (! $settings) {
                return response()->json(['success' => false, 'error' => 'Tree not found'], 404);
            }

            return response()->json(['success' => true, 'data' => $settings]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get privacy settings', $e);
        }
    }

    /**
     * Update tree privacy settings
     */
    public function updateTreePrivacySettings(Request $request, int $treeId): JsonResponse
    {
        try {
            $settings = $request->only([
                'privacy', 'living_privacy', 'living_years_threshold',
                'default_media_privacy', 'allow_public_search', 'owner_id',
            ]);
            $this->genealogyService->updateTreePrivacySettings($treeId, $settings);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update privacy settings', $e);
        }
    }

    /**
     * Auto-detect living persons in a tree
     */
    public function autoDetectLivingPersons(int $treeId): JsonResponse
    {
        try {
            $result = $this->genealogyService->autoDetectLivingPersons($treeId);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to auto-detect living persons', $e);
        }
    }

    /**
     * Update person privacy override
     */
    public function updatePersonPrivacy(Request $request, int $personId): JsonResponse
    {
        try {
            $privacyOverride = $request->input('privacy_override', 'default');
            $this->genealogyService->updatePersonPrivacy($personId, $privacyOverride);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update person privacy', $e);
        }
    }

    /**
     * Update media privacy settings
     */
    public function updateMediaPrivacySettings(Request $request, int $mediaId): JsonResponse
    {
        try {
            $privacy = $request->input('privacy');
            $isSensitive = $request->boolean('is_sensitive', false);
            $this->genealogyService->updateMediaPrivacy($mediaId, $privacy, $isSensitive);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update media privacy', $e);
        }
    }

    /**
     * Get tree collaborators
     */
    public function getCollaborators(int $treeId): JsonResponse
    {
        try {
            $collaborators = $this->genealogyService->getTreeCollaborators($treeId);

            return response()->json(['success' => true, 'data' => $collaborators]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get collaborators', $e);
        }
    }

    /**
     * Add a collaborator to a tree
     */
    public function addCollaborator(Request $request, int $treeId): JsonResponse
    {
        try {
            $userId = $request->input('user_id');
            $role = $request->input('role', 'viewer');
            $permissions = $request->only(['can_export', 'can_delete', 'can_manage_media']);

            $result = $this->genealogyService->addCollaborator($treeId, $userId, $role, auth()->id(), $permissions);

            return response()->json(['success' => $result]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add collaborator', $e);
        }
    }

    /**
     * Update collaborator permissions
     */
    public function updateCollaborator(Request $request, int $collaboratorId): JsonResponse
    {
        try {
            $updates = $request->only(['role', 'can_export', 'can_delete', 'can_manage_media']);
            $this->genealogyService->updateCollaborator($collaboratorId, $updates);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update collaborator', $e);
        }
    }

    /**
     * Remove a collaborator
     */
    public function removeCollaborator(int $collaboratorId): JsonResponse
    {
        try {
            $this->genealogyService->removeCollaborator($collaboratorId);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to remove collaborator', $e);
        }
    }

    /**
     * Create an invitation
     */
    public function createInvitation(Request $request, int $treeId): JsonResponse
    {
        try {
            $email = $request->input('email');
            $role = $request->input('role', 'viewer');

            $invitation = $this->genealogyService->createInvitation($treeId, $email, $role, auth()->id());
            if (! $invitation) {
                return response()->json(['success' => false, 'error' => 'Invalid role'], 400);
            }

            return response()->json(['success' => true, 'data' => $invitation]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create invitation', $e);
        }
    }

    /**
     * Get pending invitations
     */
    public function getPendingInvitations(int $treeId): JsonResponse
    {
        try {
            $invitations = $this->genealogyService->getPendingInvitations($treeId);

            return response()->json(['success' => true, 'data' => $invitations]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get invitations', $e);
        }
    }

    /**
     * Accept an invitation
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        try {
            $token = $request->input('token');
            $result = $this->genealogyService->acceptInvitation($token, auth()->id());
            if (! $result) {
                return response()->json(['success' => false, 'error' => 'Invalid or expired invitation'], 404);
            }

            return response()->json(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to accept invitation', $e);
        }
    }

    /**
     * Cancel an invitation
     */
    public function cancelInvitation(int $invitationId): JsonResponse
    {
        try {
            $this->genealogyService->cancelInvitation($invitationId);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to cancel invitation', $e);
        }
    }

    /**
     * Get user's permissions on a tree
     */
    public function getUserPermissions(int $treeId): JsonResponse
    {
        try {
            $permissions = $this->genealogyService->getUserTreePermissions($treeId, auth()->id());

            return response()->json(['success' => true, 'data' => $permissions]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get permissions', $e);
        }
    }

    /**
     * Get activity log for a tree
     */
    public function getActivityLog(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = $request->input('limit', 50);
            $offset = $request->input('offset', 0);
            $personId = $request->input('person_id');
            $activities = $this->genealogyService->getActivityLog($treeId, $limit, $offset, $personId ? (int) $personId : null);

            return response()->json(['success' => true, 'data' => $activities]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get activity log', $e);
        }
    }

    /**
     * Get living status statistics for a tree
     */
    public function getLivingStatistics(int $treeId): JsonResponse
    {
        try {
            $stats = $this->genealogyService->getLivingStatistics($treeId);

            return response()->json(['success' => true, 'data' => $stats]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get living statistics', $e);
        }
    }

    // ========================================================================
    // Phase 8: AI-Assisted Research
    // ========================================================================

    /**
     * Get research hints for a tree
     */
    public function getResearchHints(Request $request, int $treeId): JsonResponse
    {
        try {
            $status = $request->query('status', 'pending');
            $personId = $request->query('person_id');
            $limit = (int) $request->query('limit', 50);

            $hints = $this->genealogyService->getResearchHints(
                $treeId,
                $personId ? (int) $personId : null,
                $status,
                min($limit, 200)
            );

            return response()->json([
                'success' => true,
                'data' => $hints,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get research hints', $e);
        }
    }

    /**
     * Update a research hint status
     */
    public function updateResearchHintStatus(Request $request, int $hintId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,accepted,rejected,deferred',
            ]);

            $success = $this->genealogyService->updateResearchHintStatus(
                $hintId,
                $validated['status'],
                auth()->id()
            );

            return response()->json([
                'success' => $success,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update hint status', $e);
        }
    }

    /**
     * Generate research hints for a tree
     */
    public function generateResearchHints(int $treeId): JsonResponse
    {
        try {
            $hints = $this->genealogyService->generateTreeHints($treeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'hints_generated' => count($hints),
                    'hints' => $hints,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate research hints', $e);
        }
    }

    /**
     * Generate record hints for a tree by matching against external sources
     */
    public function generateRecordHintsForTree(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = (int) $request->query('limit', 50);
            $minConfidence = (float) $request->query('min_confidence', 0.5);

            $service = app(\App\Services\Genealogy\RecordHintService::class);
            $result = $service->generateTreeRecordHints($treeId, $limit, $minConfidence);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate record hints', $e);
        }
    }

    /**
     * Generate record hints for a specific person
     */
    public function generatePersonRecordHints(int $personId): JsonResponse
    {
        try {
            $service = app(\App\Services\Genealogy\RecordHintService::class);
            $result = $service->generateRecordHints($personId);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate record hints', $e);
        }
    }

    /**
     * Get name variations for a tree
     */
    public function getNameVariations(Request $request, int $treeId): JsonResponse
    {
        try {
            $originalName = $request->query('original_name');
            $nameType = $request->query('name_type');

            $variations = $this->genealogyService->getNameVariations($treeId, $originalName, $nameType);

            return response()->json([
                'success' => true,
                'data' => $variations,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get name variations', $e);
        }
    }

    /**
     * Add a name variation
     */
    public function addNameVariation(Request $request, int $treeId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'original_name' => 'required|string|max:255',
                'name_type' => 'required|in:given,surname',
                'variation' => 'required|string|max:255',
                'language_origin' => 'nullable|string|max:50',
                'notes' => 'nullable|string',
            ]);

            $validated['tree_id'] = $treeId;

            $id = $this->genealogyService->addNameVariation($validated);

            if (! $id) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Variation already exists'],
                ], 409);
            }

            return response()->json([
                'success' => true,
                'data' => ['id' => $id],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add name variation', $e);
        }
    }

    /**
     * Delete a name variation
     */
    public function deleteNameVariation(int $variationId): JsonResponse
    {
        try {
            $success = $this->genealogyService->deleteNameVariation($variationId);

            return response()->json([
                'success' => $success,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete name variation', $e);
        }
    }

    /**
     * Generate AI name suggestions
     */
    public function generateNameSuggestions(Request $request, int $treeId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'name_type' => 'required|in:given,surname',
            ]);

            $suggestions = $this->genealogyService->generateNameVariations(
                $treeId,
                $validated['name'],
                $validated['name_type']
            );

            return response()->json([
                'success' => true,
                'data' => $suggestions,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate name suggestions', $e);
        }
    }

    /**
     * Get research tasks for a tree
     */
    public function getResearchTasks(Request $request, int $treeId): JsonResponse
    {
        try {
            $status = $request->query('status');
            $limit = (int) $request->query('limit', 50);

            $tasks = $this->genealogyService->getResearchTasks($treeId, $status, min($limit, 100));

            return response()->json([
                'success' => true,
                'data' => $tasks,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get research tasks', $e);
        }
    }

    /**
     * Create a research task
     */
    public function createResearchTask(Request $request, int $treeId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'person_id' => 'nullable|integer|exists:genealogy_persons,id',
                'task_type' => 'required|in:find_records,verify_facts,find_relatives,analyze_dna,suggest_sources,transcribe_document',
                'priority' => 'nullable|in:low,medium,high,urgent',
                'parameters' => 'nullable|array',
            ]);

            $validated['tree_id'] = $treeId;
            $validated['created_by'] = auth()->id();

            $taskId = $this->genealogyService->createResearchTask($validated);

            return response()->json([
                'success' => true,
                'data' => ['id' => $taskId],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create research task', $e);
        }
    }

    /**
     * Update a research task
     */
    public function updateResearchTask(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'nullable|in:queued,processing,completed,failed,cancelled',
                'results' => 'nullable|array',
                'error_message' => 'nullable|string',
            ]);

            $success = $this->genealogyService->updateResearchTask($taskId, $validated);

            return response()->json([
                'success' => $success,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update research task', $e);
        }
    }

    /**
     * Get smart matches for a person
     */
    public function getSmartMatches(Request $request, int $personId): JsonResponse
    {
        try {
            $status = $request->query('status');
            $matches = $this->genealogyService->getSmartMatches($personId, $status);

            return response()->json([
                'success' => true,
                'data' => $matches,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get smart matches', $e);
        }
    }

    /**
     * Update a smart match status
     */
    public function updateSmartMatchStatus(Request $request, int $matchId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,accepted,rejected,merged',
            ]);

            $success = $this->genealogyService->updateSmartMatchStatus(
                $matchId,
                $validated['status'],
                auth()->id()
            );

            return response()->json([
                'success' => $success,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update match status', $e);
        }
    }

    /**
     * Get research statistics for a tree
     */
    public function getResearchStatistics(int $treeId): JsonResponse
    {
        try {
            $stats = $this->genealogyService->getResearchStatistics($treeId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get research statistics', $e);
        }
    }

    /**
     * Analyze a person for research hints
     */
    public function analyzePersonForHints(int $personId): JsonResponse
    {
        try {
            $hints = $this->genealogyService->analyzePersonForHints($personId);

            // Create the hints
            $createdHints = [];
            foreach ($hints as $hint) {
                $hintId = $this->genealogyService->createResearchHint($hint);
                if ($hintId) {
                    $hint['id'] = $hintId;
                    $createdHints[] = $hint;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'hints_generated' => count($createdHints),
                    'hints' => $createdHints,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to analyze person', $e);
        }
    }

    // ========================================================================
    // PHASE 9: EXTERNAL INTEGRATIONS
    // ========================================================================

    /**
     * Get all external service connections for a tree
     */
    public function getExternalConnections(int $treeId): JsonResponse
    {
        try {
            $connections = $this->genealogyService->getExternalConnections($treeId);

            return response()->json([
                'success' => true,
                'data' => $connections,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get external connections', $e);
        }
    }

    /**
     * Get a specific external connection
     */
    public function getExternalConnection(int $connectionId): JsonResponse
    {
        try {
            $connection = $this->genealogyService->getExternalConnection($connectionId);
            if (! $connection) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Connection not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $connection,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get external connection', $e);
        }
    }

    /**
     * Save (create or update) an external connection
     */
    public function saveExternalConnection(Request $request, int $treeId): JsonResponse
    {
        try {
            $data = $request->validate([
                'service_type' => 'required|string|in:findmypast,myheritage,geneanet,wikitree,findagrave',
                'service_user_id' => 'nullable|string|max:255',
                'access_token' => 'nullable|string',
                'refresh_token' => 'nullable|string',
                'token_expires_at' => 'nullable|date',
                'settings' => 'nullable|array',
            ]);

            $data['tree_id'] = $treeId;
            $data['user_id'] = auth()->id() ?? 1;

            $connectionId = $this->genealogyService->saveExternalConnection($data);
            if (! $connectionId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to save connection'],
                ], 500);
            }

            $connection = $this->genealogyService->getExternalConnection($connectionId);

            return response()->json([
                'success' => true,
                'data' => $connection,
                'message' => 'Connection saved successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to save external connection', $e);
        }
    }

    /**
     * Delete an external connection
     */
    public function deleteExternalConnection(int $connectionId): JsonResponse
    {
        try {
            $success = $this->genealogyService->deleteExternalConnection($connectionId);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Connection deleted successfully' : 'Failed to delete connection',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete external connection', $e);
        }
    }

    /**
     * Update connection status
     */
    public function updateConnectionStatus(Request $request, int $connectionId): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'required|string|in:active,expired,revoked,error',
                'error_message' => 'nullable|string',
            ]);

            $success = $this->genealogyService->updateConnectionStatus(
                $connectionId,
                $data['status'],
                $data['error_message'] ?? null
            );

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Status updated successfully' : 'Failed to update status',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update connection status', $e);
        }
    }

    /**
     * Get external records for a tree or person
     */
    public function getExternalRecords(Request $request, int $treeId): JsonResponse
    {
        try {
            $personId = $request->query('person_id');
            $status = $request->query('status');
            $limit = (int) $request->query('limit', 50);

            $records = $this->genealogyService->getExternalRecords(
                $treeId,
                $personId ? (int) $personId : null,
                $status,
                $limit
            );

            return response()->json([
                'success' => true,
                'data' => $records,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get external records', $e);
        }
    }

    /**
     * Save an external record
     */
    public function saveExternalRecord(Request $request, int $treeId): JsonResponse
    {
        try {
            $data = $request->validate([
                'person_id' => 'nullable|integer',
                'service_type' => 'required|string|in:familysearch,ancestry,findmypast,myheritage,geneanet,wikitree,findagrave',
                'external_id' => 'required|string|max:255',
                'record_type' => 'nullable|string|max:100',
                'title' => 'nullable|string|max:500',
                'record_data' => 'required|array',
                'match_confidence' => 'nullable|numeric|min:0|max:1',
            ]);

            $data['tree_id'] = $treeId;

            $recordId = $this->genealogyService->saveExternalRecord($data);
            if (! $recordId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to save record'],
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => ['id' => $recordId],
                'message' => 'Record saved successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to save external record', $e);
        }
    }

    /**
     * Update external record status
     */
    public function updateExternalRecordStatus(Request $request, int $recordId): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'required|string|in:pending,matched,rejected,imported',
            ]);

            $userId = auth()->id() ?? 1;
            $success = $this->genealogyService->updateExternalRecordStatus($recordId, $data['status'], $userId);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Record status updated successfully' : 'Failed to update status',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update record status', $e);
        }
    }

    /**
     * Get external links for a person
     */
    public function getPersonExternalLinks(int $personId): JsonResponse
    {
        try {
            $links = $this->genealogyService->getPersonExternalLinks($personId);

            return response()->json([
                'success' => true,
                'data' => $links,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get external links', $e);
        }
    }

    /**
     * Link a person to an external service
     */
    public function linkPersonToExternalService(Request $request, int $personId): JsonResponse
    {
        try {
            $data = $request->validate([
                'service_type' => 'required|string|in:familysearch,ancestry,findmypast,myheritage,geneanet,wikitree,findagrave',
                'external_person_id' => 'required|string|max:255',
                'link_type' => 'nullable|string|in:confirmed,suggested,rejected',
                'sync_enabled' => 'nullable|boolean',
            ]);

            $data['person_id'] = $personId;

            $linkId = $this->genealogyService->linkPersonToExternalService($data);
            if (! $linkId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to create link'],
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => ['id' => $linkId],
                'message' => 'Person linked successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to link person to external service', $e);
        }
    }

    /**
     * Unlink a person from an external service
     */
    public function unlinkPersonFromExternalService(int $personId, string $serviceType): JsonResponse
    {
        try {
            $success = $this->genealogyService->unlinkPersonFromExternalService($personId, $serviceType);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Person unlinked successfully' : 'Failed to unlink person',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to unlink person from external service', $e);
        }
    }

    /**
     * Start an external sync
     */
    public function startExternalSync(Request $request, int $connectionId): JsonResponse
    {
        try {
            $data = $request->validate([
                'sync_type' => 'required|string|in:full,incremental,manual',
                'direction' => 'nullable|string|in:import,export,bidirectional',
            ]);

            $syncId = $this->genealogyService->createExternalSync(
                $connectionId,
                $data['sync_type'],
                $data['direction'] ?? 'import'
            );

            if (! $syncId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to start sync'],
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => ['sync_id' => $syncId],
                'message' => 'Sync started successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to start external sync', $e);
        }
    }

    /**
     * Update sync status
     */
    public function updateExternalSync(Request $request, int $syncId): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'nullable|string|in:pending,running,completed,failed,cancelled',
                'records_found' => 'nullable|integer',
                'records_imported' => 'nullable|integer',
                'records_updated' => 'nullable|integer',
                'records_skipped' => 'nullable|integer',
                'records_failed' => 'nullable|integer',
                'error_log' => 'nullable|array',
            ]);

            $success = $this->genealogyService->updateExternalSync($syncId, $data);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Sync updated successfully' : 'Failed to update sync',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update external sync', $e);
        }
    }

    /**
     * Get sync history for a connection
     */
    public function getSyncHistory(Request $request, int $connectionId): JsonResponse
    {
        try {
            $limit = (int) $request->query('limit', 20);
            $history = $this->genealogyService->getSyncHistory($connectionId, $limit);

            return response()->json([
                'success' => true,
                'data' => $history,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get sync history', $e);
        }
    }

    /**
     * Get external integration statistics
     */
    public function getExternalIntegrationStats(int $treeId): JsonResponse
    {
        try {
            $stats = $this->genealogyService->getExternalIntegrationStats($treeId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get integration statistics', $e);
        }
    }

    /**
     * Get list of supported external services
     */
    public function getSupportedExternalServices(): JsonResponse
    {
        try {
            $services = $this->genealogyService->getSupportedExternalServices();

            return response()->json([
                'success' => true,
                'data' => $services,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get supported services', $e);
        }
    }

    // ========================================================================
    // PHASE 9.5: PROVIDER INTEGRATION
    // ========================================================================

    /**
     * Get status of all genealogy providers
     */
    public function getProvidersStatus(): JsonResponse
    {
        try {
            $manager = new GenealogyProviderManager;
            $status = $manager->getProvidersStatus();

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get provider status', $e);
        }
    }

    /**
     * Get active (configured) providers
     */
    public function getActiveProviders(): JsonResponse
    {
        try {
            $manager = new GenealogyProviderManager;
            $providers = $manager->getActiveProviders();
            $data = [];
            foreach ($providers as $id => $provider) {
                $data[$id] = [
                    'id' => $id,
                    'name' => $provider->getProviderName(),
                    'capabilities' => $provider->getCapabilities(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get active providers', $e);
        }
    }

    /**
     * Get info for a specific provider
     */
    public function getProviderInfo(string $providerId): JsonResponse
    {
        try {
            $manager = new GenealogyProviderManager;
            $provider = $manager->getProvider($providerId);
            if (! $provider) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Unknown provider: {$providerId}"],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $providerId,
                    'name' => $provider->getProviderName(),
                    'auth_type' => $provider->getAuthType(),
                    'configured' => $provider->isConfigured(),
                    'authenticated' => $provider->isAuthenticated(),
                    'capabilities' => $provider->getCapabilities(),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get provider info', $e);
        }
    }

    /**
     * Get OAuth2 authorization URL for a provider
     */
    public function getProviderAuthUrl(Request $request, string $providerId): JsonResponse
    {
        try {
            $state = $request->query('state');
            $manager = new GenealogyProviderManager;
            $url = $manager->getAuthorizationUrl($providerId, $state);
            if (! $url) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Provider does not support OAuth2 or is not configured'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => ['authorization_url' => $url],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get authorization URL', $e);
        }
    }

    /**
     * Handle OAuth2 callback from provider
     */
    public function handleProviderCallback(Request $request, string $providerId): JsonResponse
    {
        try {
            $data = $request->validate([
                'code' => 'required|string',
                'state' => 'nullable|string',
            ]);
            $manager = new GenealogyProviderManager;
            $success = $manager->handleOAuthCallback($providerId, $data['code'], $data['state'] ?? null);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Provider connected successfully' : 'Failed to connect provider',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to handle OAuth callback', $e);
        }
    }

    /**
     * Disconnect a provider from a tree
     */
    public function disconnectProvider(int $treeId, string $providerId): JsonResponse
    {
        try {
            $manager = new GenealogyProviderManager;
            $manager->setTreeContext($treeId);
            $success = $manager->disconnectProvider($providerId);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Provider disconnected' : 'Provider not found',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to disconnect provider', $e);
        }
    }

    /**
     * Get stored OAuth tokens for a tree
     */
    public function getProviderTokens(int $treeId): JsonResponse
    {
        try {
            $manager = new GenealogyProviderManager;
            $manager->setTreeContext($treeId);
            $tokens = $manager->getStoredTokens();

            return response()->json([
                'success' => true,
                'data' => $tokens,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get provider tokens', $e);
        }
    }

    /**
     * Search across all active providers
     */
    public function searchAllProviders(Request $request): JsonResponse
    {
        try {
            $criteria = $request->validate([
                'given_name' => 'nullable|string|max:100',
                'surname' => 'nullable|string|max:100',
                'birth_date' => 'nullable|string|max:20',
                'birth_year' => 'nullable|integer',
                'birth_place' => 'nullable|string|max:255',
                'death_date' => 'nullable|string|max:20',
                'death_year' => 'nullable|integer',
                'death_place' => 'nullable|string|max:255',
                'tree_id' => 'nullable|integer',
                'providers' => 'nullable|array',
            ]);
            $manager = new GenealogyProviderManager;
            if (! empty($criteria['tree_id'])) {
                $manager->setTreeContext($criteria['tree_id']);
            }
            $results = $manager->searchAllProviders($criteria, [
                'providers' => $criteria['providers'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search providers', $e);
        }
    }

    /**
     * Search a specific provider
     */
    public function searchProvider(Request $request, string $providerId): JsonResponse
    {
        try {
            $criteria = $request->validate([
                'given_name' => 'nullable|string|max:100',
                'surname' => 'nullable|string|max:100',
                'birth_date' => 'nullable|string|max:20',
                'birth_year' => 'nullable|integer',
                'birth_place' => 'nullable|string|max:255',
                'death_date' => 'nullable|string|max:20',
                'death_year' => 'nullable|integer',
                'death_place' => 'nullable|string|max:255',
                'tree_id' => 'nullable|integer',
            ]);
            $manager = new GenealogyProviderManager;
            if (! empty($criteria['tree_id'])) {
                $manager->setTreeContext($criteria['tree_id']);
            }
            $provider = $manager->getProvider($providerId);
            if (! $provider) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Unknown provider: {$providerId}"],
                ], 404);
            }
            $results = $provider->searchPersons($criteria);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search provider', $e);
        }
    }

    /**
     * Get provider matches for a person
     */
    public function getPersonProviderMatches(Request $request, int $personId): JsonResponse
    {
        try {
            $person = $this->genealogyService->getPerson($personId);
            if (! $person) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found'],
                ], 404);
            }
            $manager = new GenealogyProviderManager;
            $manager->setTreeContext($person->tree_id);
            $results = $manager->searchForPerson([
                'given_name' => $person->given_name ?? null,
                'surname' => $person->surname ?? null,
                'birth_date' => $person->birth_date ?? null,
                'death_date' => $person->death_date ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get person provider matches', $e);
        }
    }

    // ========================================================================
    // PHASE 9.5: RESEARCH SOURCES & CACHE
    // ========================================================================

    /**
     * Get all research sources
     */
    public function getResearchSources(): JsonResponse
    {
        try {
            $service = new GenealogyResearchService;
            $sources = $service->getSources();

            return response()->json([
                'success' => true,
                'data' => $sources,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get research sources', $e);
        }
    }

    /**
     * Get a specific research source
     */
    public function getResearchSource(string $sourceCode): JsonResponse
    {
        try {
            $service = new GenealogyResearchService;
            $source = $service->getSourceByCode($sourceCode);
            if (! $source) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Unknown source: {$sourceCode}"],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $source,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get research source', $e);
        }
    }

    /**
     * Execute a research search
     */
    public function executeResearchSearch(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'source_code' => 'required|string',
                'query' => 'required|string',
                'options' => 'nullable|array',
            ]);
            $service = new GenealogyResearchService;
            $results = $service->search($data['source_code'], $data['query'], $data['options'] ?? []);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to execute research search', $e);
        }
    }

    /**
     * Get research cache statistics
     */
    public function getResearchCacheStats(): JsonResponse
    {
        try {
            $service = new GenealogyResearchService;
            $stats = $service->getCacheStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get cache stats', $e);
        }
    }

    /**
     * Cleanup expired cache entries
     */
    public function cleanupExpiredCache(): JsonResponse
    {
        try {
            $service = new GenealogyResearchService;
            $deleted = $service->cleanupExpiredCache();

            return response()->json([
                'success' => true,
                'data' => ['deleted_count' => $deleted],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to cleanup cache', $e);
        }
    }

    // ========================================================================
    // PHASE 9.6: NEWSPAPER CLIPPINGS
    // ========================================================================

    /**
     * Search Library of Congress newspapers
     */
    public function searchNewspapers(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'query' => 'required|string|max:500',
                'date_start' => 'nullable|string|max:10',
                'date_end' => 'nullable|string|max:10',
                'state' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);
            $service = new NewspaperSearchService;
            $results = $service->search($data['query'], $data);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search newspapers', $e);
        }
    }

    /**
     * Search for obituaries
     */
    public function searchObituaries(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'date_start' => 'nullable|string|max:10',
                'date_end' => 'nullable|string|max:10',
                'state' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
            $service = new NewspaperSearchService;
            $results = $service->searchObituaries($data['name'], $data);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search obituaries', $e);
        }
    }

    /**
     * Search for birth announcements
     */
    public function searchBirthAnnouncements(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'date_start' => 'nullable|string|max:10',
                'date_end' => 'nullable|string|max:10',
                'state' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
            $service = new NewspaperSearchService;
            $results = $service->searchBirthAnnouncements($data['name'], $data);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search birth announcements', $e);
        }
    }

    /**
     * Search for marriage announcements
     */
    public function searchMarriages(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'date_start' => 'nullable|string|max:10',
                'date_end' => 'nullable|string|max:10',
                'state' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
            $service = new NewspaperSearchService;
            $results = $service->searchMarriages($data['name'], $data);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search marriages', $e);
        }
    }

    /**
     * Get OCR text for a newspaper page
     */
    public function getNewspaperPageOCR(Request $request, string $lccn, string $date): JsonResponse
    {
        try {
            $edition = (int) $request->query('edition', 1);
            $page = (int) $request->query('page', 1);
            $service = new NewspaperSearchService;
            $result = $service->getPageOCR($lccn, $date, $edition, $page);

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get newspaper OCR', $e);
        }
    }

    /**
     * Get newspaper metadata by LCCN
     */
    public function getNewspaperInfo(string $lccn): JsonResponse
    {
        try {
            $service = new NewspaperSearchService;
            $result = $service->getNewspaperInfo($lccn);

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get newspaper info', $e);
        }
    }

    /**
     * Get clippings for a tree
     */
    public function getTreeClippings(Request $request, int $treeId): JsonResponse
    {
        try {
            $options = [
                'limit' => (int) $request->query('limit', 50),
                'offset' => (int) $request->query('offset', 0),
                'type' => $request->query('type'),
            ];
            $service = new NewspaperSearchService;
            $clippings = $service->getClippingsForTree($treeId, $options);

            return response()->json([
                'success' => true,
                'data' => $clippings,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get tree clippings', $e);
        }
    }

    /**
     * Save a newspaper clipping
     */
    public function saveClipping(Request $request, int $treeId): JsonResponse
    {
        try {
            $data = $request->validate([
                'newspaper_name' => 'nullable|string|max:255',
                'publication_date' => 'nullable|date',
                'headline' => 'nullable|string|max:500',
                'clipping_type' => 'nullable|string|in:obituary,birth,marriage,death,military,social,legal,other',
                'url' => 'nullable|url',
                'id' => 'nullable|string|max:255',
            ]);
            $service = new NewspaperSearchService;
            $clippingId = $service->saveClipping($treeId, $data);
            if (! $clippingId) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to save clipping'],
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => ['clipping_id' => $clippingId],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to save clipping', $e);
        }
    }

    /**
     * Get a specific clipping
     */
    public function getClipping(int $id): JsonResponse
    {
        try {
            $clipping = \Illuminate\Support\Facades\DB::selectOne('
                SELECT * FROM genealogy_newspaper_clippings WHERE id = ?
            ', [$id]);
            if (! $clipping) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Clipping not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $clipping,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get clipping', $e);
        }
    }

    /**
     * Delete a clipping
     */
    public function deleteClipping(int $id): JsonResponse
    {
        try {
            $deleted = \Illuminate\Support\Facades\DB::delete('
                DELETE FROM genealogy_newspaper_clippings WHERE id = ?
            ', [$id]);

            return response()->json([
                'success' => $deleted > 0,
                'message' => $deleted > 0 ? 'Clipping deleted' : 'Clipping not found',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete clipping', $e);
        }
    }

    /**
     * Link a clipping to a person
     */
    public function linkClippingToPerson(Request $request, int $clippingId, int $personId): JsonResponse
    {
        try {
            $data = $request->validate([
                'relevance_type' => 'nullable|string|in:subject,mentioned,relative,witness,author,other',
                'confidence' => 'nullable|numeric|min:0|max:1',
            ]);
            $service = new NewspaperSearchService;
            $success = $service->linkClippingToPerson(
                $clippingId,
                $personId,
                $data['relevance_type'] ?? 'mentioned',
                $data['confidence'] ?? 0.5
            );

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Clipping linked to person' : 'Failed to link clipping',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to link clipping to person', $e);
        }
    }

    /**
     * Unlink a clipping from a person
     */
    public function unlinkClippingFromPerson(int $clippingId, int $personId): JsonResponse
    {
        try {
            $deleted = \Illuminate\Support\Facades\DB::delete('
                DELETE FROM genealogy_person_clippings
                WHERE clipping_id = ? AND person_id = ?
            ', [$clippingId, $personId]);

            return response()->json([
                'success' => $deleted > 0,
                'message' => $deleted > 0 ? 'Clipping unlinked' : 'Link not found',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to unlink clipping from person', $e);
        }
    }

    /**
     * Get clippings for a person
     */
    public function getPersonClippings(int $personId): JsonResponse
    {
        try {
            $service = new NewspaperSearchService;
            $clippings = $service->getClippingsForPerson($personId);

            return response()->json([
                'success' => true,
                'data' => $clippings,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get person clippings', $e);
        }
    }

    /**
     * Search newspapers for a specific person and save clippings
     */
    public function searchNewspapersForPerson(Request $request, int $personId): JsonResponse
    {
        try {
            $person = $this->genealogyService->getPerson($personId);
            if (! $person) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found'],
                ], 404);
            }
            $options = $request->validate([
                'state' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
            $service = new NewspaperSearchService;
            $results = $service->searchAndSaveForPerson($personId, $person->tree_id, $options);

            return response()->json([
                'success' => $results['success'],
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to search newspapers for person', $e);
        }
    }

    // ========================================================================
    // FACE REGION OPERATIONS (E23)
    // ========================================================================

    /**
     * Rescan face regions from Nextcloud files for a tree
     */
    public function rescanFaceRegions(Request $request, int $treeId): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);
            $results = $this->mediaService->rescanFaceRegions($treeId, $limit);

            if (! $results['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $results['error'] ?? 'Face scan failed'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "Processed {$results['processed']} files, found {$results['faces_found']} faces, linked {$results['persons_linked']} persons",
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to rescan face regions', $e);
        }
    }

    /**
     * Cleanup unlinked media that has no face data and no person matches.
     * Uses AI to try to match media to persons before deleting.
     * Deletes from both database and Nextcloud.
     */
    public function cleanupUnlinkedMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $dryRun = $request->input('dry_run', true);
            $limit = $request->input('limit', 100);

            $results = $this->mediaService->cleanupUnlinkedMedia($treeId, $dryRun, $limit);

            if (! $results['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $results['error'] ?? 'Cleanup failed'],
                ], 400);
            }

            $mode = $dryRun ? 'DRY RUN' : 'EXECUTED';

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "[{$mode}] Processed {$results['processed']} media: {$results['linked']} linked to persons, {$results['deleted']} deleted, {$results['skipped']} skipped (family keywords), {$results['errors']} errors",
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to cleanup unlinked media', $e);
        }
    }

    /**
     * Add a face region to a media file
     */
    public function addFaceRegion(Request $request, int $mediaId): JsonResponse
    {
        try {
            $request->validate([
                'person_id' => 'required|integer',
                'x' => 'nullable|numeric|between:0,1',
                'y' => 'nullable|numeric|between:0,1',
                'w' => 'nullable|numeric|between:0,1',
                'h' => 'nullable|numeric|between:0,1',
            ]);

            $personId = $request->input('person_id');
            $coordinates = [
                'x' => $request->input('x'),
                'y' => $request->input('y'),
                'w' => $request->input('w'),
                'h' => $request->input('h'),
            ];

            // Check if media exists
            $media = $this->genealogyService->getMedia($mediaId);
            if (! $media) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Media not found'],
                ], 404);
            }

            // Check if person exists
            $person = $this->genealogyService->getPerson($personId);
            if (! $person) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found'],
                ], 404);
            }

            // Check if link already exists
            $existingLink = DB::selectOne(
                'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                [$personId, $mediaId]
            );

            if ($existingLink) {
                // Update existing link with face coordinates
                DB::update(
                    'UPDATE genealogy_person_media SET face_region_x = ?, face_region_y = ?, face_region_w = ?, face_region_h = ? WHERE id = ?',
                    [$coordinates['x'], $coordinates['y'], $coordinates['w'], $coordinates['h'], $existingLink->id]
                );
            } else {
                // Create new link
                DB::insert(
                    'INSERT INTO genealogy_person_media (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h, face_confirmed, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                    [$personId, $mediaId, $coordinates['x'], $coordinates['y'], $coordinates['w'], $coordinates['h']]
                );
            }

            // Update face count on media
            $faceCount = DB::selectOne(
                'SELECT COUNT(*) as count FROM genealogy_person_media WHERE media_id = ?',
                [$mediaId]
            );
            DB::update(
                'UPDATE genealogy_media SET has_faces = 1, face_count = ? WHERE id = ?',
                [$faceCount->count, $mediaId]
            );

            return response()->json([
                'success' => true,
                'data' => ['media_id' => $mediaId, 'person_id' => $personId],
                'message' => 'Face region added successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add face region', $e);
        }
    }

    /**
     * Update a face region for a person on a media file
     */
    public function updateFaceRegion(Request $request, int $mediaId, int $personId): JsonResponse
    {
        try {
            $request->validate([
                'x' => 'nullable|numeric|between:0,1',
                'y' => 'nullable|numeric|between:0,1',
                'w' => 'nullable|numeric|between:0,1',
                'h' => 'nullable|numeric|between:0,1',
                'confirmed' => 'nullable|boolean',
            ]);

            $link = DB::selectOne(
                'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                [$personId, $mediaId]
            );

            if (! $link) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Face tag not found'],
                ], 404);
            }

            $updates = [];
            $params = [];

            if ($request->has('x')) {
                $updates[] = 'face_x = ?';
                $params[] = $request->input('x');
            }
            if ($request->has('y')) {
                $updates[] = 'face_y = ?';
                $params[] = $request->input('y');
            }
            if ($request->has('w')) {
                $updates[] = 'face_w = ?';
                $params[] = $request->input('w');
            }
            if ($request->has('h')) {
                $updates[] = 'face_h = ?';
                $params[] = $request->input('h');
            }
            if ($request->has('confirmed')) {
                $updates[] = 'face_confirmed = ?';
                $params[] = $request->input('confirmed') ? 1 : 0;
            }

            if (! empty($updates)) {
                $updates[] = 'updated_at = NOW()';
                $params[] = $link->id;
                DB::update(
                    'UPDATE genealogy_person_media SET '.implode(', ', $updates).' WHERE id = ?',
                    $params
                );
            }

            return response()->json([
                'success' => true,
                'data' => ['media_id' => $mediaId, 'person_id' => $personId],
                'message' => 'Face region updated successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update face region', $e);
        }
    }

    /**
     * Remove a face region from a media file
     */
    public function removeFaceRegion(int $mediaId, int $personId): JsonResponse
    {
        try {
            $deleted = DB::delete(
                'DELETE FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                [$personId, $mediaId]
            );

            if ($deleted === 0) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Face tag not found'],
                ], 404);
            }

            // Update face count on media
            $faceCount = DB::selectOne(
                'SELECT COUNT(*) as count FROM genealogy_person_media WHERE media_id = ?',
                [$mediaId]
            );
            DB::update(
                'UPDATE genealogy_media SET has_faces = ?, face_count = ? WHERE id = ?',
                [$faceCount->count > 0 ? 1 : 0, $faceCount->count, $mediaId]
            );

            return response()->json([
                'success' => true,
                'message' => 'Face region removed successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to remove face region', $e);
        }
    }

    /**
     * Write face regions back to the Nextcloud file using XMP-mwg-rs format
     */
    public function writeFaceRegionsToFile(int $mediaId): JsonResponse
    {
        try {
            if (! config('metadata_writeback.enabled', false) || ! config('metadata_writeback.in_place_enabled', false)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Metadata writeback disabled'],
                ], 403);
            }

            // Get media with face regions
            $media = DB::selectOne(
                'SELECT m.*, m.nextcloud_path, m.local_filename
                 FROM genealogy_media m WHERE m.id = ?',
                [$mediaId]
            );

            if (! $media) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Media not found'],
                ], 404);
            }

            if (! $media->nextcloud_path) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Media has no Nextcloud path'],
                ], 400);
            }

            // Get all face regions for this media with person names
            $faceRegions = DB::select(
                'SELECT pm.face_region_x, pm.face_region_y, pm.face_region_w, pm.face_region_h,
                        p.given_name, p.surname, p.suffix
                 FROM genealogy_person_media pm
                 JOIN genealogy_persons p ON p.id = pm.person_id
                 WHERE pm.media_id = ?',
                [$mediaId]
            );

            if (empty($faceRegions)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'No face regions to write'],
                ], 400);
            }

            // Download file from Nextcloud to temp location
            $tempPath = sys_get_temp_dir().'/face_write_'.$mediaId.'_'.basename($media->local_filename ?? 'file.jpg');

            $nextcloudApi = app(\App\Services\NextcloudApiService::class);
            $downloaded = $nextcloudApi->downloadFile($media->nextcloud_path, $tempPath);

            if (! $downloaded || ! file_exists($tempPath)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to download file from Nextcloud'],
                ], 500);
            }

            // Get image dimensions
            $imageInfo = @getimagesize($tempPath);
            if (! $imageInfo) {
                @unlink($tempPath);

                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Could not read image dimensions'],
                ], 400);
            }

            $imageWidth = $imageInfo[0];
            $imageHeight = $imageInfo[1];

            // Build ExifTool command to write face regions
            // First clear existing regions, then add new ones
            $exiftoolPath = '/usr/bin/exiftool';

            // Clear existing regions first
            $clearCmd = [$exiftoolPath, '-overwrite_original', '-XMP-mwg-rs:all=', $tempPath];
            \Illuminate\Support\Facades\Process::timeout(30)->run($clearCmd);

            // Add each face region using the same format as the Python program
            foreach ($faceRegions as $face) {
                $personName = trim($face->given_name.' '.$face->surname);
                if ($face->suffix) {
                    $personName .= ' '.$face->suffix;
                }

                // Convert normalized coordinates to the format expected by XMP-mwg-rs
                $x = $face->face_x ?? 0.5;
                $y = $face->face_y ?? 0.5;
                $w = $face->face_w ?? 0.1;
                $h = $face->face_h ?? 0.1;

                $regionJson = json_encode([
                    'Area' => [
                        'X' => $x,
                        'Y' => $y,
                        'W' => $w,
                        'H' => $h,
                        'Unit' => 'normalized',
                    ],
                    'Name' => $personName,
                    'Type' => 'Face',
                ]);

                $addCmd = [
                    $exiftoolPath,
                    '-overwrite_original',
                    "-XMP-mwg-rs:RegionList+={$regionJson}",
                    $tempPath,
                ];
                \Illuminate\Support\Facades\Process::timeout(30)->run($addCmd);
            }

            // Also set the applied region dimensions
            $dimsCmd = [
                $exiftoolPath,
                '-overwrite_original',
                "-XMP-mwg-rs:RegionAppliedToDimensionsW={$imageWidth}",
                "-XMP-mwg-rs:RegionAppliedToDimensionsH={$imageHeight}",
                '-XMP-mwg-rs:RegionAppliedToDimensionsUnit=pixel',
                $tempPath,
            ];
            \Illuminate\Support\Facades\Process::timeout(30)->run($dimsCmd);

            // Upload modified file back to Nextcloud
            $uploaded = $nextcloudApi->uploadFile($tempPath, $media->nextcloud_path);

            // Clean up temp file
            @unlink($tempPath);

            if (! $uploaded) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to upload modified file to Nextcloud'],
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'media_id' => $mediaId,
                    'faces_written' => count($faceRegions),
                    'nextcloud_path' => $media->nextcloud_path,
                ],
                'message' => 'Face regions written to file successfully',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to write face regions to file', $e);
        }
    }

    // ========================================================================
    // BATCH OPERATIONS (Priority 3.5)
    // ========================================================================

    /**
     * Batch update multiple persons
     */
    public function batchUpdatePersons(Request $request, int $treeId): JsonResponse
    {
        try {
            $personIds = $request->input('person_ids', []);
            $updates = $request->input('updates', []);

            if (empty($personIds) || empty($updates)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'person_ids and updates are required'],
                ], 400);
            }

            $batchService = app(\App\Services\Genealogy\BatchOperationsService::class);
            $result = $batchService->batchUpdatePersons($treeId, $personIds, $updates);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to batch update persons', $e);
        }
    }

    /**
     * Batch delete multiple persons
     */
    public function batchDeletePersons(Request $request, int $treeId): JsonResponse
    {
        try {
            $personIds = $request->input('person_ids', []);
            $cascade = $request->boolean('cascade', true);

            if (empty($personIds)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'person_ids is required'],
                ], 400);
            }

            $batchService = app(\App\Services\Genealogy\BatchOperationsService::class);
            $result = $batchService->batchDeletePersons($treeId, $personIds, $cascade);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to batch delete persons', $e);
        }
    }

    /**
     * Batch tag multiple media items
     */
    public function batchTagMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $mediaIds = $request->input('media_ids', []);
            $tags = $request->input('tags', []);

            if (empty($mediaIds) || empty($tags)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'media_ids and tags are required'],
                ], 400);
            }

            $batchService = app(\App\Services\Genealogy\BatchOperationsService::class);
            $result = $batchService->batchTagMedia($treeId, $mediaIds, $tags);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to batch tag media', $e);
        }
    }

    /**
     * Batch link multiple media items to a person
     */
    public function batchLinkMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $personId = $request->input('person_id');
            $mediaIds = $request->input('media_ids', []);

            if (! $personId || empty($mediaIds)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'person_id and media_ids are required'],
                ], 400);
            }

            $batchService = app(\App\Services\Genealogy\BatchOperationsService::class);
            $result = $batchService->batchLinkMediaToPerson($treeId, (int) $personId, $mediaIds);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to batch link media', $e);
        }
    }

    /**
     * Batch delete multiple media items
     */
    public function batchDeleteMedia(Request $request, int $treeId): JsonResponse
    {
        try {
            $mediaIds = $request->input('media_ids', []);
            $deleteFiles = $request->boolean('delete_files', false);

            if (empty($mediaIds)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'media_ids is required'],
                ], 400);
            }

            $batchService = app(\App\Services\Genealogy\BatchOperationsService::class);
            $result = $batchService->batchDeleteMedia($treeId, $mediaIds, $deleteFiles);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to batch delete media', $e);
        }
    }

    // ========================================================================
    // AI RESEARCH (Priority A.1)
    // ========================================================================

    /**
     * Get AI-powered research suggestions for a person
     *
     * POST /api/genealogy/persons/{id}/ai-research
     *
     * Request body:
     * - focus: 'ancestry'|'descendants'|'siblings'|'general' (default: 'general')
     * - include_sources: bool (default: true)
     * - brick_wall: bool (default: false)
     */
    public function aiResearchPerson(Request $request, int $personId): JsonResponse
    {
        try {
            $aiService = app(GenealogyAIResearchService::class);

            $options = [
                'focus' => $request->input('focus', 'general'),
                'include_sources' => $request->boolean('include_sources', true),
                'brick_wall' => $request->boolean('brick_wall', false),
            ];

            $result = $aiService->researchPerson($personId, $options);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate AI research suggestions', $e);
        }
    }

    /**
     * Get AI-powered brick wall breaking strategies
     *
     * POST /api/genealogy/persons/{id}/brick-wall-suggestions
     */
    public function aiBrickWallSuggestions(int $personId): JsonResponse
    {
        try {
            $aiService = app(GenealogyAIResearchService::class);
            $result = $aiService->suggestResearchForBrickWall($personId);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate brick wall suggestions', $e);
        }
    }

    /**
     * Extract structured data from research results for applying to person fields
     *
     * POST /api/genealogy/persons/{id}/extract-research-data
     *
     * Request body:
     * - research_text: string (required) - The research text to parse
     */
    public function extractResearchData(Request $request, int $personId): JsonResponse
    {
        try {
            $researchText = $request->input('research_text');

            if (empty($researchText)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'research_text is required'],
                ], 400);
            }

            $aiService = app(GenealogyAIResearchService::class);
            $result = $aiService->extractStructuredData($personId, $researchText);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to extract research data', $e);
        }
    }

    /**
     * Apply extracted research data to person fields
     *
     * POST /api/genealogy/persons/{id}/apply-research-data
     *
     * Request body:
     * - items: array (required) - Array of items to apply, each with field and value
     */
    public function applyResearchData(Request $request, int $personId): JsonResponse
    {
        try {
            $items = $request->input('items', []);

            if (empty($items)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'items array is required'],
                ], 400);
            }

            $aiService = app(GenealogyAIResearchService::class);
            $result = $aiService->applyExtractedData($personId, $items);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            // Get updated person data
            $person = $this->genealogyService->getPerson($personId);

            return response()->json([
                'success' => true,
                'data' => $result,
                'person' => $person,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to apply research data', $e);
        }
    }

    /**
     * Evaluate a genealogical source using AI
     *
     * POST /api/genealogy/sources/evaluate
     *
     * Request body:
     * - source_description: string (required)
     * - person_id: int (optional, for context)
     */
    public function aiEvaluateSource(Request $request): JsonResponse
    {
        try {
            $sourceDescription = $request->input('source_description');

            if (empty($sourceDescription)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'source_description is required'],
                ], 400);
            }

            $aiService = app(GenealogyAIResearchService::class);

            $options = [];
            if ($request->has('person_id')) {
                $options['person_id'] = (int) $request->input('person_id');
            }

            $result = $aiService->evaluateSource($sourceDescription, $options);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to evaluate source', $e);
        }
    }

    /**
     * Analyze relationship between two persons using AI
     *
     * POST /api/genealogy/persons/analyze-relationship
     *
     * Request body:
     * - person1_id: int (required)
     * - person2_id: int (required)
     */
    public function aiAnalyzeRelationship(Request $request): JsonResponse
    {
        try {
            $person1Id = $request->input('person1_id');
            $person2Id = $request->input('person2_id');

            if (! $person1Id || ! $person2Id) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'person1_id and person2_id are required'],
                ], 400);
            }

            $aiService = app(GenealogyAIResearchService::class);
            $result = $aiService->analyzeRelationship((int) $person1Id, (int) $person2Id);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => $result['error']],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to analyze relationship', $e);
        }
    }

    // ========================================================================
    // NATURAL LANGUAGE SEARCH (Priority A.3)
    // ========================================================================

    /**
     * Search genealogy persons using natural language queries
     *
     * POST /api/genealogy/search/natural-language
     *
     * Enables queries like:
     * - "Who were the Doe family members born in Pennsylvania before 1900?"
     * - "Find all persons who died in Ohio"
     * - "Show me ancestors from Germany"
     *
     * Request body:
     * - query: string (required) - Natural language search query
     * - limit: int (optional, default: 10) - Maximum results to return
     */
    public function naturalLanguageSearch(Request $request): JsonResponse
    {
        try {
            $query = $request->input('query');
            $limit = (int) $request->input('limit', 10);

            if (! $query || strlen(trim($query)) < 3) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Query must be at least 3 characters'],
                ], 400);
            }

            $ragService = app(RAGService::class);
            $results = $ragService->search($query, $limit, 'genealogy_person');

            // Transform results to include person links
            // RAGService returns [{ 'document' => stdClass, 'similarity' => float }]
            $persons = array_map(function ($result) {
                $doc = $result['document'];
                $personId = $doc->source_id ?? null;
                $metadata = is_string($doc->metadata) ? json_decode($doc->metadata, true) : (array) ($doc->metadata ?? []);

                return [
                    'id' => $personId,
                    'name' => $doc->title ?? 'Unknown',
                    'content' => $doc->content ?? '',
                    'similarity' => round(($result['similarity'] ?? 0) * 100, 1),
                    'birth_date' => $metadata['birth_date'] ?? null,
                    'birth_place' => $metadata['birth_place'] ?? null,
                    'death_date' => $metadata['death_date'] ?? null,
                    'death_place' => $metadata['death_place'] ?? null,
                    'sex' => $metadata['sex'] ?? null,
                    'tree_id' => $metadata['tree_id'] ?? null,
                ];
            }, $results);

            return response()->json([
                'success' => true,
                'data' => [
                    'query' => $query,
                    'count' => count($persons),
                    'persons' => $persons,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Natural language search failed', $e);
        }
    }

    // ========================================================================
    // FACE MATCH APPROVAL QUEUE (Sprint 2)
    // ========================================================================

    /**
     * Get pending face matches for review
     */
    public function getFaceMatchQueue(Request $request, int $treeId): JsonResponse
    {
        try {
            $status = $request->get('status', 'pending');
            $limit = min((int) $request->get('limit', 50), 500);
            $offset = (int) $request->get('offset', 0);

            $statusFilter = $status === 'all' ? '' : 'AND q.status = ?';
            $params = $status === 'all' ? [$treeId, $limit, $offset] : [$treeId, $status, $limit, $offset];

            $sql = "SELECT
                        q.id,
                        q.media_id,
                        q.face_name,
                        q.suggested_person_id,
                        q.match_type,
                        q.confidence_score,
                        q.face_region,
                        q.match_details,
                        q.status,
                        q.reviewed_at,
                        q.review_notes,
                        q.created_at,
                        p.given_name,
                        p.surname,
                        p.birth_date,
                        p.death_date,
                        m.local_filename,
                        m.nextcloud_path
                    FROM genealogy_face_match_queue q
                    LEFT JOIN genealogy_persons p ON q.suggested_person_id = p.id
                    LEFT JOIN genealogy_media m ON q.media_id = m.id
                    WHERE q.tree_id = ? {$statusFilter}
                    ORDER BY q.confidence_score DESC, q.created_at ASC
                    LIMIT ? OFFSET ?";

            $matches = DB::select($sql, $params);

            // Get total count
            $countSql = 'SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE tree_id = ? '.($status !== 'all' ? 'AND status = ?' : '');
            $countParams = $status === 'all' ? [$treeId] : [$treeId, $status];
            $total = DB::selectOne($countSql, $countParams);

            // Transform results
            $items = array_map(function ($match) {
                return [
                    'id' => $match->id,
                    'media_id' => $match->media_id,
                    'face_name' => $match->face_name,
                    'match_type' => $match->match_type,
                    'confidence_score' => (float) $match->confidence_score,
                    'status' => $match->status,
                    'face_region' => $match->face_region ? json_decode($match->face_region, true) : null,
                    'match_details' => $match->match_details ? json_decode($match->match_details, true) : null,
                    'reviewed_at' => $match->reviewed_at,
                    'review_notes' => $match->review_notes,
                    'created_at' => $match->created_at,
                    'suggested_person' => $match->suggested_person_id ? [
                        'id' => $match->suggested_person_id,
                        'given_name' => $match->given_name,
                        'surname' => $match->surname,
                        'birth_date' => $match->birth_date,
                        'death_date' => $match->death_date,
                    ] : null,
                    'media' => [
                        'file_name' => $match->local_filename,
                        'file_path' => $match->nextcloud_path,
                    ],
                ];
            }, $matches);

            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'total' => $total->total,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get face match queue', $e);
        }
    }

    /**
     * Get face match queue statistics
     */
    public function getFaceMatchQueueStats(int $treeId): JsonResponse
    {
        try {
            // Status counts
            $statusCounts = DB::select(
                'SELECT status, COUNT(*) as count
                 FROM genealogy_face_match_queue
                 WHERE tree_id = ?
                 GROUP BY status',
                [$treeId]
            );

            $byStatus = [];
            $total = 0;
            foreach ($statusCounts as $row) {
                $byStatus[$row->status] = (int) $row->count;
                $total += (int) $row->count;
            }

            // Match type counts (pending only)
            $typeCounts = DB::select(
                "SELECT match_type, COUNT(*) as count, AVG(confidence_score) as avg_confidence
                 FROM genealogy_face_match_queue
                 WHERE tree_id = ? AND status = 'pending'
                 GROUP BY match_type
                 ORDER BY count DESC",
                [$treeId]
            );

            $byType = [];
            foreach ($typeCounts as $row) {
                $byType[$row->match_type] = [
                    'count' => (int) $row->count,
                    'avg_confidence' => round((float) $row->avg_confidence, 1),
                ];
            }

            // Recent activity
            $recent24h = DB::selectOne(
                "SELECT
                    SUM(CASE WHEN status = 'approved' AND reviewed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' AND reviewed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as rejected
                 FROM genealogy_face_match_queue
                 WHERE tree_id = ?",
                [$treeId]
            );

            $queueHealth = DB::selectOne(
                "SELECT
                    COUNT(*) as pending_total,
                    SUM(CASE WHEN match_type = 'no_match' THEN 1 ELSE 0 END) as no_match_pending,
                    SUM(CASE WHEN match_type NOT IN ('exact', 'no_match') THEN 1 ELSE 0 END) as fuzzy_pending,
                    SUM(CASE WHEN file_registry_face_id IS NOT NULL THEN 1 ELSE 0 END) as bridge_eligible_pending,
                    SUM(CASE WHEN created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as stale_pending,
                    SUM(CASE WHEN match_type NOT IN ('exact', 'no_match') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as stale_fuzzy_pending,
                    MIN(created_at) as oldest_pending_created_at,
                    MAX(TIMESTAMPDIFF(HOUR, created_at, NOW())) as oldest_pending_age_hours,
                    MAX(CASE WHEN match_type NOT IN ('exact', 'no_match') THEN TIMESTAMPDIFF(HOUR, created_at, NOW()) ELSE NULL END) as oldest_fuzzy_age_hours
                 FROM genealogy_face_match_queue
                 WHERE tree_id = ? AND status = 'pending'",
                [$treeId]
            );

            $faceRegistry = DB::selectOne(
                "SELECT
                    COUNT(*) as total_faces,
                    SUM(CASE WHEN hidden = 0 THEN 1 ELSE 0 END) as visible_faces,
                    SUM(CASE WHEN hidden = 0 AND person_name IS NOT NULL AND person_name != '' THEN 1 ELSE 0 END) as named_faces,
                    SUM(CASE WHEN hidden = 0 AND (person_name IS NULL OR person_name = '') THEN 1 ELSE 0 END) as unnamed_faces,
                    SUM(CASE WHEN hidden = 0 AND genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) as linked_faces
                 FROM file_registry_faces"
            );

            $treeMediaLinks = DB::selectOne(
                'SELECT
                    COUNT(*) as person_media_links,
                    SUM(CASE WHEN pm.face_confirmed = 1 THEN 1 ELSE 0 END) as confirmed_face_links
                 FROM genealogy_person_media pm
                 INNER JOIN genealogy_persons p ON p.id = pm.person_id
                 WHERE p.tree_id = ?',
                [$treeId]
            );

            $bridgeIssues = DB::selectOne(
                "SELECT COUNT(*) as approved_missing_person_media
                 FROM genealogy_face_match_queue q
                 LEFT JOIN genealogy_person_media pm
                    ON pm.person_id = q.suggested_person_id
                   AND pm.media_id = q.media_id
                 WHERE q.tree_id = ?
                   AND q.status IN ('approved', 'auto_linked')
                   AND q.file_registry_face_id IS NOT NULL
                   AND q.suggested_person_id IS NOT NULL
                   AND q.media_id IS NOT NULL
                   AND pm.id IS NULL",
                [$treeId]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'by_status' => $byStatus,
                    'pending_by_type' => $byType,
                    'last_24h' => [
                        'approved' => (int) ($recent24h->approved ?? 0),
                        'rejected' => (int) ($recent24h->rejected ?? 0),
                    ],
                    'queue_health' => [
                        'pending_total' => (int) ($queueHealth->pending_total ?? 0),
                        'no_match_pending' => (int) ($queueHealth->no_match_pending ?? 0),
                        'fuzzy_pending' => (int) ($queueHealth->fuzzy_pending ?? 0),
                        'bridge_eligible_pending' => (int) ($queueHealth->bridge_eligible_pending ?? 0),
                        'stale_pending' => (int) ($queueHealth->stale_pending ?? 0),
                        'stale_fuzzy_pending' => (int) ($queueHealth->stale_fuzzy_pending ?? 0),
                        'oldest_pending_created_at' => $queueHealth->oldest_pending_created_at ?? null,
                        'oldest_pending_age_hours' => isset($queueHealth->oldest_pending_age_hours)
                            ? (int) $queueHealth->oldest_pending_age_hours
                            : null,
                        'oldest_fuzzy_age_hours' => isset($queueHealth->oldest_fuzzy_age_hours)
                            ? (int) $queueHealth->oldest_fuzzy_age_hours
                            : null,
                    ],
                    'face_registry' => [
                        'total_faces' => (int) ($faceRegistry->total_faces ?? 0),
                        'visible_faces' => (int) ($faceRegistry->visible_faces ?? 0),
                        'named_faces' => (int) ($faceRegistry->named_faces ?? 0),
                        'unnamed_faces' => (int) ($faceRegistry->unnamed_faces ?? 0),
                        'linked_faces' => (int) ($faceRegistry->linked_faces ?? 0),
                    ],
                    'genealogy_links' => [
                        'person_media_links' => (int) ($treeMediaLinks->person_media_links ?? 0),
                        'confirmed_face_links' => (int) ($treeMediaLinks->confirmed_face_links ?? 0),
                    ],
                    'bridge_issues' => [
                        'approved_missing_person_media' => (int) ($bridgeIssues->approved_missing_person_media ?? 0),
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get queue stats', $e);
        }
    }

    /**
     * Approve a face match - creates person-media link
     */
    public function approveFaceMatch(Request $request, int $id): JsonResponse
    {
        try {
            $match = DB::selectOne(
                'SELECT * FROM genealogy_face_match_queue WHERE id = ?',
                [$id]
            );

            if (! $match) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Queue entry not found'],
                ], 404);
            }

            if ($match->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Entry already {$match->status}"],
                ], 400);
            }

            if (! $match->suggested_person_id) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'No suggested person - use /link endpoint to specify a person'],
                ], 400);
            }

            DB::beginTransaction();
            $bridgeResult = null;

            if ($match->file_registry_face_id) {
                $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink(
                    (int) $match->file_registry_face_id,
                    (int) $match->suggested_person_id,
                    $match->media_id ? (int) $match->media_id : null
                );
            }

            if ((! ($bridgeResult['success'] ?? false)) && $match->media_id) {
                // Decode face region from JSON
                $faceRegion = $match->face_region ? json_decode($match->face_region, true) : [];
                $this->markFileRegistryFaceLinked($match->file_registry_face_id ?? null, (int) $match->suggested_person_id);

                // Check if link already exists
                $existing = DB::selectOne(
                    'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                    [$match->suggested_person_id, $match->media_id]
                );

                if (! $existing) {
                    DB::insert(
                        'INSERT INTO genealogy_person_media
                         (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h,
                          face_confirmed, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                        [
                            $match->suggested_person_id,
                            $match->media_id,
                            $faceRegion['x'] ?? null,
                            $faceRegion['y'] ?? null,
                            $faceRegion['w'] ?? null,
                            $faceRegion['h'] ?? null,
                        ]
                    );
                }
            }

            // Update queue status
            $notes = $request->get('notes', null);
            DB::update(
                "UPDATE genealogy_face_match_queue
                 SET status = 'approved', reviewed_at = NOW(), review_notes = ?, updated_at = NOW()
                 WHERE id = ?",
                [$notes, $id]
            );

            DB::commit();

            Log::info('Face match approved via API', [
                'queue_id' => $id,
                'person_id' => $match->suggested_person_id,
                'media_id' => $match->media_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Match approved and person linked to media',
                'genealogy_bridge' => $bridgeResult,
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return $this->errorResponse('Failed to approve match', $e);
        }
    }

    /**
     * Reject a face match
     */
    public function rejectFaceMatch(Request $request, int $id): JsonResponse
    {
        try {
            $match = DB::selectOne(
                'SELECT * FROM genealogy_face_match_queue WHERE id = ?',
                [$id]
            );

            if (! $match) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Queue entry not found'],
                ], 404);
            }

            if ($match->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Entry already {$match->status}"],
                ], 400);
            }

            $notes = $request->get('notes', null);
            DB::update(
                "UPDATE genealogy_face_match_queue
                 SET status = 'rejected', reviewed_at = NOW(), review_notes = ?, updated_at = NOW()
                 WHERE id = ?",
                [$notes, $id]
            );

            Log::info('Face match rejected via API', [
                'queue_id' => $id,
                'face_name' => $match->face_name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Match rejected',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reject match', $e);
        }
    }

    /**
     * Link a face match to a specific person (for no_match or override)
     */
    public function linkFaceMatchToPerson(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'person_id' => 'required|integer',
            ]);

            $personId = (int) $request->get('person_id');

            $match = DB::selectOne(
                'SELECT * FROM genealogy_face_match_queue WHERE id = ?',
                [$id]
            );

            if (! $match) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Queue entry not found'],
                ], 404);
            }

            if ($match->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Entry already {$match->status}"],
                ], 400);
            }

            // Verify person exists in same tree
            $person = DB::selectOne(
                'SELECT id, given_name, surname FROM genealogy_persons WHERE id = ? AND tree_id = ?',
                [$personId, $match->tree_id]
            );

            if (! $person) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Person not found in this tree'],
                ], 404);
            }

            DB::beginTransaction();
            $bridgeResult = null;

            if ($match->file_registry_face_id) {
                $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink(
                    (int) $match->file_registry_face_id,
                    $personId,
                    $match->media_id ? (int) $match->media_id : null
                );
            }

            if ((! ($bridgeResult['success'] ?? false)) && $match->media_id) {
                // Decode face region from JSON
                $faceRegion = $match->face_region ? json_decode($match->face_region, true) : [];
                $this->markFileRegistryFaceLinked($match->file_registry_face_id ?? null, $personId);

                // Check if link already exists
                $existing = DB::selectOne(
                    'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                    [$personId, $match->media_id]
                );

                if (! $existing) {
                    DB::insert(
                        'INSERT INTO genealogy_person_media
                         (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h,
                          face_confirmed, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                        [
                            $personId,
                            $match->media_id,
                            $faceRegion['x'] ?? null,
                            $faceRegion['y'] ?? null,
                            $faceRegion['w'] ?? null,
                            $faceRegion['h'] ?? null,
                        ]
                    );
                }
            }

            // Update queue status with manual link info
            $notes = $request->get('notes', "Manually linked to {$person->given_name} {$person->surname}");
            DB::update(
                "UPDATE genealogy_face_match_queue
                 SET status = 'approved', suggested_person_id = ?, reviewed_at = NOW(),
                     review_notes = ?, match_type = 'manual', updated_at = NOW()
                 WHERE id = ?",
                [$personId, $notes, $id]
            );

            DB::commit();

            Log::info('Face match manually linked via API', [
                'queue_id' => $id,
                'person_id' => $personId,
                'media_id' => $match->media_id,
                'face_name' => $match->face_name,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Face linked to {$person->given_name} {$person->surname}",
                'data' => [
                    'person_id' => $personId,
                    'person_name' => "{$person->given_name} {$person->surname}",
                    'genealogy_bridge' => $bridgeResult,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return $this->errorResponse('Failed to link face match', $e);
        }
    }

    /**
     * Rename a face in the match queue (correct the detected name without genealogy linking)
     */
    public function renameFaceMatch(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'new_name' => 'required|string|max:255',
            ]);

            $newName = trim($request->get('new_name'));

            $match = DB::selectOne(
                'SELECT * FROM genealogy_face_match_queue WHERE id = ?',
                [$id]
            );

            if (! $match) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Queue entry not found'],
                ], 404);
            }

            if ($match->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Entry already {$match->status}"],
                ], 400);
            }

            $oldName = $match->face_name ?? 'Unknown';

            // Check if target name already exists for this media (unique constraint: media_id + face_name)
            $existing = DB::selectOne(
                'SELECT id FROM genealogy_face_match_queue WHERE media_id = ? AND face_name = ? AND id != ?',
                [$match->media_id, $newName, $id]
            );

            if ($existing) {
                // Target name already has a queue entry for this media — delete the current (wrong-name) row
                DB::delete('DELETE FROM genealogy_face_match_queue WHERE id = ?', [$id]);
                Log::info('Face match merged (duplicate removed)', [
                    'deleted_id' => $id,
                    'kept_id' => $existing->id,
                    'media_id' => $match->media_id,
                    'old_name' => $oldName,
                    'target_name' => $newName,
                ]);
            } else {
                DB::update(
                    "UPDATE genealogy_face_match_queue
                     SET face_name = ?, review_notes = CONCAT(COALESCE(review_notes, ''), 'Renamed from: ', ?), updated_at = NOW()
                     WHERE id = ?",
                    [$newName, $oldName, $id]
                );
            }

            // Also update the face name in file_registry_faces if we can find the matching face
            if ($match->face_region) {
                $faceRegion = json_decode($match->face_region, true);
                if ($faceRegion && isset($faceRegion['x'])) {
                    // Find matching file_registry_faces entry by media → file_registry link
                    $media = DB::selectOne(
                        'SELECT fr.id as file_registry_id FROM genealogy_media gm
                         JOIN file_registry fr ON fr.current_path = gm.nextcloud_path OR fr.original_path = gm.original_path
                         WHERE gm.id = ? LIMIT 1',
                        [$match->media_id]
                    );

                    if ($media) {
                        DB::update(
                            'UPDATE file_registry_faces
                             SET person_name = ?, updated_at = NOW()
                             WHERE file_registry_id = ? AND person_name = ?',
                            [$newName, $media->file_registry_id, $oldName]
                        );
                    }
                }
            }

            Log::info('Face match renamed via API', [
                'queue_id' => $id,
                'old_name' => $match->face_name,
                'new_name' => $newName,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Face renamed to {$newName}",
                'data' => [
                    'old_name' => $match->face_name,
                    'new_name' => $newName,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to rename face', $e);
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function markFileRegistryFaceLinked(mixed $fileRegistryFaceId, int $personId): void
    {
        if (empty($fileRegistryFaceId)) {
            return;
        }

        DB::update(
            'UPDATE file_registry_faces
             SET genealogy_person_id = ?, verified = 1, updated_at = NOW()
             WHERE id = ?',
            [$personId, (int) $fileRegistryFaceId]
        );

        DB::update(
            'UPDATE file_registry
             SET exif_faces_written = 0
             WHERE id = (
                 SELECT file_registry_id FROM file_registry_faces WHERE id = ?
             )',
            [(int) $fileRegistryFaceId]
        );
    }

    /**
     * Standard error response
     */
    private function errorResponse(string $message, Exception $e, int $code = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => $message.': '.$e->getMessage(),
            ],
        ], $code);
    }

    // ========================================================================
    // FAN CLUSTER (Friends, Associates, Neighbors)
    // ========================================================================

    /**
     * Get FAN cluster relationship and source types
     */
    public function getFanClusterTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'relationship_types' => FANClusterService::RELATIONSHIP_TYPES,
                'source_record_types' => FANClusterService::SOURCE_RECORD_TYPES,
                'confidence_levels' => FANClusterService::CONFIDENCE_LEVELS,
            ],
        ]);
    }

    /**
     * Get all FAN clusters for a person
     */
    public function getPersonFanClusters(int $personId): JsonResponse
    {
        try {
            $clusters = $this->fanClusterService->getClustersForPerson($personId);

            return response()->json([
                'success' => true,
                'data' => [
                    'person_id' => $personId,
                    'clusters' => $clusters,
                    'count' => count($clusters),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get FAN clusters', $e);
        }
    }

    /**
     * Get a single FAN cluster with details
     */
    public function getFanCluster(int $id): JsonResponse
    {
        try {
            $cluster = $this->fanClusterService->getCluster($id);

            if (! $cluster) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Cluster not found'],
                ], 404);
            }

            $members = $this->fanClusterService->getClusterMembers($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'cluster' => $cluster,
                    'members' => $members,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get FAN cluster', $e);
        }
    }

    /**
     * Create a new FAN cluster
     */
    public function createFanCluster(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'person_id' => 'required|integer',
                'name' => 'required|string|max:255',
                'research_period' => 'nullable|string|max:100',
                'location' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            $clusterId = $this->fanClusterService->createCluster(
                (int) $request->input('person_id'),
                $request->input('name'),
                $request->only(['research_period', 'location', 'notes'])
            );

            $cluster = $this->fanClusterService->getCluster($clusterId);

            return response()->json([
                'success' => true,
                'data' => ['cluster' => $cluster],
                'message' => 'FAN cluster created',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create FAN cluster', $e);
        }
    }

    /**
     * Update a FAN cluster
     */
    public function updateFanCluster(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'cluster_name' => 'sometimes|string|max:255',
                'research_period' => 'nullable|string|max:100',
                'location' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            $updated = $this->fanClusterService->updateCluster(
                $id,
                $request->only(['cluster_name', 'research_period', 'location', 'notes'])
            );

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Cluster not found or no changes made'],
                ], 404);
            }

            $cluster = $this->fanClusterService->getCluster($id);

            return response()->json([
                'success' => true,
                'data' => ['cluster' => $cluster],
                'message' => 'FAN cluster updated',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update FAN cluster', $e);
        }
    }

    /**
     * Delete a FAN cluster
     */
    public function deleteFanCluster(int $id): JsonResponse
    {
        try {
            $deleted = $this->fanClusterService->deleteCluster($id);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Cluster not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'FAN cluster deleted',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete FAN cluster', $e);
        }
    }

    /**
     * Add a member to a FAN cluster
     */
    public function addFanClusterMember(Request $request, int $clusterId): JsonResponse
    {
        try {
            $request->validate([
                'member_name' => 'required|string|max:255',
                'member_person_id' => 'nullable|integer',
                'relationship_type' => 'required|string',
                'source_record_type' => 'required|string',
                'source_citation' => 'nullable|string',
                'interaction_date' => 'nullable|date',
                'interaction_description' => 'nullable|string',
                'confidence' => 'nullable|string|in:high,medium,low',
            ]);

            $memberId = $this->fanClusterService->addMember($clusterId, $request->all());

            return response()->json([
                'success' => true,
                'member_id' => $memberId,
                'message' => 'Member added to FAN cluster',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add FAN cluster member', $e);
        }
    }

    /**
     * Update a FAN cluster member
     */
    public function updateFanClusterMember(Request $request, int $memberId): JsonResponse
    {
        try {
            $request->validate([
                'member_name' => 'sometimes|string|max:255',
                'member_person_id' => 'nullable|integer',
                'relationship_type' => 'sometimes|string',
                'source_record_type' => 'sometimes|string',
                'source_citation' => 'nullable|string',
                'interaction_date' => 'nullable|date',
                'interaction_description' => 'nullable|string',
                'confidence' => 'nullable|string|in:high,medium,low',
            ]);

            $updated = $this->fanClusterService->updateMember($memberId, $request->all());

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Member not found or no changes made'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'FAN cluster member updated',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update FAN cluster member', $e);
        }
    }

    /**
     * Remove a member from a FAN cluster
     */
    public function removeFanClusterMember(int $memberId): JsonResponse
    {
        try {
            $removed = $this->fanClusterService->removeMember($memberId);

            if (! $removed) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Member not found'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Member removed from FAN cluster',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to remove FAN cluster member', $e);
        }
    }

    /**
     * Link a FAN cluster member to a person in the database
     */
    public function linkFanMemberToPerson(Request $request, int $memberId): JsonResponse
    {
        try {
            $request->validate([
                'person_id' => 'required|integer',
            ]);

            $linked = $this->fanClusterService->linkMemberToPerson(
                $memberId,
                (int) $request->input('person_id')
            );

            if (! $linked) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Failed to link member to person'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'FAN cluster member linked to person',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to link FAN member to person', $e);
        }
    }

    /**
     * Extract potential FAN members from census records
     */
    public function extractFanFromCensus(Request $request, int $personId): JsonResponse
    {
        try {
            $year = $request->has('year') ? (int) $request->input('year') : null;
            $results = $this->fanClusterService->extractFromCensus($personId, $year);

            return response()->json([
                'success' => true,
                'data' => [
                    'person_id' => $personId,
                    'year' => $year,
                    'potential_members' => $results,
                    'count' => count($results),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to extract FAN from census', $e);
        }
    }

    /**
     * Extract witnesses from vital records
     */
    public function extractFanWitnesses(int $personId): JsonResponse
    {
        try {
            $results = $this->fanClusterService->extractWitnesses($personId);

            return response()->json([
                'success' => true,
                'data' => [
                    'person_id' => $personId,
                    'witnesses' => $results,
                    'count' => count($results),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to extract FAN witnesses', $e);
        }
    }

    /**
     * Extract godparents and church associates
     */
    public function extractFanChurchAssociates(int $personId): JsonResponse
    {
        try {
            $results = $this->fanClusterService->extractChurchAssociates($personId);

            return response()->json([
                'success' => true,
                'data' => [
                    'person_id' => $personId,
                    'church_associates' => $results,
                    'count' => count($results),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to extract FAN church associates', $e);
        }
    }

    /**
     * Analyze a FAN cluster for patterns
     */
    public function analyzeFanCluster(int $id): JsonResponse
    {
        try {
            $analysis = $this->fanClusterService->analyzeCluster($id);

            return response()->json([
                'success' => true,
                'data' => $analysis,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to analyze FAN cluster', $e);
        }
    }

    /**
     * Get research suggestions based on FAN cluster analysis
     */
    public function getFanResearchSuggestions(int $id): JsonResponse
    {
        try {
            $suggestions = $this->fanClusterService->suggestResearchTargets($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'cluster_id' => $id,
                    'suggestions' => $suggestions,
                    'count' => count($suggestions),
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get FAN research suggestions', $e);
        }
    }

    /**
     * Get FAN cluster network data for visualization
     */
    public function getFanClusterNetwork(int $id): JsonResponse
    {
        try {
            $network = $this->fanClusterService->getClusterNetwork($id);

            return response()->json([
                'success' => true,
                'data' => $network,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get FAN cluster network', $e);
        }
    }

    // ========================================================================
    // N98: RESEARCH SEARCH HISTORY
    // ========================================================================

    /**
     * Get research search history for a person from gps_research_logs.
     */
    public function getPersonResearchLogs(Request $request, int $personId): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);
        $repository = $request->query('repository');
        $negative = $request->query('negative');

        $params = [$personId];
        $where = 'WHERE rl.person_id = ?';

        if ($repository) {
            $where .= ' AND rl.repository_searched = ?';
            $params[] = $repository;
        }
        if ($negative !== null) {
            $where .= ' AND rl.negative_result = ?';
            $params[] = (int) $negative;
        }

        $logs = DB::select("
            SELECT rl.*,
                   rt.task_type, rt.question AS task_question
            FROM gps_research_logs rl
            LEFT JOIN gps_research_tasks rt ON rt.id = rl.task_id
            {$where}
            ORDER BY rl.searched_at DESC
            LIMIT {$limit}
        ", $params);

        $summary = DB::select('
            SELECT repository_searched,
                   COUNT(*) AS total_searches,
                   SUM(negative_result) AS negative_count,
                   SUM(CASE WHEN negative_result = 0 THEN 1 ELSE 0 END) AS positive_count,
                   MAX(searched_at) AS last_searched
            FROM gps_research_logs
            WHERE person_id = ?
            GROUP BY repository_searched
            ORDER BY total_searches DESC
        ', [$personId]);

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'summary' => $summary,
                'total' => count($logs),
            ],
        ]);
    }

    /**
     * Get research search history for a tree. CSV export via ?format=csv
     */
    public function getTreeResearchLogs(Request $request, int $treeId): JsonResponse
    {
        $limit = min((int) $request->query('limit', 200), 1000);
        $format = $request->query('format', 'json');

        $logs = DB::select("
            SELECT rl.*,
                   p.given_name, p.surname,
                   rt.task_type
            FROM gps_research_logs rl
            JOIN genealogy_persons p ON p.id = rl.person_id
            LEFT JOIN gps_research_tasks rt ON rt.id = rl.task_id
            WHERE p.tree_id = ?
            ORDER BY rl.searched_at DESC
            LIMIT {$limit}
        ", [$treeId]);

        if ($format === 'csv') {
            $csv = "Person,Repository,Search Terms,Negative,Date\n";
            foreach ($logs as $row) {
                $name = trim(($row->given_name ?? '').' '.($row->surname ?? ''));
                $csv .= '"'.addslashes($name).'",'
                    .'"'.addslashes($row->repository_searched ?? '').'",'
                    .'"'.addslashes($row->search_terms ?? '').'",'
                    .($row->negative_result ? 'Yes' : 'No').','
                    .($row->searched_at ?? '')."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=research-logs-tree-{$treeId}.csv",
            ]);
        }

        $summary = DB::select('
            SELECT rl.repository_searched,
                   COUNT(*) AS searches,
                   SUM(rl.negative_result) AS negative,
                   COUNT(DISTINCT rl.person_id) AS persons_searched
            FROM gps_research_logs rl
            JOIN genealogy_persons p ON p.id = rl.person_id
            WHERE p.tree_id = ?
            GROUP BY rl.repository_searched
            ORDER BY searches DESC
        ', [$treeId]);

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'repository_summary' => $summary,
                'total' => count($logs),
            ],
        ]);
    }

    /**
     * N93: Get FAN co-occurrences accumulated by the genealogy agent for a person.
     * Returns names ranked by (occurrence_count × confidence), grouped by source_type.
     */
    public function getPersonFanCooccurrences(Request $request, int $personId): JsonResponse
    {
        $minConfidence = (float) $request->get('min_confidence', 0.4);
        $sourceType = $request->get('source_type');
        $limit = min((int) $request->get('limit', 50), 200);

        $params = [$personId, $minConfidence];
        $where = 'WHERE person_id = ? AND confidence >= ?';

        if ($sourceType) {
            $where .= ' AND source_type = ?';
            $params[] = $sourceType;
        }

        $rows = DB::select("
            SELECT cooccurring_name, source_type, source_ref, source_date, source_location,
                   occurrence_count, confidence,
                   ROUND(occurrence_count * confidence, 3) AS rank_score,
                   updated_at
            FROM fan_cooccurrences
            {$where}
            ORDER BY rank_score DESC, occurrence_count DESC
            LIMIT {$limit}
        ", $params);

        // Group by source_type for summary
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->source_type] = ($byType[$row->source_type] ?? 0) + 1;
        }

        // High-value: rare surnames appearing 2+ times across any source type
        $highValue = array_filter($rows, fn ($r) => $r->occurrence_count >= 2);

        return response()->json([
            'success' => true,
            'data' => [
                'person_id' => $personId,
                'total' => count($rows),
                'high_value' => count($highValue),
                'by_type' => $byType,
                'cooccurrences' => $rows,
            ],
        ]);
    }
}
