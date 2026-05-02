<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'email_service_status',
                'service_class' => 'App\\Services\\EmailService',
                'method' => 'getStatus',
                'description' => 'Get comprehensive email service status including Thunderbird MCP connectivity, circuit breaker state, classification service availability, and draft queue overview.',
                'parameters' => '[]',
                'returns_description' => 'Array with connection status, circuit breaker state, classification stats, draft queue summary, and service settings',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_analytics_dashboard',
                'service_class' => 'App\\Services\\EmailAnalyticsService',
                'method' => 'getDashboardMetrics',
                'description' => 'Get email analytics dashboard with volume trends, AI classification accuracy, response times, failure rates, and daily trends over configurable period.',
                'parameters' => json_encode([
                    'days' => ['type' => 'integer', 'required' => false, 'default' => 30, 'description' => 'Number of days to analyze (default 30)'],
                ]),
                'returns_description' => 'Array with summary stats, status breakdown, source breakdown, AI accuracy metrics, response times, and daily trend data',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_bounce_stats',
                'service_class' => 'App\\Services\\EmailBounceService',
                'method' => 'getStats',
                'description' => 'Get bounce statistics including total bounces, hard/soft breakdown, complaint count, suppression list size, and pending retry count.',
                'parameters' => '[]',
                'returns_description' => 'Array with total bounces, hard count, soft count, complaints, suppression list size, pending retries',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_rate_limit_stats',
                'service_class' => 'App\\Services\\EmailRateLimitService',
                'method' => 'getStats',
                'description' => 'Get rate limit statistics including total mailboxes tracked, daily send counts, mailboxes in cooldown, and active domain throttles.',
                'parameters' => '[]',
                'returns_description' => 'Array with total mailboxes, daily sends, cooldown count, domain throttle count',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_draft_queue_stats',
                'service_class' => 'App\\Services\\EmailService',
                'method' => 'getDraftQueueStats',
                'description' => 'Get draft queue statistics including counts by status (pending, approved, rejected, sent) and by source (workflow, AI-generated, manual).',
                'parameters' => '[]',
                'returns_description' => 'Array with draft counts by status, by source, and aging information',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_followup_stats',
                'service_class' => 'App\\Services\\EmailFollowUpService',
                'method' => 'getStats',
                'description' => 'Get follow-up tracking statistics including total follow-ups, counts by status, overdue count, and average reminders sent.',
                'parameters' => '[]',
                'returns_description' => 'Array with total follow-ups, by status, overdue count, average reminders per follow-up',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_urgent_emails',
                'service_class' => 'App\\Services\\EmailSentimentService',
                'method' => 'getUrgentEmails',
                'description' => 'Get emails flagged as urgent by sentiment analysis. Returns emails with high urgency scores that may need immediate attention.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum number of urgent emails to return (default 50)'],
                ]),
                'returns_description' => 'Array of urgent emails with ID, subject, sender, urgency score, and timestamp',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_sentiment_stats',
                'service_class' => 'App\\Services\\EmailSentimentService',
                'method' => 'getSentimentStats',
                'description' => 'Get sentiment analysis statistics including distribution across labels, averages by category, pending analysis backlog, and urgent email count in last 24 hours.',
                'parameters' => '[]',
                'returns_description' => 'Array with sentiment distribution, averages by label, pending analysis count, urgent count 24h',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'email_pending_drafts',
                'service_class' => 'App\\Services\\EmailService',
                'method' => 'getPendingDrafts',
                'description' => 'Get pending email drafts awaiting approval. Can filter by source and priority. Returns draft details including age and origin.',
                'parameters' => json_encode([
                    'filters' => ['type' => 'object', 'required' => false, 'default' => [], 'description' => 'Optional filters: source (workflow/ai/manual), priority (low/normal/high/urgent)'],
                ]),
                'returns_description' => 'Array of pending drafts with ID, subject, recipient, source, priority, and created timestamp',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_overdue_followups',
                'service_class' => 'App\\Services\\EmailFollowUpService',
                'method' => 'getOverdueFollowUps',
                'description' => 'Get follow-ups that are past their expected reply date. Helps identify emails that need human attention or re-sending.',
                'parameters' => '[]',
                'returns_description' => 'Array of overdue follow-ups with ID, email subject, expected date, days overdue, reminder count',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_process_reminders',
                'service_class' => 'App\\Services\\EmailFollowUpService',
                'method' => 'processReminders',
                'description' => 'Process all pending follow-up reminders that are due. Sends reminder notifications for overdue follow-ups. This is a scheduled maintenance action.',
                'parameters' => '[]',
                'returns_description' => 'Array with count of reminders processed, sent, and any failures',
                'permissions' => '["email:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'email',
            ],
            [
                'name' => 'email_pending_retries',
                'service_class' => 'App\\Services\\EmailBounceService',
                'method' => 'getPendingRetries',
                'description' => 'Get soft-bounced emails awaiting retry. Shows emails that failed delivery but may succeed on retry after backoff period.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum number of pending retries to return (default 50)'],
                ]),
                'returns_description' => 'Array of pending retries with ID, recipient, bounce reason, retry count, next retry timestamp',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_high_risk_senders',
                'service_class' => 'App\\Services\\SenderProfileService',
                'method' => 'getHighRiskSenders',
                'description' => 'Get senders with high spam/risk scores (>0.7). Identifies potentially malicious or unwanted senders for review.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum number of high-risk senders to return (default 50)'],
                ]),
                'returns_description' => 'Array of high-risk sender profiles with email, domain, spam score, trust score, interaction count',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_unsubscribeable_senders',
                'service_class' => 'App\\Services\\EmailUnsubscribeService',
                'method' => 'getUnsubscribeableSenders',
                'description' => 'Get senders that have unsubscribe links available, grouped by domain. Helps identify newsletter cleanup opportunities.',
                'parameters' => '[]',
                'returns_description' => 'Array of senders grouped by domain with unsubscribe link count, method types (one-click, mailto, HTTP)',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_search',
                'service_class' => 'App\\Services\\EmailService',
                'method' => 'searchEmails',
                'description' => 'Search emails by query string across folders. Use for targeted investigation of specific senders, domains, or patterns identified during assessment.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query (sender, subject, or content keywords)'],
                    'folder' => ['type' => 'string', 'required' => false, 'default' => null, 'description' => 'Specific folder to search (null = all folders)'],
                ]),
                'returns_description' => 'Array of matching emails with ID, subject, sender, date, folder, and snippet',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
            [
                'name' => 'email_scheduled_upcoming',
                'service_class' => 'App\\Services\\EmailScheduledService',
                'method' => 'getUpcomingEmails',
                'description' => 'Get emails scheduled to be sent in the next N hours. Provides awareness of upcoming outbound email activity.',
                'parameters' => json_encode([
                    'hours' => ['type' => 'integer', 'required' => false, 'default' => 24, 'description' => 'Hours ahead to look (default 24)'],
                ]),
                'returns_description' => 'Array of upcoming scheduled emails with ID, recipient, subject, scheduled time, and status',
                'permissions' => '["email:read"]',
                'risk_level' => 'read',
                'category' => 'email',
            ],
        ];

        foreach ($tools as $tool) {
            try {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, enabled, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config')
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ]);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }

        // Add scheduled job for email-ops agent
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'email_ops_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'email_ops_agent',
                'Email system health monitoring: bounce rates, rate limits, draft queue, follow-ups, sender reputation',
                'email-ops',
                '*/30 * * * *',
                'agent_task',
                1,
                'Agent',
                10,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }
    }

    public function down(): void
    {
        $toolNames = [
            'email_service_status', 'email_analytics_dashboard', 'email_bounce_stats',
            'email_rate_limit_stats', 'email_draft_queue_stats', 'email_followup_stats',
            'email_urgent_emails', 'email_sentiment_stats', 'email_pending_drafts',
            'email_overdue_followups', 'email_process_reminders', 'email_pending_retries',
            'email_high_risk_senders', 'email_unsubscribeable_senders', 'email_search',
            'email_scheduled_upcoming',
        ];

        $placeholders = implode(',', array_fill(0, count($toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $toolNames);
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'email_ops_agent'");
    }
};
