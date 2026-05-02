<?php

namespace App\Services;

use App\Controllers\NotificationController;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Email Suggestion Service (EA2)
 *
 * AI-powered email analysis and suggestion generation:
 * - Email classification/triage (urgent, actionable, fyi, newsletter, spam)
 * - Contact suggestions (2+ emails from unknown sender)
 * - Calendar suggestions (detect dates/times in emails)
 * - Bill/payment detection with due dates
 * - Configurable Pushover notifications
 *
 * All suggestions require human approval before execution.
 */
class EmailSuggestionService
{
    private ThunderbirdService $thunderbird;

    private AIService $aiService;

    private NextcloudContactsService $contacts;

    private NextcloudService $nextcloud;

    // Classification categories
    private const CATEGORIES = ['urgent', 'important', 'actionable', 'fyi', 'newsletter', 'spam'];

    // Bill detection patterns
    private const BILL_PATTERNS = [
        'from_patterns' => [
            '@paypal.com', '@venmo.com', '@zelle',
            '@psecu.com', '@bank', '@credit',
            '@electric', '@gas', '@water', '@utility',
            '@comcast', '@verizon', '@tmobile', '@att.com',
            '@netflix.com', '@spotify.com', '@amazon.com',
            'billing@', 'invoice@', 'payment@', 'statement@',
        ],
        'subject_patterns' => [
            'bill', 'invoice', 'payment', 'statement', 'due',
            'amount owed', 'balance', 'autopay', 'subscription',
        ],
    ];

    public function __construct(
        ThunderbirdService $thunderbird,
        AIService $aiService,
        NextcloudContactsService $contacts,
        NextcloudService $nextcloud
    ) {
        $this->thunderbird = $thunderbird;
        $this->aiService = $aiService;
        $this->contacts = $contacts;
        $this->nextcloud = $nextcloud;
    }

    // =========================================================================
    // EMAIL SCANNING & CLASSIFICATION
    // =========================================================================

    /**
     * Scan emails and generate suggestions
     *
     * @param  string  $folder  Email folder to scan
     * @param  int  $limit  Number of emails to process
     * @return array Scan results
     */
    public function scanAndProcess(string $folder = 'Inbox', int $limit = 50): array
    {
        $results = [
            'scanned' => 0,
            'classified' => 0,
            'suggestions_created' => 0,
            'contacts_suggested' => 0,
            'calendar_suggested' => 0,
            'bills_detected' => 0,
            'errors' => [],
        ];

        try {
            if (! $this->thunderbird->isAvailable()) {
                throw new Exception('Thunderbird MCP not available');
            }

            $emails = $this->thunderbird->getRecentMessages($folder, $limit);
            $messages = $emails['messages'] ?? [];

            foreach ($messages as $email) {
                $results['scanned']++;

                try {
                    // 1. Classify email
                    $classification = $this->classifyEmail($email);
                    if ($classification) {
                        $results['classified']++;
                    }

                    // 2. Check for contact suggestion (2+ emails threshold)
                    $contactSuggestion = $this->checkContactSuggestion($email);
                    if ($contactSuggestion) {
                        $results['contacts_suggested']++;
                        $results['suggestions_created']++;
                    }

                    // 3. Check for calendar suggestions (dates in email)
                    $calendarSuggestion = $this->checkCalendarSuggestion($email, $classification);
                    if ($calendarSuggestion) {
                        $results['calendar_suggested']++;
                        $results['suggestions_created']++;
                    }

                    // 4. Check for bill/payment
                    $billSuggestion = $this->checkBillSuggestion($email);
                    if ($billSuggestion) {
                        $results['bills_detected']++;
                        $results['suggestions_created']++;
                    }

                    // 5. FUTURE: Reply suggestions (stub for future implementation)
                    // $replySuggestion = $this->checkReplySuggestion($email, $classification);
                    // if ($replySuggestion) {
                    //     $results['replies_suggested']++;
                    //     $results['suggestions_created']++;
                    // }

                } catch (Exception $e) {
                    $results['errors'][] = [
                        'email_subject' => $email['subject'] ?? 'Unknown',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            Log::info('EmailSuggestionService: Scan complete', $results);

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            Log::error('EmailSuggestionService: Scan failed', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Classify a single email using AI
     */
    public function classifyEmail(array $email): ?array
    {
        $emailId = $email['id'] ?? md5(($email['subject'] ?? '').($email['from'] ?? '').($email['date'] ?? ''));

        // Check if already classified
        $existing = DB::selectOne(
            'SELECT * FROM email_classifications WHERE message_id = ?',
            [$emailId]
        );

        if ($existing && $existing->processed) {
            return (array) $existing;
        }

        // Build prompt for AI classification
        $prompt = $this->buildClassificationPrompt($email);

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true, // Use factual mode for consistent classification
            ]);

            if (! $result['success']) {
                Log::warning('EmailSuggestionService: AI classification failed', ['error' => $result['error'] ?? 'Unknown']);

                return null;
            }

            $classification = $this->parseClassificationResponse($result['response'], $email);

            // Save classification
            $this->saveClassification($emailId, $email, $classification);

            return $classification;

        } catch (Exception $e) {
            Log::error('EmailSuggestionService: Classification error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build AI prompt for email classification
     */
    private function buildClassificationPrompt(array $email): string
    {
        $from = $email['from'] ?? 'Unknown';
        $subject = $email['subject'] ?? 'No subject';
        $body = substr($email['body'] ?? '', 0, 1000); // Limit body length
        $date = $email['date'] ?? 'Unknown';

        return <<<PROMPT
Analyze this email and provide a JSON classification:

From: {$from}
Subject: {$subject}
Date: {$date}
Body excerpt: {$body}

Respond with ONLY valid JSON (no other text):
{
  "category": "one of: urgent, important, actionable, fyi, newsletter, spam",
  "priority": "one of: urgent, high, normal, low",
  "needs_response": true/false,
  "has_dates": true/false,
  "is_bill": true/false,
  "summary": "1-2 sentence summary",
  "extracted_dates": ["array of dates found, in ISO format"],
  "extracted_amounts": ["array of dollar amounts found"],
  "confidence": 0.0-1.0
}
PROMPT;
    }

    /**
     * Parse AI response into classification array
     */
    private function parseClassificationResponse(string $response, array $email): array
    {
        // Try to extract JSON from response
        $jsonMatch = preg_match('/\{[\s\S]*\}/', $response, $matches);

        if ($jsonMatch) {
            $data = json_decode($matches[0], true);
            if ($data) {
                return [
                    'category' => $data['category'] ?? 'fyi',
                    'priority' => $data['priority'] ?? 'normal',
                    'needs_response' => $data['needs_response'] ?? false,
                    'has_dates' => $data['has_dates'] ?? false,
                    'is_bill' => $data['is_bill'] ?? false,
                    'summary' => $data['summary'] ?? null,
                    'extracted_dates' => $data['extracted_dates'] ?? [],
                    'extracted_amounts' => $data['extracted_amounts'] ?? [],
                    'confidence' => $data['confidence'] ?? 0.5,
                ];
            }
        }

        // Fallback classification
        return [
            'category' => 'fyi',
            'priority' => 'normal',
            'needs_response' => false,
            'has_dates' => false,
            'is_bill' => false,
            'summary' => null,
            'extracted_dates' => [],
            'extracted_amounts' => [],
            'confidence' => 0.3,
        ];
    }

    /**
     * Save email classification to database
     */
    private function saveClassification(string $emailId, array $email, array $classification): void
    {
        $now = now();

        $existing = DB::selectOne('SELECT id FROM email_classifications WHERE message_id = ? LIMIT 1', [$emailId]);

        if ($existing) {
            DB::update('
                UPDATE email_classifications SET
                    folder = ?, from_address = ?, subject = ?, email_date = ?,
                    category = ?, priority = ?, needs_response = ?, has_dates = ?,
                    is_bill = ?, is_shipping = ?, summary = ?, extracted_dates = ?,
                    extracted_amounts = ?, confidence = ?, processed = ?, processed_at = ?,
                    classified_at = ?, updated_at = ?
                WHERE id = ?
            ', [
                $email['folder'] ?? 'Inbox',
                $email['from'] ?? null,
                substr($email['subject'] ?? '', 0, 255),
                isset($email['date']) ? Carbon::parse($email['date']) : null,
                $classification['category'],
                $classification['priority'],
                $classification['needs_response'],
                $classification['has_dates'],
                $classification['is_bill'],
                false,
                $classification['summary'],
                json_encode($classification['extracted_dates']),
                json_encode($classification['extracted_amounts']),
                $classification['confidence'],
                true,
                $now,
                $now,
                $now,
                $existing->id,
            ]);
        } else {
            DB::insert('
                INSERT INTO email_classifications (
                    message_id, folder, from_address, subject, email_date,
                    category, priority, needs_response, has_dates, is_bill,
                    is_shipping, summary, extracted_dates, extracted_amounts,
                    confidence, processed, processed_at, classified_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $emailId,
                $email['folder'] ?? 'Inbox',
                $email['from'] ?? null,
                substr($email['subject'] ?? '', 0, 255),
                isset($email['date']) ? Carbon::parse($email['date']) : null,
                $classification['category'],
                $classification['priority'],
                $classification['needs_response'],
                $classification['has_dates'],
                $classification['is_bill'],
                false,
                $classification['summary'],
                json_encode($classification['extracted_dates']),
                json_encode($classification['extracted_amounts']),
                $classification['confidence'],
                true,
                $now,
                $now,
                $now,
                $now,
            ]);
        }
    }

    // =========================================================================
    // CONTACT SUGGESTIONS
    // =========================================================================

    /**
     * Check if we should suggest adding this sender as a contact
     */
    public function checkContactSuggestion(array $email): ?int
    {
        $fromAddress = $this->extractEmailAddress($email['from'] ?? '');
        $fromName = $this->extractName($email['from'] ?? '');

        if (! $fromAddress) {
            return null;
        }

        // Skip common no-reply addresses
        if ($this->isNoReplyAddress($fromAddress)) {
            return null;
        }

        // Check if already in contacts
        if ($this->isInContacts($fromAddress)) {
            return null;
        }

        // Check if already suggested (pending)
        $existingSuggestion = DB::selectOne(
            "SELECT * FROM email_suggested_actions
             WHERE type = 'contact' AND contact_email = ? AND status = 'pending'",
            [$fromAddress]
        );

        if ($existingSuggestion) {
            // Increment email count
            DB::update(
                'UPDATE email_suggested_actions SET email_count = email_count + 1, updated_at = ? WHERE id = ?',
                [now(), $existingSuggestion->id]
            );

            return null; // Not a new suggestion
        }

        // Get threshold from settings
        $threshold = $this->getSetting('contact_threshold')['min_emails'] ?? 2;

        // Count emails from this sender
        $emailCount = $this->countEmailsFromSender($fromAddress);

        if ($emailCount < $threshold) {
            return null; // Not enough emails yet
        }

        // Create contact suggestion
        return $this->createSuggestion([
            'type' => 'contact',
            'title' => "Add contact: {$fromName}",
            'description' => "You've received {$emailCount} emails from {$fromAddress}. Add to contacts?",
            'source_email_id' => $email['id'] ?? null,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'contact_email' => $fromAddress,
            'contact_name' => $fromName,
            'email_count' => $emailCount,
            'confidence' => 0.8,
        ]);
    }

    /**
     * Count emails from a sender
     */
    private function countEmailsFromSender(string $email): int
    {
        // Check classifications table
        $count = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM email_classifications WHERE from_address LIKE ?',
            ["%{$email}%"]
        );

        return $count->cnt ?? 1;
    }

    /**
     * Check if email address is in Nextcloud contacts
     */
    private function isInContacts(string $email): bool
    {
        try {
            // Use searchContacts for more efficient lookup
            $results = $this->contacts->searchContacts($email);

            return ! empty($results);
        } catch (Exception $e) {
            Log::warning('EmailSuggestionService: Could not check contacts', ['error' => $e->getMessage()]);
        }

        return false;
    }

    // =========================================================================
    // CALENDAR SUGGESTIONS
    // =========================================================================

    /**
     * Check for calendar event suggestions based on dates in email
     */
    public function checkCalendarSuggestion(array $email, ?array $classification = null): ?int
    {
        // Use classification if available
        if ($classification && ! ($classification['has_dates'] ?? false)) {
            return null;
        }

        $dates = $classification['extracted_dates'] ?? [];

        // Handle JSON string from database
        if (is_string($dates)) {
            $dates = json_decode($dates, true) ?: [];
        }

        // If no dates from AI, try pattern matching
        if (empty($dates)) {
            $dates = $this->extractDatesFromText(($email['subject'] ?? '').' '.($email['body'] ?? ''));
        }

        if (empty($dates)) {
            return null;
        }

        // Filter to future dates only
        $futureDates = array_filter($dates, function ($date) {
            try {
                return Carbon::parse($date)->isFuture();
            } catch (Exception $e) {
                return false;
            }
        });

        if (empty($futureDates)) {
            return null;
        }

        // Take first future date
        $eventDate = Carbon::parse(reset($futureDates));
        $subject = $email['subject'] ?? 'Event from email';

        // Check if already suggested
        $existingSuggestion = DB::selectOne(
            "SELECT * FROM email_suggested_actions
             WHERE type = 'calendar'
             AND DATE(event_date) = DATE(?)
             AND status = 'pending'",
            [$eventDate]
        );

        if ($existingSuggestion) {
            return null;
        }

        // Create calendar suggestion
        return $this->createSuggestion([
            'type' => 'calendar',
            'title' => "Calendar: {$subject}",
            'description' => "Detected date {$eventDate->format('M j, Y')} in email. Create calendar event?",
            'source_email_id' => $email['id'] ?? null,
            'from_address' => $email['from'] ?? null,
            'email_subject' => $subject,
            'event_date' => $eventDate,
            'event_title' => $subject,
            'confidence' => $classification['confidence'] ?? 0.6,
        ]);
    }

    /**
     * Extract dates from text using regex patterns
     */
    private function extractDatesFromText(string $text): array
    {
        $dates = [];
        $patterns = [
            '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\b/i',
            '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/',
            '/\b\d{4}-\d{2}-\d{2}\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $match) {
                    try {
                        $dates[] = Carbon::parse($match)->toIso8601String();
                    } catch (Exception $e) {
                        // Skip unparseable dates
                    }
                }
            }
        }

        return array_unique($dates);
    }

    // =========================================================================
    // BILL/PAYMENT DETECTION
    // =========================================================================

    /**
     * Check for bill/payment emails
     */
    public function checkBillSuggestion(array $email): ?int
    {
        $from = strtolower($email['from'] ?? '');
        $subject = strtolower($email['subject'] ?? '');
        $body = $email['body'] ?? '';

        // Check if this looks like a bill
        $isBill = false;

        foreach (self::BILL_PATTERNS['from_patterns'] as $pattern) {
            if (str_contains($from, strtolower($pattern))) {
                $isBill = true;
                break;
            }
        }

        if (! $isBill) {
            foreach (self::BILL_PATTERNS['subject_patterns'] as $pattern) {
                if (str_contains($subject, strtolower($pattern))) {
                    $isBill = true;
                    break;
                }
            }
        }

        if (! $isBill) {
            return null;
        }

        // Extract bill details
        $amount = $this->extractAmount($subject.' '.$body);
        $dueDate = $this->extractDueDate($subject.' '.$body);
        $billFrom = $this->extractName($email['from'] ?? '');

        // Check if already suggested
        $checkDate = $dueDate ?? Carbon::now();
        $existingSuggestion = DB::selectOne(
            "SELECT * FROM email_suggested_actions
             WHERE type = 'bill'
             AND bill_from = ?
             AND MONTH(bill_due_date) = MONTH(?)
             AND status = 'pending'",
            [$billFrom, $checkDate]
        );

        if ($existingSuggestion) {
            return null;
        }

        // Create bill suggestion
        $title = $amount
            ? "Bill: {$billFrom} - \${$amount}"
            : "Bill: {$billFrom}";

        $description = $dueDate
            ? "Bill detected. Due: {$dueDate->format('M j, Y')}".($amount ? ", Amount: \${$amount}" : '')
            : "Bill detected from {$billFrom}";

        return $this->createSuggestion([
            'type' => 'bill',
            'title' => $title,
            'description' => $description,
            'source_email_id' => $email['id'] ?? null,
            'from_address' => $email['from'] ?? null,
            'email_subject' => $email['subject'] ?? null,
            'bill_from' => $billFrom,
            'bill_amount' => $amount,
            'bill_due_date' => $dueDate,
            'confidence' => 0.7,
        ]);
    }

    /**
     * Extract dollar amount from text
     */
    private function extractAmount(string $text): ?float
    {
        if (preg_match('/\$\s*([\d,]+\.?\d*)/', $text, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        return null;
    }

    /**
     * Extract due date from text
     */
    private function extractDueDate(string $text): ?Carbon
    {
        // Look for "due" followed by date
        $patterns = [
            '/due\s*(?:date)?[:\s]+([a-z]+\s+\d{1,2}(?:,?\s+\d{4})?)/i',
            '/due\s*(?:by)?[:\s]+(\d{1,2}\/\d{1,2}\/\d{2,4})/i',
            '/payment\s+due[:\s]+([a-z]+\s+\d{1,2})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                try {
                    return Carbon::parse($matches[1]);
                } catch (Exception $e) {
                    // Continue trying
                }
            }
        }

        return null;
    }

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================

    /**
     * Send notifications for urgent/important emails
     */
    public function sendEmailNotifications(): array
    {
        $results = ['notified' => 0, 'errors' => []];

        // Get enabled categories from settings
        $enabledCategories = $this->getSetting('pushover_categories') ?? ['urgent'];

        // Check quiet hours
        if ($this->isQuietHours()) {
            return ['notified' => 0, 'skipped' => 'quiet_hours'];
        }

        // Find unnotified classified emails in enabled categories
        $emails = DB::select(
            "SELECT * FROM email_classifications
             WHERE category IN ('".implode("','", $enabledCategories)."')
             AND processed = 1
             AND (SELECT COUNT(*) FROM email_suggested_actions
                  WHERE email_suggested_actions.source_email_id = email_classifications.message_id
                  AND email_suggested_actions.notified = 1) = 0
             ORDER BY created_at DESC
             LIMIT 10"
        );

        foreach ($emails as $email) {
            try {
                $this->sendPushoverNotification(
                    "Email: {$email->category}",
                    "{$email->from_address}\n{$email->subject}",
                    $email->priority === 'urgent' ? 1 : 0
                );

                $results['notified']++;

            } catch (Exception $e) {
                $results['errors'][] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Send daily bill digest via Pushover
     */
    public function sendBillDigest(): array
    {
        $results = ['bills' => 0, 'sent' => false];

        // Get pending bill suggestions
        $bills = DB::select(
            "SELECT * FROM email_suggested_actions
             WHERE type = 'bill'
             AND status = 'pending'
             AND notified = 0
             ORDER BY bill_due_date ASC"
        );

        if (empty($bills)) {
            return $results;
        }

        // Build digest message
        $lines = [];
        foreach ($bills as $bill) {
            $line = $bill->bill_from;
            if ($bill->bill_due_date) {
                $line .= ' - Due: '.Carbon::parse($bill->bill_due_date)->format('M j');
            }
            if ($bill->bill_amount) {
                $line .= " - \${$bill->bill_amount}";
            }
            $lines[] = $line;
            $results['bills']++;
        }

        $message = implode("\n", $lines);

        try {
            $this->sendPushoverNotification(
                "Bills ({$results['bills']})",
                $message,
                0
            );

            // Mark as notified
            DB::update(
                "UPDATE email_suggested_actions SET notified = 1, notified_at = ? WHERE type = 'bill' AND status = 'pending'",
                [now()]
            );

            $results['sent'] = true;

        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Send Pushover notification
     */
    private function sendPushoverNotification(string $title, string $message, int $priority = 0): void
    {
        $controller = new NotificationController;
        $controller->send('pushover', [
            'source_group' => 'ops_maintenance',
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'sound' => $priority >= 1 ? 'siren' : 'pushover',
        ]);
    }

    /**
     * Check if current time is in quiet hours
     */
    private function isQuietHours(): bool
    {
        $quietHours = $this->getSetting('quiet_hours');

        if (! $quietHours || ! isset($quietHours['start']) || ! isset($quietHours['end'])) {
            return false;
        }

        $now = Carbon::now();
        $start = Carbon::parse($quietHours['start']);
        $end = Carbon::parse($quietHours['end']);

        // Handle overnight quiet hours (e.g., 22:00 - 07:00)
        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    // =========================================================================
    // SUGGESTION MANAGEMENT
    // =========================================================================

    /**
     * Create a new suggestion
     */
    public function createSuggestion(array $data): int
    {
        $now = now();

        DB::insert('
            INSERT INTO email_suggested_actions (
                type, status, title, description, source_email_id, source_folder,
                from_address, from_name, email_subject, email_date, action_data,
                contact_email, contact_name, email_count, event_date, event_title,
                event_location, bill_from, bill_amount, bill_due_date, bill_account,
                confidence, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            $data['type'],
            'pending',
            $data['title'],
            $data['description'] ?? null,
            $data['source_email_id'] ?? null,
            $data['source_folder'] ?? null,
            $data['from_address'] ?? null,
            $data['from_name'] ?? null,
            $data['email_subject'] ?? null,
            $data['email_date'] ?? null,
            isset($data['action_data']) ? json_encode($data['action_data']) : null,
            $data['contact_email'] ?? null,
            $data['contact_name'] ?? null,
            $data['email_count'] ?? 1,
            $data['event_date'] ?? null,
            $data['event_title'] ?? null,
            $data['event_location'] ?? null,
            $data['bill_from'] ?? null,
            $data['bill_amount'] ?? null,
            $data['bill_due_date'] ?? null,
            $data['bill_account'] ?? null,
            $data['confidence'] ?? 0.5,
            $now,
            $now,
        ]);

        $id = DB::getPdo()->lastInsertId();

        Log::info('EmailSuggestionService: Created suggestion', [
            'id' => $id,
            'type' => $data['type'],
            'title' => $data['title'],
        ]);

        return $id;
    }

    /**
     * Get pending suggestions
     */
    public function getPendingSuggestions(?string $type = null): array
    {
        $query = "SELECT * FROM email_suggested_actions WHERE status = 'pending'";
        $params = [];

        if ($type) {
            $query .= ' AND type = ?';
            $params[] = $type;
        }

        $query .= ' ORDER BY created_at DESC';

        return DB::select($query, $params);
    }

    /**
     * Approve a suggestion and execute the action
     */
    public function approveSuggestion(int $id): array
    {
        $suggestion = DB::selectOne('SELECT * FROM email_suggested_actions WHERE id = ?', [$id]);

        if (! $suggestion) {
            return ['success' => false, 'error' => 'Suggestion not found'];
        }

        if ($suggestion->status !== 'pending') {
            return ['success' => false, 'error' => 'Suggestion already processed'];
        }

        $result = ['success' => false];

        try {
            switch ($suggestion->type) {
                case 'contact':
                    $result = $this->executeContactSuggestion($suggestion);
                    break;

                case 'calendar':
                    $result = $this->executeCalendarSuggestion($suggestion);
                    break;

                case 'bill':
                    $result = $this->executeBillSuggestion($suggestion);
                    break;

                default:
                    $result = ['success' => false, 'error' => 'Unknown suggestion type'];
            }

            if ($result['success']) {
                DB::update(
                    "UPDATE email_suggested_actions SET status = 'approved', approved_at = ?, updated_at = ? WHERE id = ?",
                    [now(), now(), $id]
                );
            }

        } catch (Exception $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        return $result;
    }

    /**
     * Reject a suggestion
     */
    public function rejectSuggestion(int $id, ?string $reason = null): bool
    {
        $updated = DB::update(
            "UPDATE email_suggested_actions
             SET status = 'rejected', rejected_at = ?, rejection_reason = ?, updated_at = ?
             WHERE id = ? AND status = 'pending'",
            [now(), $reason, now(), $id]
        );

        return $updated > 0;
    }

    /**
     * Execute contact suggestion - create Nextcloud contact
     */
    private function executeContactSuggestion(object $suggestion): array
    {
        try {
            $result = $this->contacts->createContact([
                'displayName' => $suggestion->contact_name ?: $suggestion->contact_email,
                'email' => $suggestion->contact_email,
            ]);

            return ['success' => true, 'contact_uid' => $result['uid'] ?? null];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute calendar suggestion - create Nextcloud event
     */
    private function executeCalendarSuggestion(object $suggestion): array
    {
        try {
            $result = $this->nextcloud->createEvent([
                'title' => $suggestion->event_title,
                'start' => Carbon::parse($suggestion->event_date),
                'end' => Carbon::parse($suggestion->event_date)->addHour(),
                'location' => $suggestion->event_location,
                'description' => $suggestion->description,
            ]);

            return ['success' => true, 'event_uid' => $result['uid'] ?? null];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute bill suggestion - create calendar reminder
     */
    private function executeBillSuggestion(object $suggestion): array
    {
        if (! $suggestion->bill_due_date) {
            return ['success' => false, 'error' => 'No due date specified'];
        }

        try {
            $title = "Bill Due: {$suggestion->bill_from}";
            if ($suggestion->bill_amount) {
                $title .= " - \${$suggestion->bill_amount}";
            }

            $result = $this->nextcloud->createEvent([
                'title' => $title,
                'start' => Carbon::parse($suggestion->bill_due_date),
                'end' => Carbon::parse($suggestion->bill_due_date)->addHour(),
                'description' => $suggestion->description,
            ]);

            return ['success' => true, 'event_uid' => $result['uid'] ?? null];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get suggestion statistics
     */
    public function getStats(): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN type = 'contact' AND status = 'pending' THEN 1 ELSE 0 END) as contacts_pending,
                SUM(CASE WHEN type = 'calendar' AND status = 'pending' THEN 1 ELSE 0 END) as calendar_pending,
                SUM(CASE WHEN type = 'bill' AND status = 'pending' THEN 1 ELSE 0 END) as bills_pending
             FROM email_suggested_actions"
        );

        return (array) $stats;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get notification setting
     */
    private function getSetting(string $key): ?array
    {
        $setting = DB::selectOne(
            'SELECT setting_value FROM email_notification_settings WHERE setting_key = ?',
            [$key]
        );

        if ($setting) {
            return json_decode($setting->setting_value, true);
        }

        return null;
    }

    /**
     * Update notification setting
     */
    public function updateSetting(string $key, array $value): bool
    {
        $updated = DB::update(
            'UPDATE email_notification_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
            [json_encode($value), now(), $key]
        );

        return $updated > 0;
    }

    /**
     * Update multiple notification settings at once
     */
    public function updateSettings(array $settings): array
    {
        $results = [];
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $results[$key] = $this->updateSetting($key, $value);
            }
        }

        return $results;
    }

    /**
     * Get all notification settings
     */
    public function getAllSettings(): array
    {
        $settings = DB::select('SELECT * FROM email_notification_settings');
        $result = [];

        foreach ($settings as $setting) {
            $result[$setting->setting_key] = [
                'value' => json_decode($setting->setting_value, true),
                'description' => $setting->description,
            ];
        }

        return $result;
    }

    /**
     * Extract email address from "Name <email>" format
     */
    private function extractEmailAddress(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return strtolower(trim($matches[1]));
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim($from));
        }

        return null;
    }

    /**
     * Extract name from "Name <email>" format
     */
    private function extractName(string $from): ?string
    {
        if (preg_match('/^([^<]+)</', $from, $matches)) {
            return trim($matches[1]);
        }

        return $this->extractEmailAddress($from);
    }

    /**
     * Check if address is a no-reply type
     */
    private function isNoReplyAddress(string $email): bool
    {
        $noReplyPatterns = [
            'noreply', 'no-reply', 'donotreply', 'do-not-reply',
            'notifications@', 'alert@', 'mailer@', 'automated@',
        ];

        foreach ($noReplyPatterns as $pattern) {
            if (str_contains(strtolower($email), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cleanup old processed suggestions
     */
    public function cleanupOldSuggestions(int $daysOld = 30): int
    {
        $cutoff = now()->subDays($daysOld);

        $deleted = DB::delete(
            "DELETE FROM email_suggested_actions
             WHERE status IN ('approved', 'rejected')
             AND updated_at < ?",
            [$cutoff]
        );

        if ($deleted > 0) {
            Log::info("EmailSuggestionService: Cleaned up {$deleted} old suggestions");
        }

        return $deleted;
    }

    // =========================================================================
    // FUTURE: Reply Suggestions (stubbed for future implementation)
    // =========================================================================

    /**
     * STUB: Check if email needs a reply suggestion
     *
     * Future implementation will:
     * - Detect emails requiring response (questions, requests, etc.)
     * - Generate AI draft replies based on context
     * - Queue drafts in email_reply_drafts for human approval
     * - Integrate with existing EmailService draft workflow
     *
     * @param  array  $email  Email data from Thunderbird
     * @param  array|null  $classification  AI classification results
     * @return int|null Suggestion ID if created, null otherwise
     */
    public function checkReplySuggestion(array $email, ?array $classification = null): ?int
    {
        // FUTURE: Implementation placeholder
        // This is intentionally stubbed - reply suggestions require additional
        // consideration for:
        // - Privacy (AI generating replies to personal emails)
        // - Integration with EmailService draft queue
        // - UI for editing AI-generated drafts
        // - Context retrieval for accurate replies

        Log::debug('EmailSuggestionService: Reply suggestions not yet implemented', [
            'email_subject' => $email['subject'] ?? 'Unknown',
        ]);

        return null;
    }

    /**
     * STUB: Generate AI reply draft for an email
     *
     * @param  array  $email  Original email to reply to
     * @param  string  $tone  Reply tone (professional, casual, brief)
     * @return array|null Draft data or null on failure
     */
    public function generateReplyDraft(array $email, string $tone = 'professional'): ?array
    {
        // FUTURE: Implementation placeholder
        // Would use AIService to generate contextual reply draft

        return null;
    }

    // =========================================================================
    // Cleanup Operations
    // =========================================================================

    /**
     * Full cleanup operation: expired suggestions + old classifications
     */
    public function cleanup(): array
    {
        $expired = $this->cleanupExpiredSuggestions();
        $oldClassifications = $this->cleanupOldClassifications();
        $oldSuggestions = $this->cleanupOldSuggestions();

        return [
            'expired' => $expired,
            'old_classifications' => $oldClassifications,
            'old_suggestions' => $oldSuggestions,
        ];
    }

    /**
     * Clean up expired suggestions (bills past due, old rejected, etc.)
     */
    private function cleanupExpiredSuggestions(): int
    {
        $deleted = 0;

        // Delete rejected suggestions older than 7 days
        $deleted += DB::delete(
            "DELETE FROM email_suggested_actions WHERE status = 'rejected' AND rejected_at < ?",
            [Carbon::now()->subDays(7)]
        );

        // Delete approved suggestions older than 30 days
        $deleted += DB::delete(
            "DELETE FROM email_suggested_actions WHERE status = 'approved' AND approved_at < ?",
            [Carbon::now()->subDays(30)]
        );

        // Delete bill suggestions with due dates in the past (more than 7 days ago)
        $deleted += DB::delete(
            "DELETE FROM email_suggested_actions WHERE type = 'bill' AND bill_due_date < ? AND status = 'pending'",
            [Carbon::now()->subDays(7)]
        );

        if ($deleted > 0) {
            Log::info("EmailSuggestionService: Cleaned up {$deleted} expired suggestions");
        }

        return $deleted;
    }

    /**
     * Clean up old classifications (older than 30 days)
     */
    private function cleanupOldClassifications(): int
    {
        $cutoff = Carbon::now()->subDays(30);

        $deleted = DB::delete(
            'DELETE FROM email_classifications WHERE created_at < ?',
            [$cutoff]
        );

        if ($deleted > 0) {
            Log::info("EmailSuggestionService: Cleaned up {$deleted} old classifications");
        }

        return $deleted;
    }
}
