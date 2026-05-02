<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EM-1: Bill/Payment Detection Service
 *
 * Analyzes email content to detect bills, invoices, and payment requests.
 * Extracts due dates, amounts, and payee information.
 * Creates calendar reminders for upcoming payments.
 *
 * Detection pipeline:
 *   1. Keyword heuristic pre-filter (fast, no LLM)
 *   2. AI extraction of structured bill data (amount, due date, payee)
 *   3. Calendar event creation for due dates
 *   4. Dedup: skip if same payee + amount + due date already tracked
 */
class BillDetectionService
{
    /** Keywords that suggest an email is a bill/invoice */
    private const BILL_KEYWORDS = [
        'invoice', 'bill', 'payment due', 'amount due', 'balance due',
        'statement', 'past due', 'overdue', 'pay by', 'due date',
        'autopay', 'auto-pay', 'automatic payment', 'payment confirmation',
        'payment reminder', 'account balance', 'minimum payment',
        'total due', 'please pay', 'remit payment', 'payment of',
    ];

    /** Keywords that suggest NOT a bill (exclusions) */
    private const EXCLUSION_KEYWORDS = [
        'unsubscribe', 'newsletter', 'sale', 'discount', 'promotion',
        'coupon', 'deal of', 'limited time', 'free trial',
    ];

    /** Amount pattern: $X,XXX.XX or similar */
    private const AMOUNT_PATTERN = '/\$\s*[\d,]+\.?\d{0,2}/';

    /** Date patterns for due dates */
    private const DATE_PATTERNS = [
        '/due\s+(?:by|on|date)?:?\s*(\w+\s+\d{1,2},?\s*\d{4})/i',
        '/pay\s+by:?\s*(\w+\s+\d{1,2},?\s*\d{4})/i',
        '/due\s+(?:by|on|date)?:?\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/i',
        '/(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:due|payment)/i',
    ];

    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    // =========================================================================
    // Main detection
    // =========================================================================

    /**
     * Analyze an email for bill/payment content.
     *
     * @param  array  $email  Email data with 'subject', 'from', 'body', 'date'
     * @return array Detection result with extracted bill data
     */
    public function detect(array $email): array
    {
        $subject = $email['subject'] ?? '';
        $from = $email['from'] ?? '';
        $body = $email['body'] ?? '';
        $date = $email['date'] ?? null;

        // Step 1: Keyword pre-filter
        $heuristic = $this->heuristicCheck($subject, $body);
        if (! $heuristic['is_likely_bill']) {
            return [
                'is_bill' => false,
                'confidence' => $heuristic['confidence'],
                'reason' => 'No bill indicators found',
            ];
        }

        // Step 2: Extract structured bill data
        $extracted = $this->extractBillData($subject, $from, $body);

        // Step 3: AI verification for ambiguous cases
        if ($heuristic['confidence'] < 0.7 || empty($extracted['amount'])) {
            $aiResult = $this->aiVerify($subject, $body);
            if ($aiResult) {
                $extracted = array_merge($extracted, array_filter($aiResult));
            }
        }

        $isBill = ! empty($extracted['amount']) || ! empty($extracted['due_date']);

        return [
            'is_bill' => $isBill,
            'confidence' => $isBill ? max($heuristic['confidence'], 0.7) : $heuristic['confidence'],
            'payee' => $extracted['payee'] ?? $this->extractPayee($from, $subject),
            'amount' => $extracted['amount'] ?? null,
            'due_date' => $extracted['due_date'] ?? null,
            'account' => $extracted['account'] ?? null,
            'bill_type' => $extracted['bill_type'] ?? $this->inferBillType($subject, $from),
            'email_date' => $date,
        ];
    }

    /**
     * Process a detected bill: store and optionally create calendar reminder.
     *
     * @param  array  $billData  Result from detect()
     * @param  int|null  $emailId  Optional email reference ID
     * @return array Processing result
     */
    public function processBill(array $billData, ?int $emailId = null): array
    {
        if (! $billData['is_bill']) {
            return ['success' => false, 'reason' => 'Not a bill'];
        }

        try {
            // Dedup check
            if ($this->isDuplicate($billData)) {
                return ['success' => true, 'action' => 'skipped', 'reason' => 'Duplicate bill'];
            }

            // Store bill record
            DB::insert('
                INSERT INTO detected_bills (payee, amount, due_date, bill_type, confidence, email_date, email_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ', [
                $billData['payee'],
                $billData['amount'],
                $billData['due_date'],
                $billData['bill_type'],
                $billData['confidence'],
                $billData['email_date'],
                $emailId,
            ]);

            $billId = DB::getPdo()->lastInsertId();
            $result = ['success' => true, 'action' => 'stored', 'bill_id' => (int) $billId];

            // Create calendar reminder if due date is in the future
            if (! empty($billData['due_date'])) {
                $calendarResult = $this->createCalendarReminder($billData);
                $result['calendar'] = $calendarResult;
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('BillDetection: Failed to process bill', [
                'payee' => $billData['payee'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Heuristic detection (pure — unit-testable)
    // =========================================================================

    /**
     * Fast keyword-based check for bill likelihood.
     */
    public function heuristicCheck(string $subject, string $body): array
    {
        $combined = strtolower($subject.' '.mb_substr($body, 0, 2000));

        // Check exclusions first
        foreach (self::EXCLUSION_KEYWORDS as $kw) {
            if (str_contains($combined, $kw)) {
                return ['is_likely_bill' => false, 'confidence' => 0.1, 'matched_keywords' => []];
            }
        }

        // Check bill keywords
        $matched = [];
        foreach (self::BILL_KEYWORDS as $kw) {
            if (str_contains($combined, $kw)) {
                $matched[] = $kw;
            }
        }

        // Has dollar amount?
        $hasAmount = (bool) preg_match(self::AMOUNT_PATTERN, $combined);

        $keywordCount = count($matched);
        $confidence = min(1.0, ($keywordCount * 0.2) + ($hasAmount ? 0.3 : 0));

        return [
            'is_likely_bill' => $keywordCount >= 1 || $hasAmount,
            'confidence' => round($confidence, 2),
            'matched_keywords' => $matched,
            'has_amount' => $hasAmount,
        ];
    }

    // =========================================================================
    // Data extraction (pure — unit-testable)
    // =========================================================================

    /**
     * Extract bill data from email content using regex patterns.
     */
    public function extractBillData(string $subject, string $from, string $body): array
    {
        $text = $subject."\n".$body;

        $amount = $this->extractAmount($text);
        $dueDate = $this->extractDueDate($text);
        $payee = $this->extractPayee($from, $subject);

        return [
            'amount' => $amount,
            'due_date' => $dueDate,
            'payee' => $payee,
            'account' => null,
            'bill_type' => $this->inferBillType($subject, $from),
        ];
    }

    /**
     * Extract dollar amount from text.
     */
    public function extractAmount(string $text): ?string
    {
        if (preg_match_all(self::AMOUNT_PATTERN, $text, $matches)) {
            // Return the largest amount (likely the total due)
            $amounts = array_map(function ($a) {
                return (float) str_replace(['$', ',', ' '], '', $a);
            }, $matches[0]);

            $maxIdx = array_search(max($amounts), $amounts);

            return $matches[0][$maxIdx];
        }

        return null;
    }

    /**
     * Extract due date from text.
     */
    public function extractDueDate(string $text): ?string
    {
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                try {
                    $date = new \DateTime($m[1]);

                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    // Try next pattern
                }
            }
        }

        return null;
    }

    /**
     * Extract payee name from sender or subject.
     */
    public function extractPayee(string $from, string $subject): string
    {
        // Try sender display name first
        if (preg_match('/^"?([^"<]+)"?\s*</', $from, $m)) {
            $name = trim($m[1]);
            if (strlen($name) > 2 && ! str_contains(strtolower($name), 'noreply')) {
                return $name;
            }
        }

        // Extract domain from email
        if (preg_match('/@([\w.-]+)/', $from, $m)) {
            $domain = $m[1];
            $parts = explode('.', $domain);
            if (count($parts) >= 2) {
                return ucfirst($parts[count($parts) - 2]);
            }
        }

        return mb_substr($subject, 0, 50);
    }

    /**
     * Infer bill type from subject/sender.
     */
    public function inferBillType(string $subject, string $from): string
    {
        $combined = strtolower($subject.' '.$from);

        $typeMap = [
            'utility' => ['electric', 'gas', 'water', 'sewer', 'utility', 'power', 'energy'],
            'telecom' => ['phone', 'mobile', 'wireless', 'internet', 'broadband', 'cable', 'spectrum', 'att', 'verizon', 'tmobile'],
            'insurance' => ['insurance', 'premium', 'policy', 'geico', 'allstate', 'progressive'],
            'mortgage' => ['mortgage', 'home loan', 'escrow'],
            'rent' => ['rent', 'lease', 'landlord', 'property management'],
            'medical' => ['medical', 'hospital', 'doctor', 'health', 'dental', 'pharmacy', 'copay'],
            'subscription' => ['subscription', 'membership', 'netflix', 'spotify', 'adobe', 'microsoft'],
            'credit_card' => ['credit card', 'visa', 'mastercard', 'amex', 'capital one', 'chase', 'citi'],
            'tax' => ['tax', 'irs', 'property tax', 'assessment'],
        ];

        foreach ($typeMap as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($combined, $kw)) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    // =========================================================================
    // AI verification
    // =========================================================================

    private function aiVerify(string $subject, string $body): ?array
    {
        try {
            $truncated = mb_substr($body, 0, 3000);
            $prompt = "Is this email a bill, invoice, or payment request? Extract the details.\n\n"
                ."Subject: {$subject}\n"
                ."Body:\n{$truncated}\n\n"
                ."Output ONLY valid JSON:\n"
                .'{"is_bill": true/false, "amount": "$X.XX" or null, "due_date": "YYYY-MM-DD" or null, "payee": "name" or null, "bill_type": "type" or null}';

            $result = $this->ai->process($prompt, [
                'max_tokens' => 150,
                'temperature' => 0,
                'expect_json' => true,
                'model_role' => 'fast',
                'suppress_alert' => true,
            ]);

            if (! ($result['success'] ?? false)) {
                return null;
            }

            $raw = trim($result['response'] ?? '');
            if (str_starts_with($raw, '```')) {
                $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
                $raw = preg_replace('/\n?```$/', '', $raw);
            }

            return json_decode(trim($raw), true);

        } catch (\Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // Calendar integration
    // =========================================================================

    private function createCalendarReminder(array $billData): array
    {
        try {
            $dueDate = new \DateTime($billData['due_date']);

            // Skip if due date is in the past
            if ($dueDate < new \DateTime('today')) {
                return ['created' => false, 'reason' => 'Due date is in the past'];
            }

            // Create reminder 3 days before due date
            $reminderDate = (clone $dueDate)->modify('-3 days');
            $payee = $billData['payee'] ?? 'Unknown';
            $amount = $billData['amount'] ?? '';
            $externalId = 'bill_detection:'.sha1(strtolower($payee).'|'.$amount.'|'.$dueDate->format('Y-m-d'));

            DB::insert("
                INSERT INTO calendar_events (external_id, calendar_name, title, description, start_at, end_at, all_day, created_at, updated_at)
                VALUES (?, 'bill_detection', ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    start_at = VALUES(start_at),
                    end_at = VALUES(end_at),
                    updated_at = NOW()
            ", [
                $externalId,
                "Bill Due: {$payee}".($amount ? " ({$amount})" : ''),
                "Payment due {$dueDate->format('M j, Y')} for {$payee}. Amount: {$amount}",
                $reminderDate->format('Y-m-d 09:00:00'),
                $dueDate->format('Y-m-d 09:00:00'),
            ]);

            return ['created' => true, 'reminder_date' => $reminderDate->format('Y-m-d')];

        } catch (\Throwable $e) {
            Log::warning('BillDetection: Calendar reminder failed', ['error' => $e->getMessage()]);

            return ['created' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Dedup
    // =========================================================================

    private function isDuplicate(array $billData): bool
    {
        try {
            $existing = DB::selectOne('
                SELECT id FROM detected_bills
                WHERE payee = ? AND amount = ? AND due_date = ?
                LIMIT 1
            ', [$billData['payee'], $billData['amount'], $billData['due_date']]);

            return ! empty($existing);
        } catch (\Throwable $e) {
            return false; // Table may not exist yet
        }
    }
}
