<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataRemovalService;
use App\Services\RAGService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API Controller for Browser Extension Communication
 * Provides endpoints for the Firefox Data Removal Assistant extension
 */
class ExtensionController extends Controller
{
    public function __construct(
        private DataRemovalService $dataRemovalService
    ) {}

    /**
     * Get pending tasks, optionally filtered by domain
     */
    public function getTasks(Request $request): JsonResponse
    {
        try {
            $domain = $request->query('domain');

            $filters = [
                'status' => 'pending',
            ];

            // If domain provided, try to match broker
            if ($domain) {
                $filters['broker_domain'] = $domain;
            }

            $tasks = $this->dataRemovalService->getRequests($filters);

            // Map to extension-friendly format
            $formattedTasks = array_map(function ($task) {
                return [
                    'id' => $task->id,
                    'subject_name' => $task->subject_name,
                    'broker_name' => $task->broker_name,
                    'broker_domain' => $task->broker_domain ?? null,
                    'broker_removal_url' => $task->broker_removal_url ?? null,
                    'status' => $task->status,
                    'requires_review' => (bool) $task->requires_review,
                    'last_error' => $task->last_error,
                    'fields_to_submit' => json_decode($task->fields_to_submit ?? '["name"]', true),
                    'created_at' => $task->created_at,
                ];
            }, $tasks);

            return response()->json([
                'success' => true,
                'tasks' => $formattedTasks,
            ]);
        } catch (\Exception $e) {
            Log::error('Extension getTasks error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get task details with full subject data for form filling
     */
    public function getTaskDetails(int $id): JsonResponse
    {
        try {
            $task = $this->dataRemovalService->getRequest($id);

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'error' => 'Task not found',
                ], 404);
            }

            $subject = $this->dataRemovalService->getSubject($task->subject_id);
            $broker = $this->dataRemovalService->getBroker($task->broker_id);

            // Get fields to submit
            $fieldsToSubmit = $this->dataRemovalService->getFieldsToSubmit($id);

            // Filter subject data to only include allowed fields
            // Email is always included as it's needed for confirmation on most sites
            $subjectData = [];
            if (in_array('name', $fieldsToSubmit)) {
                $subjectData['name'] = $subject->name;
            }
            // Always include email - needed for confirmation on most broker sites
            if (!empty($subject->email)) {
                $subjectData['email'] = $subject->email;
            }
            if (in_array('phone', $fieldsToSubmit) && !empty($subject->phone)) {
                $subjectData['phone'] = $subject->phone;
            }
            if (in_array('address', $fieldsToSubmit) && !empty($subject->address_line1)) {
                $subjectData['address_line1'] = $subject->address_line1;
            }
            if (in_array('city', $fieldsToSubmit) && !empty($subject->city)) {
                $subjectData['city'] = $subject->city;
            }
            if (in_array('state', $fieldsToSubmit) && !empty($subject->state)) {
                $subjectData['state'] = $subject->state;
            }
            if (in_array('zip', $fieldsToSubmit) && !empty($subject->zip)) {
                $subjectData['zip'] = $subject->zip;
            }
            if (in_array('dob', $fieldsToSubmit) && !empty($subject->date_of_birth)) {
                $subjectData['date_of_birth'] = $subject->date_of_birth;
            }

            return response()->json([
                'success' => true,
                'task' => [
                    'id' => $task->id,
                    'subject_name' => $subject->name,
                    'subject_data' => $subjectData,
                    'broker_name' => $broker->name,
                    'broker_domain' => $broker->domain,
                    'broker_removal_url' => $broker->removal_url,
                    'broker_notes' => $broker->removal_notes,  // Instructions for this broker
                    'profile_url' => $task->profile_url,  // URL where data was found
                    'status' => $task->status,
                    'requires_review' => (bool) $task->requires_review,
                    'last_error' => $task->last_error,
                    'fields_to_submit' => $fieldsToSubmit,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Extension getTaskDetails error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark a task as complete (submitted manually via extension)
     */
    public function completeTask(Request $request, int $id): JsonResponse
    {
        try {
            $task = $this->dataRemovalService->getRequest($id);

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'error' => 'Task not found',
                ], 404);
            }

            $result = $request->input('result', []);

            // Update status to submitted
            $this->dataRemovalService->updateRequestStatus($id, 'submitted');
            $this->dataRemovalService->scheduleFollowup($id);

            // Log the manual completion
            $this->dataRemovalService->logActivity(
                $id,
                'submitted',
                'Submitted manually via browser extension',
                array_merge($result, ['via' => 'extension']),
                'manual'
            );

            // Clear the review flag
            $this->dataRemovalService->updateRequest($id, [
                'requires_review' => 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task marked as complete',
            ]);
        } catch (\Exception $e) {
            Log::error('Extension completeTask error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Skip a task
     */
    public function skipTask(Request $request, int $id): JsonResponse
    {
        try {
            $task = $this->dataRemovalService->getRequest($id);

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'error' => 'Task not found',
                ], 404);
            }

            $reason = $request->input('reason', 'Skipped via browser extension');

            // Log the skip
            $this->dataRemovalService->logActivity(
                $id,
                'skipped',
                $reason,
                ['via' => 'extension'],
                'manual'
            );

            // Update the request to mark as skipped/failed
            $this->dataRemovalService->updateRequest($id, [
                'last_error' => $reason,
                'requires_review' => 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task skipped',
            ]);
        } catch (\Exception $e) {
            Log::error('Extension skipTask error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update task fields (like profile_url)
     */
    public function updateTaskFields(Request $request, int $id): JsonResponse
    {
        try {
            $task = $this->dataRemovalService->getRequest($id);

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'error' => 'Task not found',
                ], 404);
            }

            $updates = [];

            // Only allow updating specific fields
            if ($request->has('profile_url')) {
                $profileUrl = $request->input('profile_url');
                // Validate URL
                if (!empty($profileUrl) && !filter_var($profileUrl, FILTER_VALIDATE_URL)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Invalid URL format',
                    ], 400);
                }
                $updates['profile_url'] = $profileUrl;
            }

            if (!empty($updates)) {
                $this->dataRemovalService->updateRequest($id, $updates);

                Log::info('Extension updated task fields', [
                    'task_id' => $id,
                    'updates' => array_keys($updates),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Task fields updated',
            ]);
        } catch (\Exception $e) {
            Log::error('Extension updateTaskFields error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get AI help for completing a task
     */
    public function getAIHelp(Request $request): JsonResponse
    {
        try {
            $context = $request->input('context', []);
            $task = $context['task'] ?? null;
            $page = $context['page'] ?? null;
            $question = $context['question'] ?? 'How do I complete this removal request?';

            // Build helpful response based on context
            $help = $this->generateHelp($task, $page, $question);

            return response()->json([
                'success' => true,
                'help' => $help,
            ]);
        } catch (\Exception $e) {
            Log::error('Extension getAIHelp error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Report detected form fields for a broker
     */
    public function reportFormFields(Request $request): JsonResponse
    {
        try {
            $brokerId = $request->input('broker_id');
            $domain = $request->input('domain');
            $fields = $request->input('fields', []);
            $pageInfo = $request->input('page_info', []);

            // Find broker by ID or domain
            $broker = null;
            if ($brokerId) {
                $broker = $this->dataRemovalService->getBroker($brokerId);
            } elseif ($domain) {
                $broker = $this->dataRemovalService->getBrokerByDomain($domain);
            }

            if ($broker) {
                // Update discovered selectors
                $existingSelectors = json_decode($broker->discovered_selectors ?? '{}', true);
                $newSelectors = array_merge($existingSelectors, $fields);

                $this->dataRemovalService->updateBroker($broker->id, [
                    'discovered_selectors' => json_encode($newSelectors),
                    'form_config_source' => 'extension',
                    'form_config_updated_at' => now(),
                ]);

                Log::info('Extension updated broker form fields', [
                    'broker_id' => $broker->id,
                    'domain' => $domain,
                    'fields' => $fields,
                ]);
            } else {
                Log::info('Extension reported form fields for unknown broker', [
                    'domain' => $domain,
                    'fields' => $fields,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Form fields reported',
            ]);
        } catch (\Exception $e) {
            Log::error('Extension reportFormFields error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get site configuration for a domain
     */
    public function getSiteConfig(Request $request): JsonResponse
    {
        try {
            $domain = $request->query('domain');

            if (!$domain) {
                return response()->json([
                    'success' => false,
                    'error' => 'Domain is required',
                ], 400);
            }

            $broker = $this->dataRemovalService->getBrokerByDomain($domain);

            if (!$broker) {
                return response()->json([
                    'success' => true,
                    'config' => null,
                    'message' => 'No configuration found for this domain',
                ]);
            }

            $formConfig = json_decode($broker->form_config ?? '{}', true);
            $discoveredSelectors = json_decode($broker->discovered_selectors ?? '{}', true);

            return response()->json([
                'success' => true,
                'config' => [
                    'broker_id' => $broker->id,
                    'broker_name' => $broker->name,
                    'domain' => $broker->domain,
                    'removal_url' => $broker->removal_url,
                    'requires_captcha' => (bool) $broker->requires_captcha,
                    'uses_javascript' => (bool) $broker->uses_javascript,
                    'form_config' => $formConfig,
                    'discovered_selectors' => $discoveredSelectors,
                    'removal_notes' => $broker->removal_notes,
                    'required_fields' => json_decode($broker->required_fields ?? '["name"]', true),
                    'optional_fields' => json_decode($broker->optional_fields ?? '[]', true),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Extension getSiteConfig error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update site configuration for a broker (used by AI or admin)
     */
    public function updateSiteConfig(Request $request, int $brokerId): JsonResponse
    {
        try {
            $broker = $this->dataRemovalService->getBroker($brokerId);

            if (!$broker) {
                return response()->json([
                    'success' => false,
                    'error' => 'Broker not found',
                ], 404);
            }

            $updates = [];

            if ($request->has('form_config')) {
                $updates['form_config'] = json_encode($request->input('form_config'));
            }

            if ($request->has('removal_notes')) {
                $updates['removal_notes'] = $request->input('removal_notes');
            }

            if ($request->has('requires_captcha')) {
                $updates['requires_captcha'] = (bool) $request->input('requires_captcha');
            }

            if ($request->has('removal_url')) {
                $updates['removal_url'] = $request->input('removal_url');
            }

            if (!empty($updates)) {
                $updates['form_config_source'] = $request->input('source', 'api');
                $updates['form_config_updated_at'] = now();

                $this->dataRemovalService->updateBroker($brokerId, $updates);

                Log::info('Site config updated', [
                    'broker_id' => $brokerId,
                    'source' => $updates['form_config_source'],
                    'updates' => array_keys($updates),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Site configuration updated',
            ]);
        } catch (\Exception $e) {
            Log::error('Extension updateSiteConfig error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Index page content to RAG (from Cloudflare bypass or manual extraction)
     */
    public function indexPageContent(Request $request): JsonResponse
    {
        try {
            $pageData = $request->input('pageData', []);
            $url = $pageData['url'] ?? null;
            $title = $pageData['title'] ?? 'Untitled Page';
            $content = $pageData['content'] ?? '';
            $timestamp = $pageData['timestamp'] ?? now()->toIso8601String();

            if (empty($content) || strlen($content) < 50) {
                return response()->json([
                    'success' => false,
                    'error' => 'Content too short to index',
                ], 400);
            }

            // Parse domain from URL
            $domain = null;
            if ($url) {
                $parsed = parse_url($url);
                $domain = $parsed['host'] ?? null;
            }

            // Index to RAG
            $ragService = app(RAGService::class);
            $result = $ragService->indexDocument(
                documentType: 'web_page',
                content: $content,
                title: $title,
                metadata: [
                    'url' => $url,
                    'domain' => $domain,
                    'extracted_at' => $timestamp,
                    'source' => 'browser_extension',
                    'extraction_type' => 'cloudflare_bypass',
                ],
                sourceId: md5($url ?? $title . $timestamp)
            );

            Log::info('Extension indexed page content', [
                'url' => $url,
                'domain' => $domain,
                'title' => substr($title, 0, 100),
                'content_length' => strlen($content),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Page indexed to RAG',
                'indexed' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Extension indexPageContent error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate helpful guidance for the user
     */
    private function generateHelp(?array $task, ?array $page, string $question): string
    {
        $help = [];

        if ($task) {
            $help[] = "Task: Remove data for {$task['subject_name']} from {$task['broker_name']}";

            if (!empty($task['last_error'])) {
                if (stripos($task['last_error'], 'captcha') !== false) {
                    $help[] = "\nThis site requires a CAPTCHA. Steps:";
                    $help[] = "1. The form fields should be auto-filled";
                    $help[] = "2. Solve the CAPTCHA challenge";
                    $help[] = "3. Click the Submit button";
                    $help[] = "4. Click 'Mark Complete' in the extension";
                }
            }

            if (!empty($task['fields_to_submit'])) {
                $fields = is_array($task['fields_to_submit'])
                    ? implode(', ', $task['fields_to_submit'])
                    : $task['fields_to_submit'];
                $help[] = "\nFields being submitted: {$fields}";
            }
        }

        if ($page) {
            if (!empty($page['detectedFields'])) {
                $help[] = "\nDetected form fields on this page: " . implode(', ', $page['detectedFields']);
            }
        }

        // Add general tips
        $help[] = "\nGeneral Tips:";
        $help[] = "- If auto-fill didn't work, manually enter the data shown";
        $help[] = "- Look for opt-out or removal links at the bottom of the page";
        $help[] = "- Some sites send a confirmation email - check your inbox";
        $help[] = "- If stuck, click 'Skip' and try again later";

        return implode("\n", $help);
    }
}
