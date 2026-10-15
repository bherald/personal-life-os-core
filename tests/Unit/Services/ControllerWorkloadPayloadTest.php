<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor as Supervisor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ControllerWorkloadPayloadTest extends TestCase
{
    public function test_rerank_contract_preserves_full_sources_and_hashes_content_identity(): void
    {
        $payload = $this->rerankPayload();
        Supervisor::assertWorkloadPayload('rerank', $payload, $this->rerankContract());
        $this->assertSame("  José\r\nCritical tail  ", $payload['documents'][0]['text']);
        $settings = Supervisor::settings($payload);
        $this->assertArrayNotHasKey('documents', $settings);
        $this->assertArrayNotHasKey('query', $settings);
        $changed = $payload;
        $changed['documents'][0]['text'] .= ' changed';
        $this->assertNotSame($settings['rerank_input_sha256'], Supervisor::settings($changed)['rerank_input_sha256']);
    }

    public static function deniedRerankChanges(): array
    {
        return ['unknown' => [['truncate' => true]], 'duplicate ids' => [['documents' => [['id' => 'one', 'text' => 'a'], ['id' => 'one', 'text' => 'b']]]],
            'nontext' => [['documents' => [['id' => 'one', 'text' => 123]]]], 'oversize' => [['query' => str_repeat('x', 2049)]],
            'instruction drift' => [['instruction' => 'other instruction']]];
    }

    #[DataProvider('deniedRerankChanges')]
    public function test_rerank_rejects_changed_envelope_without_shortening(array $change): void
    {
        $payload = array_replace($this->rerankPayload(), $change);
        $before = $payload;
        try {
            Supervisor::assertWorkloadPayload('rerank', $payload, $this->rerankContract());
            $this->fail('Changed rerank envelope was accepted.');
        } catch (InferenceAdmissionException) {
            $this->assertSame($before, $payload);
        }
    }

    public static function deniedRerankHostBindings(): array
    {
        return ['unbound' => [null, 9230], 'external' => ['198.51.100.17:9230', 9230],
            'suffix' => ['127.0.0.1:9230.evil', 9230], 'port drift' => ['127.0.0.1:9230', 9231]];
    }

    #[DataProvider('deniedRerankHostBindings')]
    public function test_rerank_requires_exact_loopback_host_tied_to_native_port(?string $host, int $port): void
    {
        $contract = array_replace($this->rerankContract(), ['backend_http_host' => $host, 'native_port' => $port]);
        $this->expectException(InferenceAdmissionException::class);
        Supervisor::assertWorkloadPayload('rerank', $this->rerankPayload(), $contract);
    }

    public function test_rerank_header_binding_changes_request_contract_identity(): void
    {
        $original = $this->rerankContract();
        $changed = array_replace($original, ['backend_http_host' => 'localhost:9230']);
        $this->assertNotSame(ModelHostControllerResourcePolicyService::requestContractIdentity($original),
            ModelHostControllerResourcePolicyService::requestContractIdentity($changed));
    }

    private function rerankPayload(): array
    {
        return ['model' => 'rerank-fixture', 'query' => 'query', 'instruction' => 'Retrieve evidence.',
            'documents' => [['id' => 'one', 'text' => "  José\r\nCritical tail  "]]];
    }

    private function rerankContract(): array
    {
        return ['model' => 'rerank-fixture', 'wire_api' => 'rerank', 'maximum_input_bytes' => 2048,
            'maximum_documents' => 2, 'instruction' => 'Retrieve evidence.', 'revision' => str_repeat('a', 40),
            'scoring' => 'yes_no_softmax', 'device' => 'cpu', 'complete_input' => true,
            'native_port' => 9230, 'backend_http_host' => '127.0.0.1:9230'];
    }

    private function embeddingContract(): array
    {
        return ['model' => 'embedding', 'wire_api' => 'ollama_embeddings', 'context_window' => 2048,
            'maximum_input_bytes' => 8192, 'ollama_options' => ['num_ctx' => 2048, 'num_gpu' => 0]];
    }

    private function embeddingPayload(): array
    {
        return ['model' => 'embedding', 'input' => "Original\nbytes", 'truncate' => false, 'keep_alive' => 0,
            'options' => ['num_gpu' => 0, 'num_ctx' => 2048]];
    }

    public function test_exact_embedding_contract_preserves_original_input_and_excludes_it_from_identity(): void
    {
        $payload = $this->embeddingPayload();
        Supervisor::assertWorkloadPayload('ollama_embeddings', $payload, $this->embeddingContract());
        $this->assertSame("Original\nbytes", $payload['input']);
        $this->assertArrayNotHasKey('input', Supervisor::settings($payload));
    }

    public static function deniedEmbeddingChanges(): array
    {
        return ['truncate' => [['truncate' => true]], 'resident' => [['keep_alive' => -1]],
            'batch' => [['input' => ['first', 'second']]], 'oversize' => [['input' => str_repeat('x', 8193)]],
            'gpu' => [['options' => ['num_ctx' => 2048, 'num_gpu' => 99]]],
            'unknown' => [['parallel' => 2]], 'different model' => [['model' => 'other']]];
    }

    #[DataProvider('deniedEmbeddingChanges')]
    public function test_embedding_rejects_resource_or_input_changes(array $change): void
    {
        $this->expectException(InferenceAdmissionException::class);
        Supervisor::assertWorkloadPayload('ollama_embeddings', array_replace($this->embeddingPayload(), $change), $this->embeddingContract());
    }

    public function test_openai_embedding_requires_registered_dimension_identity(): void
    {
        $contract = ['model' => 'embedding', 'wire_api' => 'openai_embeddings', 'maximum_input_bytes' => 8192, 'dimensions' => 768];
        Supervisor::assertWorkloadPayload('openai_embeddings', ['model' => 'embedding', 'input' => 'original', 'dimensions' => 768], $contract);
        $this->expectException(InferenceAdmissionException::class);
        Supervisor::assertWorkloadPayload('openai_embeddings', ['model' => 'embedding', 'input' => 'original', 'dimensions' => 1024], $contract);
    }

    public function test_image_envelope_rejects_unqualified_resolution_without_rewriting_it(): void
    {
        $contract = ['model' => 'image', 'wire_api' => 'image_generation', 'maximum_input_bytes' => 2000,
            'maximum_width' => 1024, 'maximum_height' => 1024, 'maximum_steps' => 4, 'maximum_guidance_scale' => 5];
        $payload = ['model' => 'image', 'prompt' => 'A blue square', 'width' => 1024, 'height' => 1024, 'steps' => 4, 'guidance_scale' => 0.0, 'seed' => 42];
        Supervisor::assertWorkloadPayload('image_generation', $payload, $contract);
        $this->assertArrayNotHasKey('prompt', Supervisor::settings($payload));
        $this->expectException(InferenceAdmissionException::class);
        Supervisor::assertWorkloadPayload('image_generation', array_replace($payload, ['width' => 2048]), $contract);
    }
}
