<?php

namespace Tests\Unit\Nodes;

use App\Controllers\NotificationController;
use App\Exceptions\NodeTimeoutException;
use App\Nodes\PushoverNotify;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PushoverNotifyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_retries_failed_chunks_before_marking_notification_failed(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->times(3)
            ->andReturn(
                ['success' => true],
                ['success' => false],
                ['success' => true],
            );

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => str_repeat('A', 1200),
            'max_retries_per_chunk' => 2,
            'retry_delay_seconds' => 0,
            'inter_chunk_delay_seconds' => 0,
        ]);

        $result = $node->execute([]);

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
        $this->assertSame('workflow_node_notifications', $result['data']['source_group']);
        $this->assertSame('workflow_node_notifications', $result['meta']['source_group']);
        $this->assertSame(2, $result['data']['total_parts']);
        $this->assertSame(2, $result['data']['parts_sent']);
        $this->assertSame([2, 1], $result['data']['part_numbers_sent']);
        $this->assertSame([], $result['data']['part_numbers_failed']);
        $this->assertSame(0, $result['data']['inter_chunk_delay_seconds']);
    }

    public function test_multipart_payloads_are_distinct_and_sent_in_reverse_order(): void
    {
        $payloads = [];
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->times(3)
            ->with('pushover', Mockery::on(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return true;
            }))
            ->andReturn(['success' => true]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => implode("\n\n", [
                'First packet '.str_repeat('A', 980),
                'Second packet '.str_repeat('B', 980),
                'Third packet '.str_repeat('C', 980),
            ]),
            'url' => 'https://example.test/news',
            'url_title' => 'Open News',
            'source_group' => 'workflow_routine_updates',
            'inter_chunk_delay_seconds' => 0,
        ]);

        $result = $node->execute([]);

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
        $this->assertSame([3, 2, 1], $result['data']['part_numbers_sent']);
        $this->assertCount(3, $payloads);
        $this->assertSame('Daily News (Part 3/3)', $payloads[0]['title']);
        $this->assertSame('Daily News (Part 2/3)', $payloads[1]['title']);
        $this->assertSame('Daily News (Part 1/3)', $payloads[2]['title']);
        $this->assertStringStartsWith('Third packet', $payloads[0]['message']);
        $this->assertStringStartsWith('Second packet', $payloads[1]['message']);
        $this->assertStringStartsWith('First packet', $payloads[2]['message']);
        $this->assertArrayNotHasKey('url', $payloads[0]);
        $this->assertArrayNotHasKey('url', $payloads[1]);
        $this->assertSame('https://example.test/news', $payloads[2]['url']);
        $this->assertSame('workflow_routine_updates', $payloads[0]['source_group']);
        $this->assertSame('workflow_routine_updates', $result['data']['source_group']);
        $this->assertSame('workflow_routine_updates', $result['meta']['source_group']);
    }

    public function test_multipart_payloads_can_include_ordering_timestamps(): void
    {
        $payloads = [];
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->times(3)
            ->with('pushover', Mockery::on(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return true;
            }))
            ->andReturn(['success' => true]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => implode("\n\n", [
                'First packet '.str_repeat('A', 980),
                'Second packet '.str_repeat('B', 980),
                'Third packet '.str_repeat('C', 980),
            ]),
            'part_timestamps_enabled' => true,
            'inter_chunk_delay_seconds' => 0,
        ]);

        $result = $node->execute([]);

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
        $this->assertTrue($result['data']['part_timestamps_enabled']);
        $this->assertSame('ascending_display_order', $result['data']['part_timestamp_strategy']);
        $this->assertCount(3, $payloads);
        $this->assertSame('Daily News (Part 3/3)', $payloads[0]['title']);
        $this->assertSame('Daily News (Part 2/3)', $payloads[1]['title']);
        $this->assertSame('Daily News (Part 1/3)', $payloads[2]['title']);
        $this->assertArrayHasKey('timestamp', $payloads[0]);
        $this->assertSame(1, $payloads[1]['timestamp'] - $payloads[0]['timestamp']);
        $this->assertSame(1, $payloads[2]['timestamp'] - $payloads[1]['timestamp']);
        $this->assertSame([
            3 => $payloads[0]['timestamp'],
            2 => $payloads[1]['timestamp'],
            1 => $payloads[2]['timestamp'],
        ], $result['data']['part_timestamps']);
    }

    public function test_multipart_http_posts_are_distinct_and_sent_in_reverse_order(): void
    {
        config([
            'services.pushover.api_url' => 'https://api.pushover.net/1/messages.json',
            'services.pushover.token' => 'test-token',
            'services.pushover.user_key' => 'test-user',
        ]);
        Cache::forget('pushover_rate_limit:workflow_routine_updates');
        Http::fake([
            'https://api.pushover.net/*' => Http::response(['status' => 1], 200),
        ]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => implode("\n\n", [
                'First packet '.str_repeat('A', 980),
                'Second packet '.str_repeat('B', 980),
                'Third packet '.str_repeat('C', 980),
            ]),
            'source_group' => 'workflow_routine_updates',
            'inter_chunk_delay_seconds' => 0,
        ]);

        $result = $node->execute([]);
        $requests = Http::recorded();

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
        $this->assertSame([3, 2, 1], $result['data']['part_numbers_sent']);
        $this->assertSame('workflow_routine_updates', $result['data']['source_group']);
        $this->assertCount(3, $requests);
        $this->assertSame('Daily News (Part 3/3)', $requests[0][0]['title']);
        $this->assertSame('Daily News (Part 2/3)', $requests[1][0]['title']);
        $this->assertSame('Daily News (Part 1/3)', $requests[2][0]['title']);
        $this->assertStringStartsWith('Third packet', $requests[0][0]['message']);
        $this->assertStringStartsWith('Second packet', $requests[1][0]['message']);
        $this->assertStringStartsWith('First packet', $requests[2][0]['message']);
    }

    public function test_large_news_multipart_http_posts_record_distinct_part_fingerprints(): void
    {
        config([
            'services.pushover.api_url' => 'https://api.pushover.net/1/messages.json',
            'services.pushover.token' => 'test-token',
            'services.pushover.user_key' => 'test-user',
        ]);
        Cache::forget('pushover_rate_limit:workflow_routine_updates');

        $sendIndex = 0;
        Http::fake(function () use (&$sendIndex) {
            $sendIndex++;

            return Http::response(['status' => 1, 'request' => "request-{$sendIndex}"], 200);
        });

        $sections = [];
        for ($part = 1; $part <= 20; $part++) {
            $sections[$part] = sprintf('Packet %02d ', $part).str_repeat(chr(64 + (($part - 1) % 26) + 1), 980);
        }

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => implode("\n\n", $sections),
            'source_group' => 'workflow_routine_updates',
            'inter_chunk_delay_seconds' => 0,
            'part_timestamps_enabled' => true,
        ]);

        $result = $node->execute([]);
        $requests = Http::recorded();

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
        $this->assertSame(20, $result['data']['total_parts']);
        $this->assertSame(20, $result['data']['parts_sent']);
        $this->assertSame(range(20, 1), $result['data']['part_numbers_sent']);
        $this->assertCount(20, $requests);
        $this->assertSame('Daily News (Part 20/20)', $requests[0][0]['title']);
        $this->assertSame('Daily News (Part 1/20)', $requests[19][0]['title']);
        $this->assertStringStartsWith('Packet 20', $requests[0][0]['message']);
        $this->assertStringStartsWith('Packet 01', $requests[19][0]['message']);
        $this->assertSame(range(20, 1), array_keys($result['data']['part_message_hashes']));
        $this->assertSame(range(20, 1), array_keys($result['data']['part_message_lengths']));
        $this->assertSame(range(20, 1), array_keys($result['data']['part_response_requests']));
        $this->assertCount(20, array_unique($result['data']['part_message_hashes']));
        $this->assertSame(strlen($sections[20]), $result['data']['part_message_lengths'][20]);
        $this->assertSame(hash('sha256', $sections[20]), $result['data']['part_message_hashes'][20]);
        $this->assertSame('request-1', $result['data']['part_response_requests'][20]);
        $this->assertSame('request-20', $result['data']['part_response_requests'][1]);
    }

    public function test_uses_configured_source_group_when_present(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->once()
            ->with('pushover', Mockery::on(function (array $payload) {
                return ($payload['source_group'] ?? null) === 'workflow_routine_updates';
            }))
            ->andReturn(['success' => true]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => 'Short message',
            'source_group' => 'workflow_routine_updates',
        ]);

        $result = $node->execute([]);

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
        $this->assertSame('workflow_routine_updates', $result['data']['source_group']);
    }

    public function test_suppressed_chunk_is_not_counted_as_sent(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'suppressed' => true,
                'source_group' => 'workflow_routine_updates',
            ]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => 'Short message',
            'source_group' => 'workflow_routine_updates',
        ]);

        $result = $node->execute([]);

        $this->assertNull($result['error']);
        $this->assertFalse($result['data']['notification_sent']);
        $this->assertTrue($result['data']['notification_suppressed']);
        $this->assertSame(0, $result['data']['parts_sent']);
        $this->assertSame(1, $result['data']['parts_suppressed']);
        $this->assertSame([1], $result['data']['part_numbers_suppressed']);
        $this->assertSame([], $result['data']['part_numbers_failed']);
    }

    public function test_failed_chunk_records_part_number_without_marking_notification_sent(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->twice()
            ->andReturn(
                ['success' => true],
                ['success' => false, 'error' => 'HTTP 500'],
            );

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => str_repeat('A', 1200),
            'max_retries_per_chunk' => 1,
            'inter_chunk_delay_seconds' => 0,
        ]);

        $result = $node->execute([]);

        $this->assertNull($result['error']);
        $this->assertFalse($result['data']['notification_sent']);
        $this->assertSame(2, $result['data']['total_parts']);
        $this->assertSame(1, $result['data']['parts_sent']);
        $this->assertSame([2], $result['data']['part_numbers_sent']);
        $this->assertSame([1], $result['data']['part_numbers_failed']);
    }

    public function test_rethrows_timeout_result_from_notification_controller(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Node timeout: PushoverNotify exceeded 300s limit',
            ]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => 'Short message',
            'retry_delay_seconds' => 0,
            'inter_chunk_delay_seconds' => 0,
        ]);

        $this->expectException(NodeTimeoutException::class);
        $this->expectExceptionMessage('Node timeout: PushoverNotify exceeded 300s limit');

        $node->execute([]);
    }

    public function test_rethrows_timeout_after_partial_multipart_send(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->twice()
            ->andReturn(
                ['success' => true],
                [
                    'success' => false,
                    'error' => 'Node timeout: PushoverNotify exceeded 300s limit',
                ],
            );

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => str_repeat('A', 1200),
            'retry_delay_seconds' => 0,
            'inter_chunk_delay_seconds' => 0,
        ]);

        $this->expectException(NodeTimeoutException::class);
        $this->expectExceptionMessage('Node timeout: PushoverNotify exceeded 300s limit');

        $node->execute([]);
    }

    public function test_partial_multipart_timeout_starts_with_last_content_part(): void
    {
        $payloads = [];
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->twice()
            ->with('pushover', Mockery::on(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return true;
            }))
            ->andReturn(
                ['success' => true],
                [
                    'success' => false,
                    'error' => 'Node timeout: PushoverNotify exceeded 300s limit',
                ],
            );

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => implode("\n\n", [
                'First packet '.str_repeat('A', 980),
                'Second packet '.str_repeat('B', 980),
            ]),
            'retry_delay_seconds' => 0,
            'inter_chunk_delay_seconds' => 0,
        ]);

        try {
            $node->execute([]);
            $this->fail('Expected multipart timeout to be rethrown.');
        } catch (NodeTimeoutException $exception) {
            $this->assertStringContainsString('Node timeout: PushoverNotify exceeded 300s limit', $exception->getMessage());
        }

        $this->assertCount(2, $payloads);
        $this->assertSame('Daily News (Part 2/2)', $payloads[0]['title']);
        $this->assertStringStartsWith('Second packet', $payloads[0]['message']);
        $this->assertSame('Daily News (Part 1/2)', $payloads[1]['title']);
        $this->assertStringStartsWith('First packet', $payloads[1]['message']);
    }

    public function test_refuses_empty_upstream_error_envelope_without_sending(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')->never();

        $node = new PushoverNotify([
            'title' => 'Daily News',
        ]);

        $result = $node->execute([
            'data' => null,
            'meta' => [
                'timestamp' => '2026-04-29T10:16:38Z',
                'error_message' => 'AI processing failed for batch 3',
            ],
            'error' => 'Batch processing failed: AI processing failed for batch 3',
        ]);

        $this->assertNull($result['data']);
        $this->assertStringContainsString('Upstream node error: Batch processing failed', $result['error']);
    }

    public function test_configured_message_overrides_upstream_error_envelope(): void
    {
        $controller = Mockery::mock('overload:'.NotificationController::class);
        $controller->shouldReceive('send')
            ->once()
            ->with('pushover', Mockery::on(function (array $payload) {
                return ($payload['message'] ?? null) === 'Operator summary is ready';
            }))
            ->andReturn(['success' => true]);

        $node = new PushoverNotify([
            'title' => 'Daily News',
            'message' => 'Operator summary is ready',
        ]);

        $result = $node->execute([
            'data' => null,
            'error' => 'Batch processing failed upstream',
        ]);

        $this->assertNull($result['error']);
        $this->assertTrue($result['data']['notification_sent']);
    }
}
