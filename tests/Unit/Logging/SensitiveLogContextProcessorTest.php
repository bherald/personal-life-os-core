<?php

namespace Tests\Unit\Logging;

use App\Logging\RedactSensitiveLogContext;
use App\Logging\SensitiveLogContextProcessor;
use DateTimeImmutable;
use Illuminate\Log\Logger as IlluminateLogger;
use LogicException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

class SensitiveLogContextProcessorTest extends TestCase
{
    public function test_it_recursively_redacts_sensitive_key_variants_and_preserves_diagnostics(): void
    {
        $exception = new RuntimeException('private exception detail', 27, new LogicException('private previous detail'));
        $processor = new SensitiveLogContextProcessor;
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'test',
            context: [
                'token' => 'x',
                'provider' => [
                    'apiKey' => 'api-secret',
                    'providerApiToken' => 'test-provider-token',
                    'test-client-secret' => 'test-client-secret',
                    'aws_secret_access_key' => 'test-cloud-secret',
                    'headers' => [
                        'Authorization' => 'Bearer secret',
                        'Content-Type' => 'application/json',
                    ],
                ],
                'callback' => (object) [
                    'webhook_url' => 'https://example.test/test-hook',
                    'status' => 'ready',
                ],
                'exception' => $exception,
            ],
            extra: ['privateKey' => 'test-private-secret'],
        );

        $result = $processor($record);

        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['token']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['provider']['apiKey']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['provider']['providerApiToken']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['provider']['test-client-secret']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['provider']['aws_secret_access_key']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['provider']['headers']['Authorization']);
        $this->assertSame('application/json', $result->context['provider']['headers']['Content-Type']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->context['callback']->webhook_url);
        $this->assertSame('ready', $result->context['callback']->status);
        $this->assertSame(RuntimeException::class, $result->context['exception']['type']);
        $this->assertSame(27, $result->context['exception']['code']);
        $this->assertSame([LogicException::class], $result->context['exception']['previous_types']);
        $this->assertFalse($result->context['exception']['chain_truncated']);
        $this->assertFalse($result->context['exception']['message_logged']);
        $this->assertFalse($result->context['exception']['stacktrace_logged']);
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $result->extra['privateKey']);
        $this->assertSame('x', $record->context['token'], 'The processor must return a new immutable record.');
    }

    public function test_it_preserves_known_token_metrics(): void
    {
        $metrics = [
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'token_count' => 31,
            'token_budget' => 40,
            'tokens_per_second' => 12.5,
            'remaining_tokens' => 9,
            'trace_id' => 'trace-safe',
        ];

        $this->assertSame($metrics, (new SensitiveLogContextProcessor)->redact($metrics));
    }

    public function test_it_redacts_private_text_keys_without_hiding_safe_metrics_or_receipts(): void
    {
        $result = (new SensitiveLogContextProcessor)->redact([
            'query' => 'private search',
            'nested' => [
                'sub_query' => 'private sub-query',
                'userPrompt' => 'private prompt',
                'claim_text' => 'private claim',
                'queries' => ['private generated query'],
                'prompt_prefix' => 'private prompt prefix',
            ],
            'query_count' => 3,
            'query_chars' => 42,
            'prompt_tokens' => 9,
            'query_fingerprint' => 'hmac-sha256:safe',
        ]);

        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['query']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['nested']['sub_query']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['nested']['userPrompt']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['nested']['claim_text']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['nested']['queries']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['nested']['prompt_prefix']);
        $this->assertSame(3, $result['query_count']);
        $this->assertSame(42, $result['query_chars']);
        $this->assertSame(9, $result['prompt_tokens']);
        $this->assertSame('hmac-sha256:safe', $result['query_fingerprint']);
    }

    public function test_it_redacts_structured_diagnostic_text_but_preserves_bounded_failure_codes(): void
    {
        $result = (new SensitiveLogContextProcessor)->redact([
            'error' => 'private provider response',
            'write_error' => 'private database response',
            'errors' => ['private validation response'],
            'message' => 'private exception message',
            'exception_message' => 'private nested exception message',
            'error_code' => 'provider_timeout',
            'error_count' => 2,
            'exception_type' => RuntimeException::class,
            'failure_reason' => 'bounded_reason_code',
        ]);

        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['error']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['write_error']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['errors']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['message']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $result['exception_message']);
        $this->assertSame('provider_timeout', $result['error_code']);
        $this->assertSame(2, $result['error_count']);
        $this->assertSame(RuntimeException::class, $result['exception_type']);
        $this->assertSame('bounded_reason_code', $result['failure_reason']);
    }

    public function test_formatted_throwable_context_contains_no_message_trace_or_previous_message(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Stable failure event',
            context: [
                'exception' => new RuntimeException(
                    'private-exception-marker',
                    27,
                    new LogicException('private-previous-marker'),
                ),
            ],
        );

        $formatted = (new LineFormatter)->format((new SensitiveLogContextProcessor)($record));

        $this->assertStringContainsString(RuntimeException::class, $formatted);
        $this->assertStringContainsString(LogicException::class, $formatted);
        $this->assertStringNotContainsString('private-exception-marker', $formatted);
        $this->assertStringNotContainsString('private-previous-marker', $formatted);
        $this->assertStringNotContainsString(__FILE__, $formatted);
    }

    public function test_laravel_log_tap_redacts_before_a_handler_receives_context(): void
    {
        $handler = new TestHandler;
        $monolog = new Logger('test', [$handler]);
        $logger = new IlluminateLogger($monolog);
        $tap = new RedactSensitiveLogContext(new SensitiveLogContextProcessor);
        $tap($logger);

        $logger->info('Approval completed', [
            'token' => 'tiny',
            'query' => 'private genealogy search',
            'error' => 'private provider response',
            'exception' => new RuntimeException('private exception marker', 19),
            'query_count' => 2,
            'query_fingerprint' => 'hmac-sha256:safe',
            'review_id' => 42,
        ]);

        $record = $handler->getRecords()[0];
        $this->assertSame(SensitiveLogContextProcessor::REDACTED, $record->context['token']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $record->context['query']);
        $this->assertSame(SensitiveLogContextProcessor::PRIVATE_TEXT_REDACTED, $record->context['error']);
        $this->assertSame(RuntimeException::class, $record->context['exception']['type']);
        $this->assertSame(19, $record->context['exception']['code']);
        $this->assertFalse($record->context['exception']['message_logged']);
        $this->assertSame(2, $record->context['query_count']);
        $this->assertSame('hmac-sha256:safe', $record->context['query_fingerprint']);
        $this->assertSame(42, $record->context['review_id']);
    }

    public function test_every_audit_eligible_configured_log_sink_has_the_redaction_tap(): void
    {
        foreach ((array) config('logging.channels') as $name => $channel) {
            if ($name === 'emergency' || ($channel['driver'] ?? null) === 'stack') {
                continue;
            }

            $this->assertContains(
                RedactSensitiveLogContext::class,
                (array) ($channel['tap'] ?? []),
                "Logging channel {$name} must redact structured secrets.",
            );
        }
    }
}
