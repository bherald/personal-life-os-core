<?php

namespace Tests\Unit\Controllers\Api;

use App\Http\Controllers\Api\ChatController;
use ReflectionMethod;
use Tests\TestCase;

class ChatControllerRagSourceReferenceTest extends TestCase
{
    public function test_chat_rag_source_payload_uses_shared_reference_contract(): void
    {
        $document = (object) [
            'id' => 91,
            'document_type' => 'file',
            'source_type' => 'file_catalog',
            'source_id' => '/private/source/path',
            'media_url' => 'file:///private/source/path',
            'metadata' => ['conversation_id' => 22],
        ];
        $method = new ReflectionMethod(ChatController::class, 'ragSourcePayload');

        $payload = $method->invoke(new ChatController, $document, 1, 'Synthetic file', 0.75, 1200);

        $this->assertSame(91, $payload['id']);
        $this->assertSame(1, $payload['num']);
        $this->assertSame(1200, $payload['content_length']);
        $this->assertNull($payload['url']);
        $this->assertSame('source_bound', $payload['source']['citation_status']);
        $this->assertSame('conversation', $payload['source']['scope']);
        $this->assertStringNotContainsString('/private/source/path', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
