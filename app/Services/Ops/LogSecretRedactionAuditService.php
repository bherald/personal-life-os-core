<?php

namespace App\Services\Ops;

use App\Logging\RedactSensitiveLogContext;
use App\Logging\SensitiveLogContextProcessor;
use DateTimeImmutable;
use LogicException;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;

class LogSecretRedactionAuditService
{
    public function __construct(
        private readonly SensitiveLogContextProcessor $processor,
        private readonly LogMessageSourceAuditService $messageSourceAudit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $exception = new RuntimeException(
            'synthetic private diagnostic',
            27,
            new LogicException('synthetic private previous diagnostic'),
        );
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'audit',
            level: Level::Info,
            message: 'Synthetic in-memory log redaction audit',
            context: [
                'token' => 'short',
                'provider' => [
                    'apiKey' => 'test-api-key',
                    'query' => 'synthetic-private-query',
                    'generated_queries' => ['synthetic-private-sub-query'],
                    'system_prompt' => 'synthetic-private-prompt',
                    'claim_preview' => 'synthetic-private-claim',
                    'query_count' => 2,
                    'query_fingerprint' => 'hmac-sha256:synthetic-safe-receipt',
                    'headers' => [
                        'Authorization' => 'Bearer synthetic',
                        'Content-Type' => 'application/json',
                    ],
                    'prompt_tokens' => 37,
                ],
                'error' => 'synthetic private error detail',
                'message' => 'synthetic private message detail',
                'exception' => $exception,
            ],
            extra: [
                'private-key' => 'synthetic-private-key',
                'trace_id' => 'trace-safe',
            ],
        );

        $redacted = ($this->processor)($record);
        $checks = [
            'root_short_token_redacted' => ($redacted->context['token'] ?? null) === SensitiveLogContextProcessor::REDACTED,
            'nested_camel_case_api_key_redacted' => ($redacted->context['provider']['apiKey'] ?? null) === SensitiveLogContextProcessor::REDACTED,
            'structured_query_redacted' => ($redacted->context['provider']['query'] ?? null) === SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED,
            'structured_query_list_redacted' => ($redacted->context['provider']['generated_queries'] ?? null) === SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED,
            'structured_prompt_redacted' => ($redacted->context['provider']['system_prompt'] ?? null) === SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED,
            'structured_claim_preview_redacted' => ($redacted->context['provider']['claim_preview'] ?? null) === SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED,
            'query_count_preserved' => ($redacted->context['provider']['query_count'] ?? null) === 2,
            'query_fingerprint_preserved' => ($redacted->context['provider']['query_fingerprint'] ?? null) === 'hmac-sha256:synthetic-safe-receipt',
            'authorization_header_redacted' => ($redacted->context['provider']['headers']['Authorization'] ?? null) === SensitiveLogContextProcessor::REDACTED,
            'benign_header_preserved' => ($redacted->context['provider']['headers']['Content-Type'] ?? null) === 'application/json',
            'token_metric_preserved' => ($redacted->context['provider']['prompt_tokens'] ?? null) === 37,
            'structured_error_redacted' => ($redacted->context['error'] ?? null) === SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED,
            'structured_message_redacted' => ($redacted->context['message'] ?? null) === SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED,
            'exception_object_replaced_with_safe_array' => is_array($redacted->context['exception'] ?? null),
            'exception_type_preserved' => ($redacted->context['exception']['type'] ?? null) === RuntimeException::class,
            'exception_code_preserved' => ($redacted->context['exception']['code'] ?? null) === 27,
            'exception_previous_type_preserved' => ($redacted->context['exception']['previous_types'] ?? null) === [LogicException::class],
            'exception_message_omitted' => ($redacted->context['exception']['message_logged'] ?? null) === false,
            'exception_stacktrace_omitted' => ($redacted->context['exception']['stacktrace_logged'] ?? null) === false,
            'extra_private_key_redacted' => ($redacted->extra['private-key'] ?? null) === SensitiveLogContextProcessor::REDACTED,
            'extra_trace_id_preserved' => ($redacted->extra['trace_id'] ?? null) === 'trace-safe',
        ];

        $unprotectedChannels = $this->unprotectedConfiguredChannels();
        $checks['configured_log_sinks_protected'] = $unprotectedChannels === [];
        $sourceAudit = $this->messageSourceAudit->scanApplication();
        $sourceAudit['dynamic_message_sites_max'] = (int) config('logging.source_audit.dynamic_message_sites_max', 0);
        $sourceAudit['placeholder_template_message_sites_max'] = (int) config('logging.source_audit.placeholder_template_message_sites_max', 0);
        $checks['source_files_readable'] = $sourceAudit['php_files_read_failed'] === 0;
        $checks['source_files_parseable'] = $sourceAudit['php_files_parse_failed'] === 0;
        $checks['source_log_calls_detected'] = $sourceAudit['log_calls'] > 0;
        $checks['direct_exception_message_interpolation_absent'] = $sourceAudit['direct_exception_detail_message_sites'] === 0;
        $checks['dynamic_message_sites_within_baseline'] = $sourceAudit['dynamic_message_sites_max'] > 0
            && $sourceAudit['dynamic_message_sites'] <= $sourceAudit['dynamic_message_sites_max'];
        $checks['placeholder_template_message_sites_within_baseline'] = $sourceAudit['placeholder_template_message_sites']
            <= $sourceAudit['placeholder_template_message_sites_max'];

        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));

        return [
            'schema' => 'ops_log_secret_redaction_audit.v3',
            'status' => $failed === [] ? 'pass' : 'fail',
            'mode' => 'read_only_synthetic',
            'summary' => [
                'checks' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
                'configured_channels' => count((array) config('logging.channels', [])),
                'audit_eligible_channels' => count($this->auditEligibleConfiguredChannels()),
                'unprotected_channels' => count($unprotectedChannels),
            ],
            'failed_checks' => $failed,
            'unprotected_channels' => $unprotectedChannels,
            'structured_private_text_key_redaction' => true,
            'structured_diagnostic_text_key_redaction' => true,
            'source_audit' => $sourceAudit,
            'free_form_message_expression_classification_covered' => true,
            'free_form_message_body_covered' => false,
            'direct_exception_message_interpolation_covered' => true,
            'exception_object_body_covered' => true,
            'framework_emergency_fallback_covered' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function unprotectedConfiguredChannels(): array
    {
        $unprotected = [];

        foreach ((array) config('logging.channels', []) as $name => $channel) {
            if ($name === 'emergency' || ($channel['driver'] ?? null) === 'stack') {
                continue;
            }

            if (! in_array(RedactSensitiveLogContext::class, (array) ($channel['tap'] ?? []), true)) {
                $unprotected[] = (string) $name;
            }
        }

        sort($unprotected);

        return $unprotected;
    }

    /** @return list<string> */
    private function auditEligibleConfiguredChannels(): array
    {
        $eligible = [];

        foreach ((array) config('logging.channels', []) as $name => $channel) {
            if ($name === 'emergency' || ($channel['driver'] ?? null) === 'stack') {
                continue;
            }

            $eligible[] = (string) $name;
        }

        sort($eligible);

        return $eligible;
    }
}
