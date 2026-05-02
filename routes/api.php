<?php

use App\Http\Controllers\Api\AgentDoctorController;
use App\Http\Controllers\Api\AIStatusController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataRemovalController;
use App\Http\Controllers\Api\DevToolsController;
use App\Http\Controllers\Api\EmailAutomationController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\ExtensionController;
use App\Http\Controllers\Api\JoplinController;
use App\Http\Controllers\Api\JoplinWriteController;
use App\Http\Controllers\Api\MCPController;
use App\Http\Controllers\Api\MediaProxyController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\OperatorEvidenceController;
use App\Http\Controllers\Api\OperatorOfflineStatusController;
use App\Http\Controllers\Api\OrchestratorController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RAGController;
use App\Http\Controllers\Api\ResearchAssistantController;
use App\Http\Controllers\Api\ResearchMissionController;
use App\Http\Controllers\Api\ResearchTopicController;
use App\Http\Controllers\Api\SystemPromptController;
use App\Http\Controllers\Api\UnifiedResearchController;
use App\Http\Controllers\Api\UnifiedReviewController;
use App\Http\Controllers\Api\UnifiedSearchController;
use App\Http\Controllers\Api\WebhookTriggerController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ContactsController;
use App\Http\Controllers\GenealogyController;
use App\Http\Controllers\SystemIssuesController;
use Illuminate\Support\Facades\Route;

// Public routes - Authentication
Route::post('/auth/login', [AuthController::class, 'login']);

// Public routes - Read-only access for personal/family use
// Dashboard
Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
Route::get('/dashboard/daily-ops', [DashboardController::class, 'dailyOps']);
Route::get('/dashboard/ai-observability', [DashboardController::class, 'aiObservability']);

// Workflow webhooks
Route::post('/webhooks/{token}', [WebhookTriggerController::class, 'handle']);

// Nodes
Route::get('/nodes/types', [NodeController::class, 'types']);

// Workflows - public for personal/family use
Route::get('/workflows', [WorkflowController::class, 'index']);

// Workflow Templates & Approvals (literal paths before {id} parameter)
Route::get('/workflows/templates', [WorkflowController::class, 'listTemplates']);
Route::post('/workflows/templates', [WorkflowController::class, 'createTemplate']);
Route::post('/workflows/from-template/{id}', [WorkflowController::class, 'instantiateTemplate']);
Route::get('/workflows/pending-approvals', [WorkflowController::class, 'pendingApprovals']);

Route::get('/workflows/{id}', [WorkflowController::class, 'show']);
Route::post('/workflows', [WorkflowController::class, 'store']);
Route::put('/workflows/{id}', [WorkflowController::class, 'update']);
Route::delete('/workflows/{id}', [WorkflowController::class, 'destroy']);
Route::post('/workflows/{id}/run', [WorkflowController::class, 'run']);
Route::post('/workflows/{id}/toggle', [WorkflowController::class, 'toggle']);
Route::post('/workflows/{id}/clone', [WorkflowController::class, 'clone']);
Route::post('/workflows/{id}/dry-run', [WorkflowController::class, 'dryRun']);
Route::get('/workflows/{id}/metrics', [WorkflowController::class, 'workflowMetrics']);
Route::get('/workflows/{id}/cache-stats', [WorkflowController::class, 'cacheStats']);

// Workflow Backups - auto-backup system
Route::post('/workflows/{id}/backups', [WorkflowController::class, 'createBackup']);
Route::get('/workflows/{id}/backups', [WorkflowController::class, 'listBackups']);
Route::post('/workflows/{id}/backups/{backupId}/restore', [WorkflowController::class, 'restoreBackup']);
Route::delete('/workflows/backups/{backupId}', [WorkflowController::class, 'deleteBackup']);

// Executions - read only
Route::get('/executions', [ExecutionController::class, 'index']);
Route::get('/executions/stats', [ExecutionController::class, 'stats']);
Route::get('/executions/{id}', [ExecutionController::class, 'show']);

// AI Status - read only
Route::get('/ai/status', [AIStatusController::class, 'status']);
Route::post('/ai/test', [AIStatusController::class, 'test']);

// RAG - read only
Route::post('/rag/search', [RAGController::class, 'search']);
Route::get('/rag/stats', [RAGController::class, 'stats']);
Route::get('/rag/documents', [RAGController::class, 'list']);
Route::get('/rag/documents/{id}', [RAGController::class, 'show']);

// Knowledge Graph - entity relationships
Route::prefix('rag/graph')->group(function () {
    Route::get('/stats', [RAGController::class, 'graphStats']);
    Route::post('/extract', [RAGController::class, 'extractEntities']);
    Route::post('/triples', [RAGController::class, 'addTriple']);
    Route::get('/relationships/{entity}', [RAGController::class, 'findRelationships']);
    Route::get('/entity-graph/{entity}', [RAGController::class, 'getEntityGraph']);
    Route::get('/entities', [RAGController::class, 'searchEntities']);
    Route::get('/by-relationship', [RAGController::class, 'searchByRelationship']);
    Route::post('/merge', [RAGController::class, 'mergeEntities']);
    Route::delete('/triples/{id}', [RAGController::class, 'deleteTriple']);
    Route::delete('/entities/{id}', [RAGController::class, 'deleteEntity']);
    Route::get('/communities/stats', [RAGController::class, 'communityStats']);
    Route::get('/full-graph', [RAGController::class, 'getFullGraph']);
});

// Multimodal Embeddings - visual/image search
Route::prefix('rag/visual')->group(function () {
    Route::get('/stats', [RAGController::class, 'visualStats']);
    Route::post('/search', [RAGController::class, 'visualSearch']);
    Route::post('/hybrid-search', [RAGController::class, 'hybridSearch']);
    Route::get('/documents', [RAGController::class, 'visualDocuments']);
    Route::post('/documents/{id}/analyze', [RAGController::class, 'analyzeVisual']);
    Route::post('/batch-analyze', [RAGController::class, 'batchAnalyzeVisual']);
    Route::post('/generate-embedding', [RAGController::class, 'generateImageEmbedding']);
});

// AI Chat - public for personal/family use
Route::get('/chat/conversations', [ChatController::class, 'index']);
Route::post('/chat/conversations', [ChatController::class, 'store']);
Route::get('/chat/conversations/{id}', [ChatController::class, 'show']);
Route::delete('/chat/conversations/{id}', [ChatController::class, 'destroy']);
Route::delete('/chat/conversations/{id}/messages', [ChatController::class, 'clearMessages']);
Route::post('/chat/conversations/{id}/messages', [ChatController::class, 'sendMessage']);
Route::post('/chat/conversations/{id}/messages/stream', [ChatController::class, 'sendMessageStream']);
Route::post('/chat/conversations/{conversationId}/messages/{messageId}/save-to-rag', [ChatController::class, 'saveMessageToRAG']);
Route::get('/chat/model-modes', [ChatController::class, 'getModelModes']);

// System Prompts - public for personal/family use
Route::get('/system-prompts/conversations/{id}', [SystemPromptController::class, 'getConversationPrompt']);
Route::put('/system-prompts/conversations/{id}', [SystemPromptController::class, 'updateConversationPrompt']);
Route::delete('/system-prompts/conversations/{id}', [SystemPromptController::class, 'clearConversationPrompt']);
Route::get('/system-prompts/default', [SystemPromptController::class, 'getDefaultPrompt']);
Route::put('/system-prompts/default', [SystemPromptController::class, 'updateDefaultPrompt']);

// MCP Tool Calling - read only
Route::get('/mcp/status', [MCPController::class, 'status']);
Route::get('/mcp/tools', [MCPController::class, 'tools']);
Route::get('/mcp/servers', [MCPController::class, 'servers']);
Route::post('/mcp/call', [MCPController::class, 'call']);
Route::post('/mcp/call-direct', [MCPController::class, 'callDirect']);
Route::put('/mcp/servers/{server}', [MCPController::class, 'updateServer']);
Route::post('/mcp/clear-cache', [MCPController::class, 'clearCache']);
Route::get('/mcp/config', [MCPController::class, 'getConfig']);
Route::get('/mcp/analytics', [MCPController::class, 'analytics']);

// Intelligent Orchestrator - public for personal/family use
Route::post('/orchestrator/process', [OrchestratorController::class, 'process']);
Route::get('/orchestrator/status', [OrchestratorController::class, 'status']);
Route::get('/orchestrator/help', [OrchestratorController::class, 'help']);

// Email Automation - public for personal/family use (Legacy - kept for backward compatibility)
Route::get('/email/stats', [EmailAutomationController::class, 'stats']);
Route::post('/email/classify', [EmailAutomationController::class, 'classify']);
Route::get('/email/classifications', [EmailAutomationController::class, 'listClassifications']);
Route::post('/email/reply/generate', [EmailAutomationController::class, 'generateReply']);
Route::get('/email/drafts', [EmailAutomationController::class, 'listDrafts']);
Route::patch('/email/drafts/{id}/approve', [EmailAutomationController::class, 'approveDraft']);
Route::get('/email/templates', [EmailAutomationController::class, 'listTemplates']);
Route::get('/email/rules', [EmailAutomationController::class, 'listRules']);
Route::post('/email/rules', [EmailAutomationController::class, 'createRule']);

// EA2: Unified Email Service - comprehensive email access
Route::prefix('email/v2')->group(function () {
    // Status & Diagnostics
    Route::get('/status', [\App\Http\Controllers\Api\EmailController::class, 'status']);
    Route::get('/stats', [\App\Http\Controllers\Api\EmailController::class, 'stats']);
    Route::post('/reset-circuit', [\App\Http\Controllers\Api\EmailController::class, 'resetCircuit']);

    // Email Reading (requires Thunderbird MCP)
    Route::get('/folders', [\App\Http\Controllers\Api\EmailController::class, 'folders']);
    Route::get('/mailboxes', [\App\Http\Controllers\Api\EmailController::class, 'mailboxes']);
    Route::get('/search', [\App\Http\Controllers\Api\EmailController::class, 'search']);
    Route::get('/recent', [\App\Http\Controllers\Api\EmailController::class, 'recent']);

    // Draft Queue (human approval workflow)
    Route::get('/queue', [\App\Http\Controllers\Api\EmailController::class, 'queue']);
    Route::post('/queue', [\App\Http\Controllers\Api\EmailController::class, 'createDraft']);
    Route::get('/queue/{id}', [\App\Http\Controllers\Api\EmailController::class, 'getDraft']);
    Route::put('/queue/{id}', [\App\Http\Controllers\Api\EmailController::class, 'updateDraft']);
    Route::post('/queue/{id}/approve', [\App\Http\Controllers\Api\EmailController::class, 'approveDraft']);
    Route::post('/queue/{id}/reject', [\App\Http\Controllers\Api\EmailController::class, 'rejectDraft']);

    // Classification
    Route::post('/classify', [\App\Http\Controllers\Api\EmailController::class, 'classify']);
    Route::get('/classification/stats', [\App\Http\Controllers\Api\EmailController::class, 'classificationStats']);

    // Settings
    Route::get('/settings', [\App\Http\Controllers\Api\EmailController::class, 'settings']);
    Route::put('/settings', [\App\Http\Controllers\Api\EmailController::class, 'updateSettings']);

    // EA2: AI Suggestions (contacts, calendar, bills)
    Route::get('/suggestions', [\App\Http\Controllers\Api\EmailController::class, 'suggestions']);
    Route::get('/suggestions/stats', [\App\Http\Controllers\Api\EmailController::class, 'suggestionStats']);
    Route::post('/suggestions/scan', [\App\Http\Controllers\Api\EmailController::class, 'scanSuggestions']);
    Route::post('/suggestions/{id}/approve', [\App\Http\Controllers\Api\EmailController::class, 'approveSuggestion']);
    Route::post('/suggestions/{id}/reject', [\App\Http\Controllers\Api\EmailController::class, 'rejectSuggestion']);
    Route::get('/suggestions/settings', [\App\Http\Controllers\Api\EmailController::class, 'suggestionSettings']);
    Route::put('/suggestions/settings', [\App\Http\Controllers\Api\EmailController::class, 'updateSuggestionSettings']);

    // Sentiment, unsubscribe, follow-up, draft versions, scheduled — removed (D1)

    // Email Analytics
    Route::get('/analytics', [\App\Http\Controllers\Api\EmailController::class, 'emailAnalytics']);
});

// Operator Evidence - authenticated read-only ops counts
Route::middleware('auth:web')->get('/ops/operator-evidence', OperatorEvidenceController::class);
Route::middleware('auth:web')->get('/ops/offline-status', OperatorOfflineStatusController::class);
Route::middleware('auth:web')->get('/ops/agents/doctor', AgentDoctorController::class);

// Joplin note data is personal by default. Public releases expose it only to authenticated users.
Route::middleware('auth:web')->group(function () {
    Route::get('/joplin/write/status', [JoplinWriteController::class, 'status']);
    Route::post('/joplin/notes/{id}/append', [JoplinWriteController::class, 'appendToNote']);
    Route::post('/joplin/sync/detect-conflicts', [JoplinWriteController::class, 'detectConflicts']);

    // Notes
    Route::get('/joplin/notes', [JoplinController::class, 'listNotes']);
    Route::get('/joplin/notes/{id}', [JoplinController::class, 'getNote']);
    Route::post('/joplin/notes', [JoplinController::class, 'createNote']);
    Route::put('/joplin/notes/{id}', [JoplinController::class, 'updateNote']);
    Route::delete('/joplin/notes/{id}', [JoplinController::class, 'deleteNote']);

    // Notebooks
    Route::get('/joplin/notebooks', [JoplinController::class, 'listNotebooks']);
    Route::post('/joplin/notebooks', [JoplinController::class, 'createNotebook']);

    // Tags
    Route::get('/joplin/tags', [JoplinController::class, 'listTags']);
    Route::post('/joplin/tags', [JoplinController::class, 'createTag']);
    Route::post('/joplin/tags/refresh', [JoplinController::class, 'refreshTagCache']);
    Route::get('/joplin/notes/{id}/tags', [JoplinController::class, 'getNoteTags']);
    Route::post('/joplin/notes/{id}/tags', [JoplinController::class, 'addTagToNote']);
    Route::get('/joplin/notes/{id}/attachments', [JoplinController::class, 'getNoteAttachments']); // E17/EA1
});

// Media Proxy - Serve files from Nextcloud via WebDAV (E17/EA1)
Route::middleware('auth:web')->get('/media/joplin/{resourceId}', [MediaProxyController::class, 'getJoplinAttachment']);
Route::middleware('auth:web')->get('/media/file', [MediaProxyController::class, 'getFile']);

// ============================================================================
// Media Browser - Standalone media management
// Integrates with genealogy for face matching, but operates independently
// ============================================================================
Route::get('/media', [\App\Http\Controllers\Api\MediaBrowserController::class, 'index']);
Route::get('/media/browse', [\App\Http\Controllers\Api\MediaBrowserController::class, 'browse']);
Route::get('/media/folders', [\App\Http\Controllers\Api\MediaBrowserController::class, 'folders']);
Route::get('/media/persons', [\App\Http\Controllers\Api\MediaBrowserController::class, 'persons']);
Route::get('/media/genealogy-persons', [\App\Http\Controllers\Api\MediaBrowserController::class, 'genealogyPersons']);
Route::get('/media/stats', [\App\Http\Controllers\Api\MediaBrowserController::class, 'stats']);
Route::get('/media/faces/queue', [\App\Http\Controllers\Api\MediaBrowserController::class, 'faceQueue']);

// AI Face Clusters (pgvector)
Route::get('/media/face-clusters', [\App\Http\Controllers\Api\MediaBrowserController::class, 'faceClusters']);
Route::get('/media/face-clusters/{id}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'faceCluster']);
Route::get('/media/face-clusters/{id}/similar', [\App\Http\Controllers\Api\MediaBrowserController::class, 'similarClusters']);
Route::get('/media/face-clusters/{id}/faces', [\App\Http\Controllers\Api\MediaBrowserController::class, 'clusterFaces']);
Route::get('/media/face/{id}/photo-context', [\App\Http\Controllers\Api\MediaBrowserController::class, 'facePhotoContext']);
Route::get('/media/face-crop/{id}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'serveFaceCrop']);
Route::get('/media/face-match-crop/{id}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'serveFaceMatchCrop']);
Route::get('/media/face-counts-by-person', [\App\Http\Controllers\Api\MediaBrowserController::class, 'faceCountsByPerson']);

// Faces page
Route::get('/media/faces/recognized', [\App\Http\Controllers\Api\MediaBrowserController::class, 'facesRecognized']);
Route::get('/media/faces/new', [\App\Http\Controllers\Api\MediaBrowserController::class, 'facesNew']);
Route::middleware('auth:web')->get('/media/faces/named-only', [\App\Http\Controllers\Api\MediaBrowserController::class, 'facesNamedOnly']);
Route::middleware('auth:web')->get('/media/faces/{faceId}/candidates', [\App\Http\Controllers\Api\MediaBrowserController::class, 'faceCandidates'])->where('faceId', '[0-9]+');
Route::get('/media/faces/registry-crop/{faceId}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'serveFaceRegistryCrop']);
Route::get('/media/faces/hidden', [\App\Http\Controllers\Api\MediaBrowserController::class, 'facesHidden']);
Route::get('/media/faces/unidentified', [\App\Http\Controllers\Api\MediaBrowserController::class, 'unidentifiedFaces']);
Route::get('/media/faces/person-faces', [\App\Http\Controllers\Api\MediaBrowserController::class, 'personFaces']);

// Metadata editing
Route::get('/media/{uuid}/metadata', [\App\Http\Controllers\Api\MediaBrowserController::class, 'getMetadata'])->where('uuid', '[a-f0-9-]{36}');

// Media item routes (UUID-based)
Route::get('/media/{uuid}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'show'])->where('uuid', '[a-f0-9-]{36}');
Route::get('/media/{uuid}/thumbnail/{size?}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'thumbnail'])->where('uuid', '[a-f0-9-]{36}');
Route::get('/media/{uuid}/stream', [\App\Http\Controllers\Api\MediaBrowserController::class, 'stream'])->where('uuid', '[a-f0-9-]{36}');

Route::get('/media/{uuid}/versions', [\App\Http\Controllers\Api\MediaBrowserController::class, 'versions'])->where('uuid', '[a-f0-9-]{36}');
Route::get('/media/path-thumbnail', [\App\Http\Controllers\Api\MediaBrowserController::class, 'thumbnailByPath']);
Route::get('/media/face-crop', [\App\Http\Controllers\Api\MediaBrowserController::class, 'faceCrop']);

// Media mutations can alter tags, names, metadata, physical files, and genealogy links.
Route::middleware('auth:web')->group(function () {
    // EXIF Writeback - Write metadata to physical files (runs as www-data via PHP-FPM)
    Route::get('/media/writeback/stats', [\App\Http\Controllers\Api\MediaBrowserController::class, 'writebackStats']);

    Route::post('/media/faces/queue/{id}/review', [\App\Http\Controllers\Api\MediaBrowserController::class, 'reviewFaceMatch']);
    Route::post('/media/faces/link', [\App\Http\Controllers\Api\MediaBrowserController::class, 'linkFace']);

    Route::post('/media/face-clusters/{id}/confirm', [\App\Http\Controllers\Api\MediaBrowserController::class, 'confirmCluster']);
    Route::post('/media/face-clusters/{id}/link', [\App\Http\Controllers\Api\MediaBrowserController::class, 'linkClusterToGenealogy']);
    Route::post('/media/face-clusters/merge', [\App\Http\Controllers\Api\MediaBrowserController::class, 'mergeClusters']);
    Route::post('/media/face-clusters/batch-confirm', [\App\Http\Controllers\Api\MediaBrowserController::class, 'batchConfirmClusters']);
    Route::post('/media/face-clusters/{id}/propagate', [\App\Http\Controllers\Api\MediaBrowserController::class, 'propagateMatches']);
    Route::post('/media/face-clusters/{id}/ignore', [\App\Http\Controllers\Api\MediaBrowserController::class, 'ignoreCluster']);
    Route::post('/media/face-clusters/{id}/revert', [\App\Http\Controllers\Api\MediaBrowserController::class, 'revertCluster']);
    Route::post('/media/face-clusters/{id}/split', [\App\Http\Controllers\Api\MediaBrowserController::class, 'splitCluster']);

    // Unified Face Cluster API (Phase 4 — coexists with legacy endpoints above)
    Route::post('/media/faces/clusters/{id}/identify', [\App\Http\Controllers\Api\MediaBrowserController::class, 'identifyClusterUnified']);
    Route::post('/media/faces/clusters/batch-identify', [\App\Http\Controllers\Api\MediaBrowserController::class, 'batchIdentifyClusters']);
    Route::post('/media/faces/clusters/{id}/hide', [\App\Http\Controllers\Api\MediaBrowserController::class, 'hideClusterUnified']);
    Route::post('/media/faces/clusters/{id}/restore', [\App\Http\Controllers\Api\MediaBrowserController::class, 'restoreClusterUnified']);

    Route::post('/media/writeback/batch', [\App\Http\Controllers\Api\MediaBrowserController::class, 'writebackBatch']);
    Route::post('/media/writeback/{uuid}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'writebackFile'])->where('uuid', '[a-f0-9-]{36}');

    // Face reassignment & person links
    Route::post('/media/faces/{faceId}/reassign', [\App\Http\Controllers\Api\MediaBrowserController::class, 'reassignFace']);
    Route::post('/media/faces/{faceId}/unlink', [\App\Http\Controllers\Api\MediaBrowserController::class, 'unlinkFace']);
    Route::post('/media/faces/{faceId}/name', [\App\Http\Controllers\Api\MediaBrowserController::class, 'setFaceName']);
    Route::post('/media/faces/{faceId}/candidate-decision', [\App\Http\Controllers\Api\MediaBrowserController::class, 'decideFaceCandidate'])->where('faceId', '[0-9]+');
    Route::post('/media/faces/bulk-name', [\App\Http\Controllers\Api\MediaBrowserController::class, 'bulkNameFaces']);
    Route::post('/media/faces/bulk-hide', [\App\Http\Controllers\Api\MediaBrowserController::class, 'bulkHideFaces']);
    Route::post('/media/faces/rename-person', [\App\Http\Controllers\Api\MediaBrowserController::class, 'renamePerson']);
    Route::patch('/media/face-match/{id}/status', [\App\Http\Controllers\Api\MediaBrowserController::class, 'updateFaceMatchStatus']);
    Route::post('/media/faces/{faceId}/exclude', [\App\Http\Controllers\Api\MediaBrowserController::class, 'excludeFace']);

    Route::post('/media/{uuid}/person-link', [\App\Http\Controllers\Api\MediaBrowserController::class, 'addPersonLink'])->where('uuid', '[a-f0-9-]{36}');
    Route::delete('/media/{uuid}/person-link/{personId}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'removePersonLink'])->where('uuid', '[a-f0-9-]{36}');
    Route::post('/media/{uuid}/metadata', [\App\Http\Controllers\Api\MediaBrowserController::class, 'updateMetadata'])->where('uuid', '[a-f0-9-]{36}');
    Route::delete('/media/{uuid}', [\App\Http\Controllers\Api\MediaBrowserController::class, 'deleteFile'])->where('uuid', '[a-f0-9-]{36}');
    Route::delete('/media/{uuid}/purge', [\App\Http\Controllers\Api\MediaBrowserController::class, 'hardPurgeFile'])->where('uuid', '[a-f0-9-]{36}');
    Route::post('/media/{uuid}/rename', [\App\Http\Controllers\Api\MediaBrowserController::class, 'renameFile'])->where('uuid', '[a-f0-9-]{36}');

    // Image editing & version history
    Route::post('/media/{uuid}/edit', [\App\Http\Controllers\Api\MediaBrowserController::class, 'editImage'])->where('uuid', '[a-f0-9-]{36}');
    Route::post('/media/{uuid}/edit-preview', [\App\Http\Controllers\Api\MediaBrowserController::class, 'editPreview'])->where('uuid', '[a-f0-9-]{36}');
    Route::post('/media/{uuid}/versions/{versionId}/restore', [\App\Http\Controllers\Api\MediaBrowserController::class, 'restoreVersion'])->where('uuid', '[a-f0-9-]{36}');
});

// ============================================================================
// Unified Search - Cross-domain search combining Media + Documents/RAG
// Hybrid search with RRF ranking, autocomplete, faceted filters
// ============================================================================
Route::get('/search', [UnifiedSearchController::class, 'search']);
Route::get('/search/suggestions', [UnifiedSearchController::class, 'suggestions']);
Route::get('/search/facets', [UnifiedSearchController::class, 'facets']);
Route::get('/search/landing', [UnifiedSearchController::class, 'landing']);
Route::get('/search/stats', [UnifiedSearchController::class, 'stats']);

Route::middleware('auth:web')->group(function () {
    // Search (Joplin)
    Route::get('/joplin/search', [JoplinController::class, 'searchNotes']);

    // Monitoring & Status
    Route::get('/joplin/lock-status', [JoplinController::class, 'getLockStatus']);
    Route::get('/joplin/queue-stats', [JoplinController::class, 'getQueueStats']);
    Route::get('/joplin/health', [JoplinController::class, 'getHealth']);
});

// Calendar - Nextcloud CalDAV integration (with caching)
Route::get('/calendar/calendars', [CalendarController::class, 'getCalendars']);
Route::get('/calendar/events', [CalendarController::class, 'getEvents']);
Route::get('/calendar/events/all', [CalendarController::class, 'getAllEvents']);
Route::post('/calendar/refresh', [CalendarController::class, 'refresh']);
Route::get('/calendar/cache-status', [CalendarController::class, 'cacheStatus']);

// Contacts - Nextcloud CardDAV integration (with caching)
Route::get('/contacts/addressbooks', [ContactsController::class, 'getAddressBooks']);
Route::get('/contacts', [ContactsController::class, 'getContacts']);
Route::get('/contacts/all', [ContactsController::class, 'getAllContacts']);
Route::post('/contacts/refresh', [ContactsController::class, 'refresh']);
Route::get('/contacts/cache-status', [ContactsController::class, 'cacheStatus']);

// Research Topics - AI-assisted research workflow
Route::get('/research-topics', [ResearchTopicController::class, 'index']);
Route::get('/research-topics/stats', [ResearchTopicController::class, 'stats']);
Route::get('/research-topics/rag-categories', [ResearchTopicController::class, 'ragCategories']);
Route::get('/research-topics/pending', [ResearchTopicController::class, 'pendingResults']);
Route::get('/research-topics/deferred', [ResearchTopicController::class, 'deferredResults']);
Route::post('/research-topics/refine', [ResearchTopicController::class, 'refine']);
Route::get('/research-topics/{id}', [ResearchTopicController::class, 'show']);
Route::post('/research-topics', [ResearchTopicController::class, 'store']);
Route::put('/research-topics/{id}', [ResearchTopicController::class, 'update']);
Route::delete('/research-topics/{id}', [ResearchTopicController::class, 'destroy']);
Route::post('/research-topics/{id}/toggle', [ResearchTopicController::class, 'toggle']);
Route::post('/research-results/{id}/approve', [ResearchTopicController::class, 'approveResult']);
Route::post('/research-results/{id}/skip', [ResearchTopicController::class, 'skipResult']);
Route::post('/research-results/{id}/restore', [ResearchTopicController::class, 'restoreResult']);
Route::get('/research-topics/skipped', [ResearchTopicController::class, 'skippedResults']);

// Research Sources - Dynamic source discovery
Route::get('/research/sources', [ResearchMissionController::class, 'sources']);
Route::post('/research/sources', [ResearchMissionController::class, 'addSource']);
Route::post('/research/sources/discover', [ResearchMissionController::class, 'discoverSources']);

// Unified Review Queue - Topic Results
Route::get('/research/review-queue', [ResearchMissionController::class, 'reviewQueue']);
Route::get('/research/review-queue/stats', [ResearchMissionController::class, 'reviewQueueStats']);

// Discovery Rules - Dynamic source management (v2.0)
Route::prefix('research/rules')->group(function () {
    Route::get('/', [ResearchMissionController::class, 'listRules']);
    Route::get('/options', [ResearchMissionController::class, 'ruleOptions']);
    Route::get('/{id}', [ResearchMissionController::class, 'showRule']);
    Route::post('/', [ResearchMissionController::class, 'createRule']);
    Route::put('/{id}', [ResearchMissionController::class, 'updateRule']);
    Route::delete('/{id}', [ResearchMissionController::class, 'deleteRule']);
});

// Source Optimization - Self-healing and improvement
Route::prefix('research/optimize')->group(function () {
    Route::post('/heal', [ResearchMissionController::class, 'runHealing']);
    Route::post('/rules', [ResearchMissionController::class, 'runOptimization']);
    Route::get('/health', [ResearchMissionController::class, 'healthReport']);
    Route::get('/engine-health', [ResearchMissionController::class, 'engineHealth']);
    Route::get('/suggest', [ResearchMissionController::class, 'sourceSuggestions']);
    Route::get('/category-health', [ResearchMissionController::class, 'categoryHealth']);
    Route::post('/maintenance', [ResearchMissionController::class, 'runMaintenance']);
    Route::post('/refresh/{category}', [ResearchMissionController::class, 'refreshCategory']);
});

// Source Feedback - Performance tracking
Route::post('/research/sources/{id}/feedback', [ResearchMissionController::class, 'recordSourceFeedback']);
Route::get('/research/sources/{id}/feedback', [ResearchMissionController::class, 'sourceFeedback']);

// =========================================================================
// Unified Research API (v2) - Consolidates Topics + Missions
// =========================================================================
Route::prefix('research/unified')->group(function () {
    // Research items (unified view of topics + missions)
    Route::get('/', [UnifiedResearchController::class, 'index']);
    Route::post('/', [UnifiedResearchController::class, 'store']);
    Route::get('/stats', [UnifiedResearchController::class, 'stats']);
    Route::get('/categories', [UnifiedResearchController::class, 'categories']);
    Route::get('/rag-categories', [UnifiedResearchController::class, 'ragCategories']);

    // Review queue (facts pending human approval) - MUST be before /{id} routes
    Route::get('/pending-facts', [UnifiedResearchController::class, 'pendingFacts']);
    Route::get('/rejected', [UnifiedResearchController::class, 'rejectedFacts']);
    Route::post('/unreject', [UnifiedResearchController::class, 'unrejectFact']);

    // Fact actions (before generic {id} to avoid conflicts)
    Route::post('/facts/{factId}/approve', [UnifiedResearchController::class, 'approveFact']);
    Route::post('/facts/{factId}/reject', [UnifiedResearchController::class, 'rejectFact']);

    // Research item CRUD (with {id} parameter)
    Route::get('/{id}', [UnifiedResearchController::class, 'show']);
    Route::put('/{id}', [UnifiedResearchController::class, 'update']);
    Route::delete('/{id}', [UnifiedResearchController::class, 'destroy']);
    Route::post('/{id}/toggle', [UnifiedResearchController::class, 'toggle']);
    Route::post('/{id}/run', [UnifiedResearchController::class, 'run']);
});

// Research Assistant - Unified Research Interface
Route::prefix('research-assistant')->group(function () {
    Route::post('/query', [ResearchAssistantController::class, 'query']);
    Route::get('/history', [ResearchAssistantController::class, 'history']);
    Route::get('/stats', [ResearchAssistantController::class, 'stats']);
    Route::get('/topics', [ResearchAssistantController::class, 'topics']);
    Route::post('/topics', [ResearchAssistantController::class, 'createTopic']);
    Route::get('/patterns', [ResearchAssistantController::class, 'patterns']);
    Route::post('/patterns/learn', [ResearchAssistantController::class, 'learnPattern']);
    Route::post('/save-to-rag', [ResearchAssistantController::class, 'saveToRag']);

    // Dynamic Authoritative Sources Management
    Route::get('/sources', [ResearchAssistantController::class, 'sources']);
    Route::post('/sources', [ResearchAssistantController::class, 'addSource']);
    Route::put('/sources/{id}', [ResearchAssistantController::class, 'updateSource']);
    Route::delete('/sources/{id}', [ResearchAssistantController::class, 'deleteSource']);
});

// =========================================================================
// Unified Review Center - All human approval queues in one place
// =========================================================================
Route::prefix('reviews')->group(function () {
    Route::get('/', [UnifiedReviewController::class, 'index']);
    Route::get('/stats', [UnifiedReviewController::class, 'stats']);
    Route::get('/quick/{unifiedId}', [UnifiedReviewController::class, 'quickAction'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::get('/{unifiedId}', [UnifiedReviewController::class, 'show'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/{unifiedId}/approve', [UnifiedReviewController::class, 'approve'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/{unifiedId}/reject', [UnifiedReviewController::class, 'reject'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/batch/approve', [UnifiedReviewController::class, 'batchApprove']);
    Route::post('/batch/reject', [UnifiedReviewController::class, 'batchReject']);
});

// =========================================================================
// Research Hub - Unified Reviews + Agent Status (pluggable registry)
// =========================================================================
Route::prefix('research-hub')->group(function () {
    // Review type registry
    Route::get('/types', [\App\Http\Controllers\Api\ResearchHubController::class, 'types']);
    Route::get('/stats', [\App\Http\Controllers\Api\ResearchHubController::class, 'stats']);
    Route::get('/items', [\App\Http\Controllers\Api\ResearchHubController::class, 'items']);

    // Phase 1 (Genealogy Review UI redesign): enriched detail-pane payload
    // Returns review item + on-file person dossier + per-field diffs in one call
    Route::get('/items/{unifiedId}/context', [\App\Http\Controllers\Api\ResearchHubController::class, 'context'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');

    // Phase 3: per-field accept/reject with structured reason codes and conflict resolutions.
    // genealogy_finding only — other types use whole-item approve/reject.
    Route::post('/items/{unifiedId}/apply-fields', [\App\Http\Controllers\Api\ResearchHubController::class, 'applyFields'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');

    // Individual item actions
    Route::post('/approve/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'approve'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/reject/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'reject'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/clarify/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'clarify'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/defer/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'defer'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    // Pushover action button callbacks (GET for mobile browser open)
    Route::get('/quick-approve/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'quickApprove'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::get('/quick-reject/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'quickReject'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::get('/quick-view/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'quickView'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/ignore/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'ignore'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/revive/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'revive'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');

    // Batch operations
    Route::post('/batch/approve', [\App\Http\Controllers\Api\ResearchHubController::class, 'batchApprove']);
    Route::post('/batch/reject', [\App\Http\Controllers\Api\ResearchHubController::class, 'batchReject']);

    // INF-10c: Remediation actions
    Route::get('/remediation/registry', [\App\Http\Controllers\Api\ResearchHubController::class, 'remediationRegistry']);
    Route::get('/remediation/{unifiedId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'getRemediation'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');
    Route::post('/remediation/{unifiedId}/execute', [\App\Http\Controllers\Api\ResearchHubController::class, 'executeRemediation'])->where('unifiedId', '[a-z_]+:[a-zA-Z0-9_-]+');

    // Agent status (merged from agent dashboard)
    Route::get('/agents/status', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentStatus']);
    Route::get('/agents/reviewer-feedback', [\App\Http\Controllers\Api\ResearchHubController::class, 'reviewerFeedback']);
    Route::get('/agents/activity', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentActivity']);
    Route::get('/agents/jobs', [\App\Http\Controllers\Api\ResearchHubController::class, 'scheduledJobs']);
    Route::get('/agents/reports', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentReports']);
    Route::get('/agents/handoffs', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentHandoffs']);
    Route::get('/agents/{agentId}/episodes', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentEpisodes']);

    // Procedural memory
    Route::get('/agents/procedures', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentProcedures']);
    Route::get('/agents/procedures/stats', [\App\Http\Controllers\Api\ResearchHubController::class, 'agentProcedureStats']);
    Route::post('/agents/procedures/{id}/retire', [\App\Http\Controllers\Api\ResearchHubController::class, 'retireProcedure']);
    Route::post('/agents/procedures/{id}/restore', [\App\Http\Controllers\Api\ResearchHubController::class, 'restoreProcedure']);

    // Speculative execution (S19)
    Route::get('/agents/speculative/stats', [\App\Http\Controllers\Api\ResearchHubController::class, 'speculativeStats']);
    Route::get('/agents/speculative/history', [\App\Http\Controllers\Api\ResearchHubController::class, 'speculativeHistory']);
    Route::get('/agents/speculative/{specRunId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'speculativeDetail']);
    Route::post('/agents/speculative/run', [\App\Http\Controllers\Api\ResearchHubController::class, 'speculativeRun']);
    Route::post('/agents/speculative/{specRunId}/cancel', [\App\Http\Controllers\Api\ResearchHubController::class, 'speculativeCancel']);

    // Adaptive mode selection (S20)
    Route::get('/agents/adaptive/stats', [\App\Http\Controllers\Api\ResearchHubController::class, 'adaptiveModeStats']);
    Route::get('/agents/adaptive/history/{agentId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'adaptiveModeHistory']);
    Route::get('/agents/adaptive/recommend/{agentId}', [\App\Http\Controllers\Api\ResearchHubController::class, 'adaptiveModeRecommend']);
});

// =========================================================================
// Internet Archive Integration
// =========================================================================
Route::prefix('internet-archive')->group(function () {
    Route::get('/search', [\App\Http\Controllers\Api\InternetArchiveController::class, 'search']);
    Route::get('/genealogy', [\App\Http\Controllers\Api\InternetArchiveController::class, 'searchGenealogy']);
    Route::get('/family', [\App\Http\Controllers\Api\InternetArchiveController::class, 'searchFamily']);
    Route::get('/item/{identifier}', [\App\Http\Controllers\Api\InternetArchiveController::class, 'item']);
    Route::get('/item/{identifier}/files', [\App\Http\Controllers\Api\InternetArchiveController::class, 'files']);
    Route::post('/download', [\App\Http\Controllers\Api\InternetArchiveController::class, 'download']);
    Route::post('/download-best', [\App\Http\Controllers\Api\InternetArchiveController::class, 'downloadBest']);
    Route::post('/copy-to-tree', [\App\Http\Controllers\Api\InternetArchiveController::class, 'copyToTree']);
});

// =========================================================================
// NARA (National Archives) Integration
// =========================================================================
Route::prefix('nara')->group(function () {
    Route::get('/search', [\App\Http\Controllers\Api\NaraController::class, 'search']);
    Route::get('/{naId}/objects', [\App\Http\Controllers\Api\NaraController::class, 'objects']);
    Route::post('/download', [\App\Http\Controllers\Api\NaraController::class, 'download']);
    Route::post('/download-best', [\App\Http\Controllers\Api\NaraController::class, 'downloadBest']);
    Route::post('/copy-to-tree', [\App\Http\Controllers\Api\NaraController::class, 'copyToTree']);
});

// Data Removal System - public for personal/family use
Route::prefix('data-removal')->group(function () {
    // Dashboard & Stats
    Route::get('/stats', [DataRemovalController::class, 'stats']);
    Route::get('/dashboard', [DataRemovalController::class, 'dashboard']);

    // Subjects (people to protect)
    Route::get('/subjects', [DataRemovalController::class, 'listSubjects']);
    Route::post('/subjects', [DataRemovalController::class, 'createSubject']);
    Route::get('/subjects/{id}', [DataRemovalController::class, 'showSubject']);
    Route::put('/subjects/{id}', [DataRemovalController::class, 'updateSubject']);
    Route::delete('/subjects/{id}', [DataRemovalController::class, 'deleteSubject']);

    // Brokers (data broker sites)
    Route::get('/brokers', [DataRemovalController::class, 'listBrokers']);
    Route::post('/brokers', [DataRemovalController::class, 'createBroker']);
    Route::get('/brokers/{id}', [DataRemovalController::class, 'showBroker']);
    Route::put('/brokers/{id}', [DataRemovalController::class, 'updateBroker']);
    Route::delete('/brokers/{id}', [DataRemovalController::class, 'deleteBroker']);

    // Removal Requests
    Route::get('/requests', [DataRemovalController::class, 'listRequests']);
    Route::get('/requests/{id}', [DataRemovalController::class, 'showRequest']);
    Route::post('/requests/{id}/submit', [DataRemovalController::class, 'submitRequest']);
    Route::get('/requests/{id}/activity', [DataRemovalController::class, 'getRequestActivity']);
    Route::get('/requests/{id}/fields', [DataRemovalController::class, 'getRequestAvailableFields']);
    Route::put('/requests/{id}/fields', [DataRemovalController::class, 'updateRequestFields']);

    // Review Queue
    Route::get('/review-queue', [DataRemovalController::class, 'reviewQueue']);
    Route::post('/requests/{id}/review', [DataRemovalController::class, 'reviewRequest']);

    // Manual Actions
    Route::post('/scan', [DataRemovalController::class, 'triggerScan']);
    Route::post('/requests/{id}/verify', [DataRemovalController::class, 'verifyRemoval']);

    // AI-Powered Broker Discovery
    Route::post('/research', [DataRemovalController::class, 'triggerResearch']);

    // Broker Discovery Queue (Human Approval)
    Route::get('/discovery/pending', [DataRemovalController::class, 'getPendingBrokers']);
    Route::get('/discovery/stats', [DataRemovalController::class, 'getDiscoveryStats']);
    Route::post('/discovery/{id}/approve', [DataRemovalController::class, 'approveBroker']);
    Route::post('/discovery/{id}/reject', [DataRemovalController::class, 'rejectBroker']);

    // Security Status
    Route::get('/security/status', [DataRemovalController::class, 'getPuppeteerSecurityStatus']);

    // Analytics & Monitoring
    Route::get('/analytics', [DataRemovalController::class, 'removalAnalytics']);
    Route::get('/relistings', [DataRemovalController::class, 'relistings']);
    Route::post('/brokers/{id}/health-check', [DataRemovalController::class, 'healthCheck']);
    Route::post('/tools/sync-badbool', [DataRemovalController::class, 'syncBadbool']);
});

// Browser Extension API - for Firefox Data Removal Assistant
Route::prefix('extension')->group(function () {
    Route::get('/tasks', [ExtensionController::class, 'getTasks']);
    Route::get('/tasks/{id}', [ExtensionController::class, 'getTaskDetails']);
    Route::post('/tasks/{id}/complete', [ExtensionController::class, 'completeTask']);
    Route::post('/tasks/{id}/skip', [ExtensionController::class, 'skipTask']);
    Route::put('/tasks/{id}/fields', [ExtensionController::class, 'updateTaskFields']);
    Route::post('/ai-help', [ExtensionController::class, 'getAIHelp']);
    Route::post('/form-fields', [ExtensionController::class, 'reportFormFields']);

    // Site configuration endpoints - dynamic config from database
    Route::get('/site-config', [ExtensionController::class, 'getSiteConfig']);
    Route::put('/site-config/{brokerId}', [ExtensionController::class, 'updateSiteConfig']);

    // Page content extraction (Cloudflare bypass)
    Route::post('/page-content', [ExtensionController::class, 'indexPageContent']);

    // Genealogy: Cookie sharing, clipping, browse queue
    Route::prefix('genealogy')->group(function () {
        Route::post('/cookies', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'storeCookies']);
        Route::get('/cookies', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'getCookies']);
        Route::post('/clips', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'saveClip']);
        Route::get('/browse-queue', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'getBrowseQueue']);
        Route::post('/browse-queue', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'createBrowseQueue']);
        Route::post('/browse-queue/{id}/result', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'submitBrowseResult']);
        Route::get('/browse-queue/stats', [\App\Http\Controllers\Api\ExtensionGenealogyController::class, 'browseQueueStats']);
    });
});

// System Issues - AI ops pending issues management
Route::prefix('system-issues')->group(function () {
    Route::get('/pending', [SystemIssuesController::class, 'getPending']);
    Route::get('/', [SystemIssuesController::class, 'getAll']);
    Route::get('/{id}', [SystemIssuesController::class, 'show']);
    Route::post('/{id}/resolve', [SystemIssuesController::class, 'resolve']);
    Route::post('/{id}/dismiss', [SystemIssuesController::class, 'dismiss']);
    Route::post('/{id}/reopen', [SystemIssuesController::class, 'reopen']);
    Route::post('/{id}/run-fix', [SystemIssuesController::class, 'runFix']);
});

// File Catalog - Read-only file browsing, search, and RAG sync
Route::prefix('file-catalog')->group(function () {
    // Dashboard & Stats
    Route::get('/dashboard', [\App\Http\Controllers\Api\FileCatalogController::class, 'dashboard']);
    Route::get('/stats', [\App\Http\Controllers\Api\FileCatalogController::class, 'stats']);

    // File Browsing
    Route::get('/files', [\App\Http\Controllers\Api\FileCatalogController::class, 'listFiles']);
    Route::get('/files/{uuid}', [\App\Http\Controllers\Api\FileCatalogController::class, 'getFile']);
    Route::get('/files/{uuid}/download', [\App\Http\Controllers\Api\FileCatalogController::class, 'downloadUrl']);
    Route::get('/files/{uuid}/archive', [\App\Http\Controllers\Api\FileCatalogController::class, 'listArchiveContents']);
    Route::get('/files/{uuid}/preview', [\App\Http\Controllers\Api\FileCatalogController::class, 'previewFile']);

    // Scanning
    Route::post('/scan', [\App\Http\Controllers\Api\FileCatalogController::class, 'triggerScan']);
    Route::get('/scan/history', [\App\Http\Controllers\Api\FileCatalogController::class, 'scanHistory']);
    Route::post('/scan/cleanup', [\App\Http\Controllers\Api\FileCatalogController::class, 'cleanupStuck']);

    // RAG Sync
    Route::get('/rag/status', [\App\Http\Controllers\Api\FileCatalogController::class, 'ragStatus']);
    Route::post('/rag/sync', [\App\Http\Controllers\Api\FileCatalogController::class, 'ragSync']);
    Route::get('/rag/search', [\App\Http\Controllers\Api\FileCatalogController::class, 'ragSearch']);

    // Duplicates (read-only)
    Route::get('/duplicates', [\App\Http\Controllers\Api\FileCatalogController::class, 'listDuplicates']);
    Route::get('/duplicates/stats', [\App\Http\Controllers\Api\FileCatalogController::class, 'duplicateStats']);

    // Thumbnails
    Route::get('/files/{uuid}/thumbnail/{size?}', [\App\Http\Controllers\Api\FileCatalogController::class, 'thumbnail']);
    Route::post('/thumbnails/generate', [\App\Http\Controllers\Api\FileCatalogController::class, 'generateThumbnails']);
    Route::get('/thumbnails/stats', [\App\Http\Controllers\Api\FileCatalogController::class, 'thumbnailStats']);

    // Quarantine
    Route::get('/quarantine', [\App\Http\Controllers\Api\FileCatalogController::class, 'listQuarantined']);
    Route::post('/quarantine/{fileId}', [\App\Http\Controllers\Api\FileCatalogController::class, 'quarantineFile']);
    Route::post('/quarantine/{id}/review', [\App\Http\Controllers\Api\FileCatalogController::class, 'reviewQuarantined']);

    // Bundles
    Route::get('/bundles', [\App\Http\Controllers\Api\FileCatalogController::class, 'listBundles']);
    Route::get('/bundles/{id}', [\App\Http\Controllers\Api\FileCatalogController::class, 'getBundle']);
    Route::post('/bundles/detect', [\App\Http\Controllers\Api\FileCatalogController::class, 'detectBundles']);

    // Collections
    Route::get('/collections', [\App\Http\Controllers\Api\FileCatalogController::class, 'listCollections']);
    Route::post('/collections', [\App\Http\Controllers\Api\FileCatalogController::class, 'createCollection']);
    Route::get('/collections/{id}/items', [\App\Http\Controllers\Api\FileCatalogController::class, 'collectionItems']);
    Route::post('/collections/{id}/evaluate', [\App\Http\Controllers\Api\FileCatalogController::class, 'evaluateCollection']);
    Route::delete('/collections/{id}', [\App\Http\Controllers\Api\FileCatalogController::class, 'deleteCollection']);

    // Versions
    Route::get('/files/{uuid}/versions', [\App\Http\Controllers\Api\FileCatalogController::class, 'fileVersions']);

    // Semantic Search
    Route::get('/semantic-search', [\App\Http\Controllers\Api\FileCatalogController::class, 'semanticSearch']);
    Route::post('/files/{uuid}/describe', [\App\Http\Controllers\Api\FileCatalogController::class, 'generateDescription']);
});

// RAG Query Tracing
Route::get('/rag/traces/recent', [RAGController::class, 'recentTraces']);

// Developer Tools - diagnostics only
Route::get('/dev-tools/diagnostics', [DevToolsController::class, 'getDiagnostics']);

// System Diagnostics - read only
Route::get('/diagnostics/health', [DevToolsController::class, 'getSystemHealth']);
Route::get('/diagnostics/errors', [DevToolsController::class, 'getErrorStatistics']);
Route::get('/diagnostics/workflows', [DevToolsController::class, 'getWorkflowDiagnostics']);
Route::get('/diagnostics/alerts', [DevToolsController::class, 'getActiveAlerts']);
Route::get('/diagnostics/backups', [DevToolsController::class, 'getBackupStatus']);
Route::get('/diagnostics/services', [DevToolsController::class, 'getServicesStatus']);

// Queue - read only
Route::get('/queue/failed', [QueueController::class, 'getFailedJobs']);
Route::get('/queue/stats', [QueueController::class, 'getStats']);

// Scheduled Jobs - Centralized job scheduling
Route::prefix('scheduled-jobs')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ScheduledJobController::class, 'index']);
    Route::get('/stats', [\App\Http\Controllers\Api\ScheduledJobController::class, 'stats']);
    Route::get('/modules', [\App\Http\Controllers\Api\ScheduledJobController::class, 'modules']);
    Route::get('/by-module', [\App\Http\Controllers\Api\ScheduledJobController::class, 'byModule']);
    Route::post('/validate-cron', [\App\Http\Controllers\Api\ScheduledJobController::class, 'validateCron']);
    Route::get('/{id}', [\App\Http\Controllers\Api\ScheduledJobController::class, 'show']);
    Route::get('/{id}/history', [\App\Http\Controllers\Api\ScheduledJobController::class, 'history']);
    Route::post('/', [\App\Http\Controllers\Api\ScheduledJobController::class, 'store']);
    Route::put('/{id}', [\App\Http\Controllers\Api\ScheduledJobController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\ScheduledJobController::class, 'destroy']);
    Route::post('/{id}/toggle', [\App\Http\Controllers\Api\ScheduledJobController::class, 'toggle']);
    Route::post('/{id}/run', [\App\Http\Controllers\Api\ScheduledJobController::class, 'run']);
    Route::post('/cleanup-history', [\App\Http\Controllers\Api\ScheduledJobController::class, 'cleanupHistory']);
});

// OAuth - read only
Route::get('/oauth/tokens', [OAuthController::class, 'getTokens']);

// Configuration - public for personal/family use
Route::get('/configuration', [ConfigurationController::class, 'index']);
Route::get('/configuration/section/{section}', [ConfigurationController::class, 'getBySection']);
Route::post('/configuration/initialize', [ConfigurationController::class, 'initializeDefaults']);
Route::post('/configuration/update-multiple', [ConfigurationController::class, 'updateMultiple']);
Route::put('/configuration/{id}', [ConfigurationController::class, 'update']);
Route::post('/configuration', [ConfigurationController::class, 'store']);
Route::delete('/configuration/{id}', [ConfigurationController::class, 'destroy']);

// Protected routes - require authentication for modifications
Route::middleware('auth:web')->group(function () {
    // Auth routes
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Executions - modifications
    Route::post('/executions/{id}/retry', [ExecutionController::class, 'retry']);

    // System Diagnostics - actions
    Route::post('/diagnostics/alerts/run-checks', [DevToolsController::class, 'runAlertChecks']);
    Route::post('/diagnostics/alerts/{id}/acknowledge', [DevToolsController::class, 'acknowledgeAlert']);
    Route::post('/diagnostics/alerts/{id}/resolve', [DevToolsController::class, 'resolveAlert']);
    Route::post('/diagnostics/health/snapshot', [DevToolsController::class, 'takeHealthSnapshot']);

    // Queue - modifications
    Route::post('/queue/retry/{id}', [QueueController::class, 'retryJob']);
    Route::delete('/queue/failed/{id}', [QueueController::class, 'deleteJob']);

    // OAuth - modifications
    Route::post('/oauth/tokens', [OAuthController::class, 'createToken']);
    Route::delete('/oauth/tokens/{id}', [OAuthController::class, 'revokeToken']);

    // RAG - modifications
    Route::post('/rag/index', [RAGController::class, 'index']);
    Route::get('/rag/similar/{id}', [RAGController::class, 'similar']);
    Route::put('/rag/documents/{id}', [RAGController::class, 'update']);
    Route::delete('/rag/documents/{id}', [RAGController::class, 'destroy']);
    Route::post('/rag/bulk-delete', [RAGController::class, 'bulkDelete']);
    Route::post('/rag/documents/{id}/reindex', [RAGController::class, 'reindex']);

    // Knowledge Graph API
    Route::get('/rag/knowledge-graph/stats', [RAGController::class, 'graphStats']);
    Route::get('/rag/knowledge-graph/entities/search', [RAGController::class, 'searchEntities']);
    Route::get('/rag/knowledge-graph/graph/{entity}', [RAGController::class, 'getEntityGraph']);
    Route::get('/rag/knowledge-graph/relationships/{entity}', [RAGController::class, 'findRelationships']);
    Route::post('/rag/knowledge-graph/extract', [RAGController::class, 'extractEntities']);
    Route::post('/rag/knowledge-graph/triple', [RAGController::class, 'addTriple']);
    Route::post('/rag/knowledge-graph/merge', [RAGController::class, 'mergeEntities']);
    Route::get('/rag/knowledge-graph/search-relationship', [RAGController::class, 'searchByRelationship']);
    Route::delete('/rag/knowledge-graph/triple/{id}', [RAGController::class, 'deleteTriple']);
    Route::delete('/rag/knowledge-graph/entity/{id}', [RAGController::class, 'deleteEntity']);

    // MCP - test tool (requires auth in production)
    Route::post('/mcp/servers/{server}/test/{tool}', [MCPController::class, 'testTool']);
});

// E20: Genealogy - Family Tree Management
Route::prefix('genealogy')->middleware(['genealogy.privacy'])->group(function () {
    // Status
    Route::get('/status', [GenealogyController::class, 'getStatus']);

    // Trees
    Route::get('/trees', [GenealogyController::class, 'listTrees']);
    Route::post('/trees', [GenealogyController::class, 'createTree']);
    Route::get('/trees/{id}', [GenealogyController::class, 'getTree']);
    Route::put('/trees/{id}', [GenealogyController::class, 'updateTree']);
    Route::delete('/trees/{id}', [GenealogyController::class, 'deleteTree']);
    Route::get('/trees/{id}/recent', [GenealogyController::class, 'getRecentAdditions']);

    // GEDCOM Import/Export
    Route::post('/import/gedcom', [GenealogyController::class, 'importGedcom']);
    Route::get('/trees/{treeId}/export/gedcom', [GenealogyController::class, 'exportGedcom']);
    Route::get('/trees/{treeId}/export/gedzip', [GenealogyController::class, 'exportGedZip']);
    Route::post('/trees/{treeId}/import/media', [GenealogyController::class, 'importMedia']);

    // Persons
    Route::get('/trees/{treeId}/persons', [GenealogyController::class, 'listPersons']);
    Route::get('/trees/{treeId}/persons/search', [GenealogyController::class, 'searchPersons']);
    Route::get('/trees/{treeId}/tree-data', [GenealogyController::class, 'getTreeData']);
    Route::get('/trees/{treeId}/surnames', [GenealogyController::class, 'getSurnames']);
    Route::get('/trees/{treeId}/surnames/{surname}', [GenealogyController::class, 'getPersonsBySurname']);
    Route::post('/trees/{treeId}/persons', [GenealogyController::class, 'createPerson']);
    Route::get('/persons/{id}', [GenealogyController::class, 'getPerson']);
    Route::put('/persons/{id}', [GenealogyController::class, 'updatePerson']);
    Route::delete('/persons/{id}', [GenealogyController::class, 'deletePerson']);
    Route::post('/persons/{personId}/primary-photo', [GenealogyController::class, 'setPersonPrimaryPhoto']);

    // Families
    Route::get('/trees/{treeId}/families', [GenealogyController::class, 'getFamilies']);
    Route::post('/trees/{treeId}/families', [GenealogyController::class, 'createFamily']);
    Route::get('/families/{id}', [GenealogyController::class, 'getFamily']);
    Route::put('/families/{id}', [GenealogyController::class, 'updateFamily']);
    Route::delete('/families/{id}', [GenealogyController::class, 'deleteFamily']);
    Route::post('/families/{familyId}/children', [GenealogyController::class, 'addChild']);
    Route::delete('/families/{familyId}/children/{personId}', [GenealogyController::class, 'removeChild']);

    // Events (Phase 2.1 - GEDCOM Life Events)
    Route::get('/event-types', [GenealogyController::class, 'getEventTypes']);
    Route::get('/persons/{personId}/events', [GenealogyController::class, 'getPersonEvents']);
    Route::post('/persons/{personId}/events', [GenealogyController::class, 'createEvent']);
    Route::get('/events/{id}', [GenealogyController::class, 'getEvent']);
    Route::put('/events/{id}', [GenealogyController::class, 'updateEvent']);
    Route::delete('/events/{id}', [GenealogyController::class, 'deleteEvent']);

    // Family Events (Phase 2.3 - GEDCOM Family Events)
    Route::get('/family-event-types', [GenealogyController::class, 'getFamilyEventTypes']);
    Route::get('/families/{familyId}/events', [GenealogyController::class, 'getFamilyEvents']);
    Route::post('/families/{familyId}/events', [GenealogyController::class, 'createFamilyEvent']);
    Route::get('/family-events/{id}', [GenealogyController::class, 'getFamilyEvent']);
    Route::put('/family-events/{id}', [GenealogyController::class, 'updateFamilyEvent']);
    Route::delete('/family-events/{id}', [GenealogyController::class, 'deleteFamilyEvent']);

    // Residences (GEDCOM RESI events)
    Route::get('/persons/{personId}/residences', [GenealogyController::class, 'getPersonResidences']);
    Route::post('/persons/{personId}/residences', [GenealogyController::class, 'createResidence']);
    Route::get('/residences/{id}', [GenealogyController::class, 'getResidence']);
    Route::put('/residences/{id}', [GenealogyController::class, 'updateResidence']);
    Route::delete('/residences/{id}', [GenealogyController::class, 'deleteResidence']);

    // Person Media (CRUD for person-media links)
    Route::get('/persons/{personId}/media', [GenealogyController::class, 'getPersonMedia']);

    // Person Sources (link sources to persons)
    Route::get('/persons/{personId}/sources', [GenealogyController::class, 'getPersonSources']);
    Route::post('/persons/{personId}/sources', [GenealogyController::class, 'linkPersonSource']);
    Route::delete('/persons/{personId}/sources/{sourceId}', [GenealogyController::class, 'unlinkPersonSource']);

    // Sources (Phase 2.4 - GEDCOM Source Management)
    Route::get('/trees/{treeId}/sources', [GenealogyController::class, 'getSources']);
    Route::get('/trees/{treeId}/sources/search', [GenealogyController::class, 'searchSources']);
    Route::post('/trees/{treeId}/sources', [GenealogyController::class, 'createSource']);
    Route::get('/sources/{id}', [GenealogyController::class, 'getSource']);
    Route::put('/sources/{id}', [GenealogyController::class, 'updateSource']);
    Route::delete('/sources/{id}', [GenealogyController::class, 'deleteSource']);

    // Citations (Phase 2.5)
    Route::get('/citation-fact-types', [GenealogyController::class, 'getCitationFactTypes']);
    Route::get('/citation-quality-levels', [GenealogyController::class, 'getCitationQualityLevels']);
    Route::get('/persons/{personId}/citations', [GenealogyController::class, 'getPersonCitations']);
    Route::get('/families/{familyId}/citations', [GenealogyController::class, 'getFamilyCitations']);
    Route::get('/sources/{sourceId}/citations', [GenealogyController::class, 'getSourceCitations']);
    Route::post('/citations', [GenealogyController::class, 'createCitation']);
    Route::get('/citations/{id}', [GenealogyController::class, 'getCitation']);
    Route::put('/citations/{id}', [GenealogyController::class, 'updateCitation']);
    Route::delete('/citations/{id}', [GenealogyController::class, 'deleteCitation']);

    // Repositories (Phase 2.6)
    Route::get('/trees/{treeId}/repositories', [GenealogyController::class, 'getRepositories']);
    Route::get('/trees/{treeId}/repositories/search', [GenealogyController::class, 'searchRepositories']);
    Route::post('/trees/{treeId}/repositories', [GenealogyController::class, 'createRepository']);
    Route::get('/repositories/{id}', [GenealogyController::class, 'getRepository']);
    Route::put('/repositories/{id}', [GenealogyController::class, 'updateRepository']);
    Route::delete('/repositories/{id}', [GenealogyController::class, 'deleteRepository']);

    // Reports (Phase 2.7)
    Route::get('/missing-data-types', [GenealogyController::class, 'getMissingDataTypes']);
    Route::get('/trees/{treeId}/reports/missing-data', [GenealogyController::class, 'getMissingDataReport']);
    Route::get('/trees/{treeId}/reports/missing-data/summary', [GenealogyController::class, 'getMissingDataSummary']);

    // Intake Runs
    Route::get('/intake-runs', [GenealogyController::class, 'listIntakeRuns']);
    Route::post('/intake-runs/stage', [GenealogyController::class, 'stageIntakeRun']);
    Route::get('/intake-runs/{runKey}', [GenealogyController::class, 'getIntakeRun']);
    Route::post('/intake-runs/{runKey}/review-decision', [GenealogyController::class, 'recordIntakeRunReviewDecision']);
    Route::get('/intake-runs/{runKey}/proposal-queue', [GenealogyController::class, 'getIntakeRunProposalQueue']);
    Route::get('/intake-runs/{runKey}/proposal-draft', [GenealogyController::class, 'getIntakeRunProposalDraft']);
    Route::get('/intake-runs/{runKey}/workspace', [GenealogyController::class, 'getIntakeRunWorkspace']);
    Route::post('/intake-runs/{runKey}/approval-draft-preview', [GenealogyController::class, 'previewIntakeRunApprovalDraft']);
    Route::post('/intake-runs/{runKey}/proposal-generate', [GenealogyController::class, 'generateIntakeRunProposals']);
    Route::get('/intake-runs/{runKey}/generated-proposals', [GenealogyController::class, 'getIntakeRunGeneratedProposals']);
    Route::post('/intake-runs/{runKey}/approval-draft-apply', [GenealogyController::class, 'applyIntakeRunApprovalDraft']);

    // Media
    Route::get('/trees/{treeId}/media', [GenealogyController::class, 'getTreeMedia']);
    Route::post('/trees/{treeId}/media', [GenealogyController::class, 'uploadMedia']);
    Route::get('/trees/{treeId}/media/status', [GenealogyController::class, 'getMediaImportStatus']);
    Route::post('/trees/{treeId}/media/sync-paths', [GenealogyController::class, 'syncMediaPaths']);
    Route::post('/trees/{treeId}/media/import', [GenealogyController::class, 'importTreeMedia']);

    // Genealogy-specific media routes (numeric IDs from genealogy_media table)
    Route::get('/media/{id}', [GenealogyController::class, 'getMedia'])->where('id', '[0-9]+');
    Route::get('/media/{id}/intake-preview', [GenealogyController::class, 'previewMediaIntake'])->where('id', '[0-9]+');
    Route::get('/media/{id}/thumbnail', [GenealogyController::class, 'getMediaThumbnail'])->where('id', '[0-9]+');
    Route::put('/media/{id}/type', [GenealogyController::class, 'updateMediaType'])->where('id', '[0-9]+'); // Phase 3.6
    Route::put('/media/{id}/transcription', [GenealogyController::class, 'updateMediaTranscription'])->where('id', '[0-9]+'); // Phase 3.7
    Route::get('/trees/{treeId}/media/transcription-queue', [GenealogyController::class, 'getMediaNeedingTranscription']); // Phase 3.7
    Route::get('/trees/{treeId}/media/windows-paths', [GenealogyController::class, 'getWindowsMediaPaths']); // Phase 3.8
    Route::post('/trees/{treeId}/media/scp-commands', [GenealogyController::class, 'generateScpCommands']); // Phase 3.8
    Route::delete('/media/{id}', [GenealogyController::class, 'deleteMedia'])->where('id', '[0-9]+');
    Route::post('/media/{mediaId}/persons', [GenealogyController::class, 'linkPersonToMedia']);
    Route::delete('/media/{mediaId}/persons/{personId}', [GenealogyController::class, 'unlinkPersonFromMedia']);
    Route::post('/media/{mediaId}/families', [GenealogyController::class, 'linkFamilyToMedia']);
    Route::delete('/media/{mediaId}/families/{familyId}', [GenealogyController::class, 'unlinkFamilyFromMedia']);

    // Face Confirmation (Phase 3.5)
    Route::post('/media/{mediaId}/persons/{personId}/confirm', [GenealogyController::class, 'confirmFaceTag']);
    Route::get('/trees/{treeId}/faces/unconfirmed', [GenealogyController::class, 'getUnconfirmedFaces']);

    // AI Media Analysis
    Route::post('/media/{mediaId}/analyze', [GenealogyController::class, 'analyzeMedia']);
    Route::post('/trees/{treeId}/media/analyze', [GenealogyController::class, 'analyzeTreeMedia']);
    Route::get('/trees/{treeId}/media/analysis-status', [GenealogyController::class, 'getAnalysisStatus']);
    Route::post('/media/{mediaId}/reset-analysis', [GenealogyController::class, 'resetAnalysisStatus']);
    Route::post('/trees/{treeId}/media/reset-failed-analyses', [GenealogyController::class, 'resetFailedAnalyses']);

    // Nextcloud Folder Scanner
    Route::post('/trees/{treeId}/media/scan-nextcloud', [GenealogyController::class, 'scanNextcloudFolder']);
    Route::post('/trees/{treeId}/media/scan-nextcloud-faces', [GenealogyController::class, 'scanNextcloudFolderWithFaces']);
    Route::get('/nextcloud-folders', [GenealogyController::class, 'listNextcloudFolders']);

    // Background Face Scan (long-running job with progress reporting)
    Route::post('/trees/{treeId}/media/face-scan-background', [GenealogyController::class, 'startBackgroundFaceScan']);
    Route::get('/trees/{treeId}/media/face-scan-status', [GenealogyController::class, 'getFaceScanStatus']);

    // Media Cleanup (unlinked media without face data or person matches)
    Route::post('/trees/{treeId}/media/cleanup-unlinked', [GenealogyController::class, 'cleanupUnlinkedMedia']);

    // Face Region Operations (E23)
    Route::post('/trees/{treeId}/media/rescan-faces', [GenealogyController::class, 'rescanFaceRegions']);
    Route::post('/media/{mediaId}/faces', [GenealogyController::class, 'addFaceRegion']);
    Route::put('/media/{mediaId}/faces/{personId}', [GenealogyController::class, 'updateFaceRegion']);
    Route::delete('/media/{mediaId}/faces/{personId}', [GenealogyController::class, 'removeFaceRegion']);
    Route::post('/media/{mediaId}/faces/write-back', [GenealogyController::class, 'writeFaceRegionsToFile']);

    // Face Match Approval Queue (Sprint 2 - fuzzy match review)
    Route::get('/trees/{treeId}/face-match-queue', [GenealogyController::class, 'getFaceMatchQueue']);
    Route::get('/trees/{treeId}/face-match-queue/stats', [GenealogyController::class, 'getFaceMatchQueueStats']);
    Route::post('/face-match-queue/{id}/approve', [GenealogyController::class, 'approveFaceMatch']);
    Route::post('/face-match-queue/{id}/reject', [GenealogyController::class, 'rejectFaceMatch']);
    Route::post('/face-match-queue/{id}/link', [GenealogyController::class, 'linkFaceMatchToPerson']);
    Route::post('/face-match-queue/{id}/rename', [GenealogyController::class, 'renameFaceMatch']);

    // Phase 4: Export, Backup & Data Integrity
    Route::get('/trees/{treeId}/export/gedcom', [GenealogyController::class, 'exportGedcom']);
    Route::get('/trees/{treeId}/validate', [GenealogyController::class, 'validateTree']);
    Route::get('/trees/{treeId}/statistics', [GenealogyController::class, 'getTreeStatistics']);
    Route::get('/trees/{treeId}/backup-status', [GenealogyController::class, 'getBackupStatus']);

    // Phase 4.3-4.4: Duplicate Detection & Merge
    Route::get('/trees/{treeId}/duplicates', [GenealogyController::class, 'findDuplicates']);
    Route::get('/trees/{treeId}/duplicates/stats', [GenealogyController::class, 'getDuplicateStats']);
    Route::post('/trees/{treeId}/duplicates/resolve', [GenealogyController::class, 'resolveDuplicate']);
    Route::post('/trees/{treeId}/persons/merge', [GenealogyController::class, 'mergePersons']);

    // Phase 5: Advanced Visualization & Analysis
    Route::get('/persons/{personId}/timeline', [GenealogyController::class, 'getPersonTimeline']);
    Route::get('/trees/{treeId}/timeline', [GenealogyController::class, 'getTreeTimeline']); // Priority 4.2
    Route::get('/timeline/{personId}', [GenealogyController::class, 'getPersonTimelineExtended']); // Extended timeline with options
    Route::post('/trees/{treeId}/relationship', [GenealogyController::class, 'calculateRelationship']);

    // Place Authority
    Route::get('/places/search', [GenealogyController::class, 'searchPlaces']);
    Route::get('/places/{placeId}', [GenealogyController::class, 'getPlace']);
    Route::post('/places/normalize', [GenealogyController::class, 'normalizePlaces']);

    // Find A Grave Integration (Priority 4.4)
    Route::get('/findagrave/search', [GenealogyController::class, 'searchFindAGrave']);
    Route::get('/findagrave/memorial/{memorialId}', [GenealogyController::class, 'getFindAGraveMemorial']);
    Route::get('/trees/{treeId}/places', [GenealogyController::class, 'getGeographicDistribution']);

    // Phase 6: Reports & Printing (JSON data)
    Route::get('/families/{familyId}/group-sheet', [GenealogyController::class, 'getFamilyGroupSheet']);
    Route::get('/persons/{personId}/pedigree', [GenealogyController::class, 'getPedigreeChart']);
    Route::get('/persons/{personId}/descendants', [GenealogyController::class, 'getDescendantReport']);
    Route::get('/trees/{treeId}/missing-data', [GenealogyController::class, 'getMissingDataReport']);
    Route::get('/persons/{personId}/summary', [GenealogyController::class, 'getIndividualSummary']);
    Route::get('/persons/{personId}/ahnentafel', [GenealogyController::class, 'getAhnentafelReport']);

    // Phase 6: PDF Reports (TCPDF)
    Route::get('/families/{familyId}/group-sheet/pdf', [GenealogyController::class, 'getFamilyGroupSheetPdf']);
    Route::get('/persons/{personId}/pedigree/pdf', [GenealogyController::class, 'getPedigreeChartPdf']);
    Route::get('/persons/{personId}/descendants/pdf', [GenealogyController::class, 'getDescendantReportPdf']);
    Route::get('/persons/{personId}/ahnentafel/pdf', [GenealogyController::class, 'getAhnentafelReportPdf']);
    Route::get('/persons/{personId}/summary/pdf', [GenealogyController::class, 'getIndividualSummaryPdf']);

    // Phase 7: Privacy & Collaboration
    Route::get('/trees/{treeId}/privacy', [GenealogyController::class, 'getTreePrivacySettings']);
    Route::put('/trees/{treeId}/privacy', [GenealogyController::class, 'updateTreePrivacySettings']);
    Route::post('/trees/{treeId}/auto-detect-living', [GenealogyController::class, 'autoDetectLivingPersons']);
    Route::get('/trees/{treeId}/living-statistics', [GenealogyController::class, 'getLivingStatistics']);
    Route::put('/persons/{personId}/privacy', [GenealogyController::class, 'updatePersonPrivacy']);
    Route::put('/media/{mediaId}/privacy', [GenealogyController::class, 'updateMediaPrivacySettings']);
    Route::get('/trees/{treeId}/collaborators', [GenealogyController::class, 'getCollaborators']);
    Route::post('/trees/{treeId}/collaborators', [GenealogyController::class, 'addCollaborator']);
    Route::put('/collaborators/{collaboratorId}', [GenealogyController::class, 'updateCollaborator']);
    Route::delete('/collaborators/{collaboratorId}', [GenealogyController::class, 'removeCollaborator']);
    Route::get('/trees/{treeId}/invitations', [GenealogyController::class, 'getPendingInvitations']);
    Route::post('/trees/{treeId}/invitations', [GenealogyController::class, 'createInvitation']);
    Route::post('/invitations/accept', [GenealogyController::class, 'acceptInvitation']);
    Route::delete('/invitations/{invitationId}', [GenealogyController::class, 'cancelInvitation']);
    Route::get('/trees/{treeId}/permissions', [GenealogyController::class, 'getUserPermissions']);
    Route::get('/trees/{treeId}/activity-log', [GenealogyController::class, 'getActivityLog']);

    // Phase 8: AI-Assisted Research
    Route::get('/trees/{treeId}/research-hints', [GenealogyController::class, 'getResearchHints']);
    Route::post('/trees/{treeId}/research-hints/generate', [GenealogyController::class, 'generateResearchHints']);
    Route::put('/research-hints/{hintId}', [GenealogyController::class, 'updateResearchHintStatus']);
    Route::post('/trees/{treeId}/record-hints/generate', [GenealogyController::class, 'generateRecordHintsForTree']);
    Route::post('/persons/{personId}/record-hints/generate', [GenealogyController::class, 'generatePersonRecordHints']);
    Route::get('/trees/{treeId}/name-variations', [GenealogyController::class, 'getNameVariations']);
    Route::post('/trees/{treeId}/name-variations', [GenealogyController::class, 'addNameVariation']);
    Route::delete('/name-variations/{variationId}', [GenealogyController::class, 'deleteNameVariation']);
    Route::post('/trees/{treeId}/name-suggestions', [GenealogyController::class, 'generateNameSuggestions']);
    Route::get('/trees/{treeId}/research-tasks', [GenealogyController::class, 'getResearchTasks']);
    Route::post('/trees/{treeId}/research-tasks', [GenealogyController::class, 'createResearchTask']);
    Route::put('/research-tasks/{taskId}', [GenealogyController::class, 'updateResearchTask']);
    Route::get('/persons/{personId}/smart-matches', [GenealogyController::class, 'getSmartMatches']);
    Route::put('/smart-matches/{matchId}', [GenealogyController::class, 'updateSmartMatchStatus']);
    Route::get('/trees/{treeId}/research-statistics', [GenealogyController::class, 'getResearchStatistics']);
    Route::post('/persons/{personId}/analyze-hints', [GenealogyController::class, 'analyzePersonForHints']);

    // Phase 9: External Integrations
    Route::get('/external-services', [GenealogyController::class, 'getSupportedExternalServices']);
    Route::get('/trees/{treeId}/external-connections', [GenealogyController::class, 'getExternalConnections']);
    Route::post('/trees/{treeId}/external-connections', [GenealogyController::class, 'saveExternalConnection']);
    Route::get('/external-connections/{connectionId}', [GenealogyController::class, 'getExternalConnection']);
    Route::delete('/external-connections/{connectionId}', [GenealogyController::class, 'deleteExternalConnection']);
    Route::put('/external-connections/{connectionId}/status', [GenealogyController::class, 'updateConnectionStatus']);
    Route::get('/trees/{treeId}/external-records', [GenealogyController::class, 'getExternalRecords']);
    Route::post('/trees/{treeId}/external-records', [GenealogyController::class, 'saveExternalRecord']);
    Route::put('/external-records/{recordId}/status', [GenealogyController::class, 'updateExternalRecordStatus']);
    Route::get('/persons/{personId}/external-links', [GenealogyController::class, 'getPersonExternalLinks']);
    Route::post('/persons/{personId}/external-links', [GenealogyController::class, 'linkPersonToExternalService']);
    Route::delete('/persons/{personId}/external-links/{serviceType}', [GenealogyController::class, 'unlinkPersonFromExternalService']);
    Route::post('/external-connections/{connectionId}/sync', [GenealogyController::class, 'startExternalSync']);
    Route::put('/external-syncs/{syncId}', [GenealogyController::class, 'updateExternalSync']);
    Route::get('/external-connections/{connectionId}/sync-history', [GenealogyController::class, 'getSyncHistory']);
    Route::get('/trees/{treeId}/external-integration-stats', [GenealogyController::class, 'getExternalIntegrationStats']);

    // Phase 9.5: Provider Integration Framework
    Route::get('/providers/status', [GenealogyController::class, 'getProvidersStatus']);
    Route::get('/providers/active', [GenealogyController::class, 'getActiveProviders']);
    Route::get('/providers/{providerId}', [GenealogyController::class, 'getProviderInfo']);
    Route::get('/providers/{providerId}/auth-url', [GenealogyController::class, 'getProviderAuthUrl']);
    Route::post('/providers/{providerId}/callback', [GenealogyController::class, 'handleProviderCallback']);
    Route::delete('/trees/{treeId}/providers/{providerId}', [GenealogyController::class, 'disconnectProvider']);
    Route::get('/trees/{treeId}/providers/tokens', [GenealogyController::class, 'getProviderTokens']);
    Route::post('/providers/search', [GenealogyController::class, 'searchAllProviders']);
    Route::post('/providers/{providerId}/search', [GenealogyController::class, 'searchProvider']);
    Route::get('/persons/{personId}/provider-matches', [GenealogyController::class, 'getPersonProviderMatches']);

    // Phase 9.5: Research Sources & Cache
    Route::get('/research/sources', [GenealogyController::class, 'getResearchSources']);
    Route::get('/research/sources/{sourceCode}', [GenealogyController::class, 'getResearchSource']);
    Route::post('/research/search', [GenealogyController::class, 'executeResearchSearch']);
    Route::get('/research/cache/stats', [GenealogyController::class, 'getResearchCacheStats']);
    Route::delete('/research/cache/expired', [GenealogyController::class, 'cleanupExpiredCache']);

    // Batch Operations (Priority 3.5)
    Route::post('/trees/{treeId}/persons/batch-update', [GenealogyController::class, 'batchUpdatePersons']);
    Route::delete('/trees/{treeId}/persons/batch-delete', [GenealogyController::class, 'batchDeletePersons']);
    Route::post('/trees/{treeId}/media/batch-tag', [GenealogyController::class, 'batchTagMedia']);
    Route::post('/trees/{treeId}/media/batch-link', [GenealogyController::class, 'batchLinkMedia']);
    Route::delete('/trees/{treeId}/media/batch-delete', [GenealogyController::class, 'batchDeleteMedia']);

    // Phase 9.6: Newspaper Clippings Integration
    Route::get('/newspapers/search', [GenealogyController::class, 'searchNewspapers']);
    Route::get('/newspapers/obituaries', [GenealogyController::class, 'searchObituaries']);
    Route::get('/newspapers/births', [GenealogyController::class, 'searchBirthAnnouncements']);
    Route::get('/newspapers/marriages', [GenealogyController::class, 'searchMarriages']);
    Route::get('/newspapers/page/{lccn}/{date}', [GenealogyController::class, 'getNewspaperPageOCR']);
    Route::get('/newspapers/info/{lccn}', [GenealogyController::class, 'getNewspaperInfo']);
    Route::get('/trees/{treeId}/clippings', [GenealogyController::class, 'getTreeClippings']);
    Route::post('/trees/{treeId}/clippings', [GenealogyController::class, 'saveClipping']);
    Route::get('/clippings/{id}', [GenealogyController::class, 'getClipping']);
    Route::delete('/clippings/{id}', [GenealogyController::class, 'deleteClipping']);
    Route::post('/clippings/{clippingId}/persons/{personId}', [GenealogyController::class, 'linkClippingToPerson']);
    Route::delete('/clippings/{clippingId}/persons/{personId}', [GenealogyController::class, 'unlinkClippingFromPerson']);
    Route::get('/persons/{personId}/clippings', [GenealogyController::class, 'getPersonClippings']);
    Route::post('/persons/{personId}/search-newspapers', [GenealogyController::class, 'searchNewspapersForPerson']);

    // AI Research (Priority A.1)
    Route::post('/persons/{id}/ai-research', [GenealogyController::class, 'aiResearchPerson']);
    Route::post('/persons/{id}/brick-wall-suggestions', [GenealogyController::class, 'aiBrickWallSuggestions']);
    Route::post('/persons/{id}/extract-research-data', [GenealogyController::class, 'extractResearchData']);
    Route::post('/persons/{id}/apply-research-data', [GenealogyController::class, 'applyResearchData']);
    Route::post('/sources/evaluate', [GenealogyController::class, 'aiEvaluateSource']);
    Route::post('/persons/analyze-relationship', [GenealogyController::class, 'aiAnalyzeRelationship']);

    // Natural Language Search (Priority A.3)
    Route::post('/search/natural-language', [GenealogyController::class, 'naturalLanguageSearch']);

    // FAN Clusters (Friends, Associates, Neighbors)
    Route::get('/fan-cluster-types', [GenealogyController::class, 'getFanClusterTypes']);
    Route::get('/persons/{personId}/fan-clusters', [GenealogyController::class, 'getPersonFanClusters']);
    Route::get('/fan-clusters/{id}', [GenealogyController::class, 'getFanCluster']);
    Route::post('/fan-clusters', [GenealogyController::class, 'createFanCluster']);
    Route::put('/fan-clusters/{id}', [GenealogyController::class, 'updateFanCluster']);
    Route::delete('/fan-clusters/{id}', [GenealogyController::class, 'deleteFanCluster']);
    Route::post('/fan-clusters/{clusterId}/members', [GenealogyController::class, 'addFanClusterMember']);
    Route::put('/fan-cluster-members/{memberId}', [GenealogyController::class, 'updateFanClusterMember']);
    Route::delete('/fan-cluster-members/{memberId}', [GenealogyController::class, 'removeFanClusterMember']);
    Route::post('/fan-cluster-members/{memberId}/link', [GenealogyController::class, 'linkFanMemberToPerson']);
    Route::get('/persons/{personId}/fan-extract/census', [GenealogyController::class, 'extractFanFromCensus']);
    Route::get('/persons/{personId}/fan-extract/witnesses', [GenealogyController::class, 'extractFanWitnesses']);
    Route::get('/persons/{personId}/fan-extract/church', [GenealogyController::class, 'extractFanChurchAssociates']);
    Route::get('/fan-clusters/{id}/analysis', [GenealogyController::class, 'analyzeFanCluster']);
    Route::get('/fan-clusters/{id}/research-suggestions', [GenealogyController::class, 'getFanResearchSuggestions']);
    Route::get('/fan-clusters/{id}/network', [GenealogyController::class, 'getFanClusterNetwork']);

    // N98: Research Search History
    Route::get('/persons/{personId}/research-logs', [GenealogyController::class, 'getPersonResearchLogs']);
    Route::get('/trees/{treeId}/research-logs', [GenealogyController::class, 'getTreeResearchLogs']);
    // N93: FAN Co-occurrence accumulated by agent
    Route::get('/persons/{personId}/fan-cooccurrences', [GenealogyController::class, 'getPersonFanCooccurrences']);
});

// =========================================================================
// Agent System Routes
// =========================================================================
Route::prefix('agent')->group(function () {
    // Guardrail confirmation endpoints (no auth - uses token-based access)
    Route::get('/guardrail/confirm/{token}/approve', function (string $token) {
        $result = app(\App\Services\AgentGuardrailService::class)->confirm($token, true, 'pushover_link');
        if ($result['success']) {
            return response()->json(['status' => 'approved', 'operation' => $result['operation']]);
        }

        return response()->json(['status' => 'error', 'message' => $result['error']], 400);
    });

    Route::get('/guardrail/confirm/{token}/deny', function (string $token) {
        $result = app(\App\Services\AgentGuardrailService::class)->confirm($token, false, 'pushover_link');
        if ($result['success']) {
            return response()->json(['status' => 'denied', 'operation' => $result['operation']]);
        }

        return response()->json(['status' => 'error', 'message' => $result['error']], 400);
    });

    // ─── Kill Switch ──────────────────────────────────────────────
    Route::post('/kill/{agentId}', function (string $agentId) {
        $ttl = (int) request('ttl', 3600);
        \Illuminate\Support\Facades\Cache::put("agent_kill:{$agentId}", true, $ttl);
        \Illuminate\Support\Facades\Log::warning('Agent kill switch activated', ['agent_id' => $agentId, 'ttl' => $ttl]);

        return response()->json(['success' => true, 'agent_id' => $agentId, 'ttl_seconds' => $ttl, 'message' => "Kill switch set for {$agentId} ({$ttl}s)"]);
    });

    Route::delete('/kill/{agentId}', function (string $agentId) {
        \Illuminate\Support\Facades\Cache::forget("agent_kill:{$agentId}");
        \Illuminate\Support\Facades\Log::info('Agent kill switch cleared', ['agent_id' => $agentId]);

        return response()->json(['success' => true, 'agent_id' => $agentId, 'message' => "Kill switch cleared for {$agentId}"]);
    });

    // Agent status and control
    Route::get('/episodes/{agentId}', function (string $agentId) {
        return response()->json([
            'success' => true,
            'data' => app(\App\Services\AgentLoopService::class)->getRecentEpisodes($agentId, (int) request('limit', 50)),
        ]);
    });

    Route::get('/skills', function () {
        return response()->json([
            'success' => true,
            'data' => app(\App\Services\SkillLoaderService::class)->getSkillIndex(),
        ]);
    });

    Route::get('/tools', function () {
        $policy = app(\App\Services\OfflinePolicyService::class);
        $profile = (string) request('profile', $policy->activeProfile());

        return response()->json([
            'success' => true,
            'profile' => $profile,
            'data' => app(\App\Services\AgentToolRegistryService::class)->listToolsForProfile($profile),
        ]);
    });

    // Dashboard: all episodes across all agents
    Route::get('/episodes', function () {
        try {
            $episodes = \Illuminate\Support\Facades\DB::select(
                'SELECT * FROM agent_episodes ORDER BY created_at DESC LIMIT ?',
                [(int) request('limit', 100)]
            );

            return response()->json([
                'success' => true,
                'data' => array_map(function ($e) {
                    $e->details = json_decode($e->details, true);

                    return $e;
                }, $episodes),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => []]);
        }
    });

    // Dashboard: scheduled agent jobs
    Route::get('/scheduled', function () {
        try {
            $jobs = \Illuminate\Support\Facades\DB::select(
                "SELECT id, name, description, command, cron_expression, enabled, last_run_at, last_run_status, notes
                 FROM scheduled_jobs WHERE job_type = 'agent_task' ORDER BY name"
            );

            return response()->json(['success' => true, 'data' => $jobs]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => []]);
        }
    });

    // ─── Review Queue (human-in-the-loop approval) ────────────────
    Route::get('/review', function () {
        $agentId = request('agent_id');
        $limit = (int) request('limit', 50);

        return response()->json([
            'success' => true,
            'data' => app(\App\Services\AgentLoopService::class)->getPendingReviews($agentId, $limit),
        ]);
    });

    Route::get('/review/stats', function () {
        try {
            $stats = \Illuminate\Support\Facades\DB::selectOne("
                SELECT
                    COUNT(*) as total,
                    COALESCE(SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END), 0) as pending,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) as approved,
                    COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) as rejected,
                    COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) as expired
                FROM agent_review_queue
            ");

            return response()->json(['success' => true, 'data' => (array) $stats]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0]]);
        }
    });

    Route::get('/review/{token}/approve', function (string $token) {
        $notes = request('notes');
        $result = app(\App\Services\AgentLoopService::class)->resolveReview($token, true, $notes);
        if ($result['success']) {
            return response()->json($result);
        }

        return response()->json($result, 400);
    });

    Route::get('/review/{token}/reject', function (string $token) {
        $notes = request('notes');
        $result = app(\App\Services\AgentLoopService::class)->resolveReview($token, false, $notes);
        if ($result['success']) {
            return response()->json($result);
        }

        return response()->json($result, 400);
    });

    Route::post('/review/{token}/resolve', function (string $token) {
        $approved = (bool) request('approved', false);
        $notes = request('notes');
        $result = app(\App\Services\AgentLoopService::class)->resolveReview($token, $approved, $notes);
        if ($result['success']) {
            return response()->json($result);
        }

        return response()->json($result, 400);
    });

    // ─── Agent Chat (review page conversation) ─────────────────────

    Route::post('/chat/{token}', function (string $token) {
        $message = request('message');
        if (empty($message)) {
            return response()->json(['success' => false, 'error' => 'Message required'], 400);
        }

        // Verify token exists
        $item = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT agent_id, title, summary, details FROM agent_review_queue WHERE token = ?',
            [$token]
        );

        if (! $item) {
            return response()->json(['success' => false, 'error' => 'Review item not found'], 404);
        }

        // Build context from review item
        $context = "You are the '{$item->agent_id}' agent. A human is asking about a review item you submitted.\n\n";
        $context .= "Review title: {$item->title}\n";
        $context .= "Summary: {$item->summary}\n";
        if ($item->details) {
            $details = json_decode($item->details, true);
            if ($details) {
                $context .= 'Details: '.json_encode($details, JSON_UNESCAPED_UNICODE)."\n";
            }
        }
        $context .= "\nHuman message: {$message}\n\nRespond concisely and helpfully. If they're asking for clarification about your recommendation, explain your reasoning. If they're giving instructions, acknowledge and confirm.";

        try {
            $aiService = app(\App\Services\AIService::class);
            $result = $aiService->process($context, [
                'max_tokens' => 1000,
                'temperature' => 0.3,
                'suppressAlert' => true,
                'use_cache' => false,
            ]);

            if ($result['success']) {
                // Log the conversation
                \Illuminate\Support\Facades\DB::insert("
                    INSERT INTO agent_messages (from_agent, to_agent, message_type, subject, body, priority, created_at)
                    VALUES (?, ?, 'chat', ?, ?, 0, NOW())
                ", ['human', $item->agent_id, "Re: {$item->title}", "Human: {$message}\n\nAI: {$result['response']}"]);

                return response()->json([
                    'success' => true,
                    'response' => $result['response'],
                    'provider' => $result['provider'] ?? 'unknown',
                ]);
            }

            return response()->json(['success' => false, 'error' => $result['error'] ?? 'AI processing failed'], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    });

    // ─── Quick Action (URL tap from Pushover — no UI needed) ──────

    Route::get('/review/{token}/quick', function (string $token) {
        $action = request('action', 'approve');
        $msg = request('msg', '');

        $approved = in_array($action, ['approve', 'yes', 'ok', 'y']);
        $result = app(\App\Services\AgentLoopService::class)->resolveReview($token, $approved, $msg ?: "Quick action: {$action}");

        if ($result['success']) {
            // Send confirmation back via Pushover
            try {
                $status = $approved ? 'APPROVED' : 'REJECTED';
                $controller = app(\App\Controllers\NotificationController::class);
                $controller->send('pushover', [
                    'source_group' => 'agent_approval_review',
                    'title' => "{$status}: {$result['title']}",
                    'message' => "Review item {$status}".($msg ? "\nYour note: {$msg}" : ''),
                    'priority' => -1,
                    'sound' => 'none',
                ]);
            } catch (\Throwable $e) {
                // Non-fatal
            }

            // Return a simple HTML confirmation (shown in mobile browser)
            return response(
                '<html><body style="background:#1a1a2e;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;"><div style="text-align:center;"><h1 style="font-size:48px;">'.($approved ? '&#10004;' : '&#10008;').'</h1><h2>'.strtoupper($action).'</h2><p style="color:#888;">'.e($result['title']).'</p></div></div></body></html>',
                200,
                ['Content-Type' => 'text/html']
            );
        }

        return response(
            '<html><body style="background:#1a1a2e;color:#e74c3c;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;"><div style="text-align:center;"><h2>Error</h2><p>'.e($result['error'] ?? 'Unknown').'</p></div></div></body></html>',
            400,
            ['Content-Type' => 'text/html']
        );
    });

    // ─── Human Command Interface (Pushover → web page → framework) ──

    Route::post('/command', function () {
        $command = request('command');
        $agentId = request('agent_id', 'system');
        $reviewToken = request('review_token');
        $notify = (bool) request('notify', false);

        if (empty($command)) {
            return response()->json(['success' => false, 'error' => 'Command required'], 400);
        }

        // Log the inbound human command
        \Illuminate\Support\Facades\DB::insert("
            INSERT INTO agent_messages (from_agent, to_agent, message_type, subject, body, priority, created_at)
            VALUES ('human', ?, 'command', ?, ?, 0, NOW())
        ", [$agentId, 'Human command', $command]);

        // Build context for AI processing
        $context = "You are the PLOS framework assistant. A human operator sent this command via the remote command interface.\n\n";

        // If linked to a review item, include that context
        if ($reviewToken) {
            $item = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT agent_id, title, summary FROM agent_review_queue WHERE token = ?',
                [$reviewToken]
            );
            if ($item) {
                $context .= "Context: This relates to review item '{$item->title}' from agent '{$item->agent_id}'.\n";
                $context .= "Summary: {$item->summary}\n\n";
            }
        }

        // Handle known direct commands without AI
        $cmd = strtolower(trim($command));
        if ($cmd === 'status') {
            try {
                $pipelines = \Illuminate\Support\Facades\DB::select('
                    SELECT name, cron_expression, enabled,
                        (SELECT MAX(started_at) FROM scheduled_job_runs WHERE scheduled_job_id = scheduled_jobs.id) as last_run
                    FROM scheduled_jobs WHERE enabled = 1 ORDER BY category, name LIMIT 20
                ');
                $pending = \Illuminate\Support\Facades\DB::selectOne("SELECT COUNT(*) as cnt FROM agent_review_queue WHERE status = 'pending'");
                $response = 'Active jobs: '.count($pipelines)."\nPending reviews: {$pending->cnt}\n\n";
                foreach (array_slice($pipelines, 0, 10) as $p) {
                    $response .= "- {$p->name} ({$p->cron_expression})".($p->last_run ? " last: {$p->last_run}" : '')."\n";
                }

                return response()->json(['success' => true, 'response' => $response]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }

        if ($cmd === 'pipeline status') {
            try {
                $stats = \Illuminate\Support\Facades\DB::select("
                    SELECT
                        CASE
                            WHEN name LIKE 'file_enrich_%' THEN 'File Enrichment'
                            WHEN name LIKE 'file_exif_%' THEN 'EXIF Writeback'
                            WHEN name LIKE 'genealogy%' THEN 'Genealogy'
                            ELSE category
                        END as pipeline_group,
                        COUNT(*) as job_count,
                        SUM(enabled) as enabled_count
                    FROM scheduled_jobs
                    GROUP BY pipeline_group
                    ORDER BY pipeline_group
                ");
                $response = "Pipeline Status:\n";
                foreach ($stats as $s) {
                    $response .= "- {$s->pipeline_group}: {$s->enabled_count}/{$s->job_count} enabled\n";
                }

                return response()->json(['success' => true, 'response' => $response]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }

        if (in_array($cmd, ['pause all', 'resume all'])) {
            $enable = $cmd === 'resume all' ? 1 : 0;
            $label = $cmd === 'resume all' ? 'resumed' : 'paused';
            try {
                $affected = \Illuminate\Support\Facades\DB::update(
                    "UPDATE scheduled_jobs SET enabled = ? WHERE job_type != 'system'",
                    [$enable]
                );
                $response = "All non-system jobs {$label}. ({$affected} jobs affected)";

                return response()->json(['success' => true, 'response' => $response]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }

        // Kill switch commands
        if (preg_match('/^kill\s+(\S+)$/i', $cmd, $killMatch)) {
            $targetAgent = $killMatch[1];
            \Illuminate\Support\Facades\Cache::put("agent_kill:{$targetAgent}", true, 3600);

            return response()->json(['success' => true, 'response' => "Kill switch activated for '{$targetAgent}' (1 hour TTL)"]);
        }

        if (preg_match('/^unkill\s+(\S+)$/i', $cmd, $unkillMatch)) {
            $targetAgent = $unkillMatch[1];
            \Illuminate\Support\Facades\Cache::forget("agent_kill:{$targetAgent}");

            return response()->json(['success' => true, 'response' => "Kill switch cleared for '{$targetAgent}'"]);
        }

        // For everything else, route through AI
        $context .= "Command: {$command}\n\n";
        $context .= "Interpret and respond to this command. If it's a question, answer it. If it's an instruction, confirm what you would do. ";
        $context .= 'Be concise — this response will be shown on a mobile screen. Keep to 2-3 sentences max.';

        try {
            $aiService = app(\App\Services\AIService::class);
            $result = $aiService->process($context, [
                'max_tokens' => 500,
                'temperature' => 0.3,
                'suppressAlert' => true,
                'use_cache' => false,
            ]);

            if ($result['success']) {
                $aiResponse = $result['response'];

                // Log the AI response
                \Illuminate\Support\Facades\DB::insert("
                    INSERT INTO agent_messages (from_agent, to_agent, message_type, subject, body, priority, created_at)
                    VALUES (?, 'human', 'command_response', ?, ?, 0, NOW())
                ", [$agentId, "Re: {$command}", $aiResponse]);

                // Optionally echo response back to Pushover
                if ($notify) {
                    try {
                        $controller = app(\App\Controllers\NotificationController::class);
                        $controller->send('pushover', [
                            'source_group' => 'agent_approval_review',
                            'title' => "PLOS: {$agentId}",
                            'message' => substr($aiResponse, 0, 500),
                            'priority' => -1,
                            'sound' => 'none',
                        ]);
                    } catch (\Throwable $e) {
                        // Non-fatal
                    }
                }

                return response()->json([
                    'success' => true,
                    'response' => $aiResponse,
                    'provider' => $result['provider'] ?? 'unknown',
                ]);
            }

            return response()->json(['success' => false, 'error' => $result['error'] ?? 'AI processing failed'], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    });

    // ─── Agent Message Bus ─────────────────────────────────────────
    Route::get('/messages', function () {
        $agentId = request('agent_id');
        $limit = (int) request('limit', 50);

        return response()->json([
            'success' => true,
            'data' => app(\App\Services\AgentLoopService::class)->getAgentMessages($agentId, $limit),
        ]);
    });

    Route::get('/messages/stats', function () {
        try {
            $stats = \Illuminate\Support\Facades\DB::selectOne('
                SELECT
                    COUNT(*) as total,
                    COALESCE(SUM(CASE WHEN expires_at IS NULL OR expires_at > NOW() THEN 1 ELSE 0 END), 0) as active,
                    COALESCE(SUM(CASE WHEN priority >= 1 THEN 1 ELSE 0 END), 0) as high_priority
                FROM agent_messages
            ');

            return response()->json(['success' => true, 'data' => (array) $stats]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => ['total' => 0, 'active' => 0, 'high_priority' => 0]]);
        }
    });

    // Toggle scheduled agent job enabled/disabled
    Route::post('/scheduled/{jobId}/toggle', function (int $jobId) {
        $job = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT id, enabled FROM scheduled_jobs WHERE id = ? AND job_type = 'agent_task'", [$jobId]
        );
        if (! $job) {
            return response()->json(['success' => false, 'error' => 'Job not found'], 404);
        }
        $newState = $job->enabled ? 0 : 1;
        \Illuminate\Support\Facades\DB::update('UPDATE scheduled_jobs SET enabled = ? WHERE id = ?', [$newState, $jobId]);

        return response()->json(['success' => true, 'enabled' => (bool) $newState]);
    });

    // Run an agent on demand
    Route::post('/run', function () {
        $agentId = request('agent_id');
        $task = request('task', '');
        $treeId = request('tree_id');
        $notify = request('notify', false);

        if (! $agentId) {
            return response()->json(['success' => false, 'error' => 'agent_id required'], 400);
        }

        $skillLoader = app(\App\Services\SkillLoaderService::class);
        if (! $skillLoader->skillExists($agentId)) {
            return response()->json(['success' => false, 'error' => "Skill '{$agentId}' not found"], 404);
        }

        // Default task from skill config
        if (! $task) {
            $task = 'Perform scheduled autonomous research and analysis.';
        }

        $result = app(\App\Services\AgentLoopService::class)->execute($agentId, $task, [
            'tree_id' => $treeId ? (int) $treeId : null,
            'notify' => (bool) $notify,
        ]);

        return response()->json($result);
    });
});

// ─── AG-18: A2A Protocol Endpoints ──────────────────────────────
Route::prefix('a2a')->group(function () {
    Route::get('/.well-known/agent.json', [\App\Http\Controllers\Api\A2AController::class, 'agentCard']);
    Route::get('/agents', [\App\Http\Controllers\Api\A2AController::class, 'listAgents']);
    Route::get('/agents/{agentId}', [\App\Http\Controllers\Api\A2AController::class, 'getAgent']);
    Route::post('/agents/{agentId}/tasks', [\App\Http\Controllers\Api\A2AController::class, 'submitTask']);
    Route::get('/agents/{agentId}/tasks/{taskId}', [\App\Http\Controllers\Api\A2AController::class, 'getTaskStatus']);
});
