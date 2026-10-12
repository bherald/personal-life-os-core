<?php

namespace App\Support\AI;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\PipelineHttpClientFactory;
use Closure;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Client\PendingRequest;

/** Invocation-local transport checkpoints. Application done events are never evidence. */
final class PoolExecutionReceipt
{
    private string $phase = 'pool_prepared';

    private int $sequence = 0;

    private ?string $protocol = null;

    private bool $failed = false;

    private bool $applicationComplete = false;

    private bool $applicationFailed = false;

    public function __construct(
        private readonly string $model,
        private readonly array $endpoints,
        private readonly Closure $publish,
        private readonly Closure $release
    ) {}

    public static function fromConfig(array $config): ?self
    {
        $receipt = $config['_pool_execution_receipt'] ?? null;
        if ($receipt !== null && ! $receipt instanceof self) {
            throw new InferenceAdmissionException('Pool execution receipt is invalid; dispatch held.');
        }

        return $receipt;
    }

    /** The actual transport calls this after all local preparation, immediately before request(). */
    public function beforeSend(string $protocol, string $endpoint, string $model): void
    {
        if ($this->failed || $this->applicationFailed || ! in_array($this->phase, ['pool_prepared', 'pool_terminal_verified:'.$this->sequence], true)
            || $model !== $this->model || ($this->endpoints[$protocol] ?? null) !== hash('sha256', $endpoint)) {
            throw new InferenceAdmissionException('Pool request binding or prior terminal evidence is unavailable; dispatch held.');
        }
        $next = 'pool_dispatched:'.($this->sequence + 1);
        $this->transition($next, ['dispatch_sequence' => $this->sequence + 1,
            'protocol' => $protocol, 'endpoint_sha256' => hash('sha256', $endpoint), 'model_sha256' => hash('sha256', $model)]);
        $this->sequence++;
        $this->protocol = $protocol;
    }

    /** Only clients parsing a complete, successful Ollama wire response call this method. */
    public function ollamaTerminal(array $frame): void
    {
        if (($frame['done'] ?? null) !== true || isset($frame['error'])
            || ! (($this->protocol === 'ollama_generate' && is_string($frame['response'] ?? null))
                || ($this->protocol === 'ollama_chat' && is_array($frame['message'] ?? null)))) {
            throw new InferenceAdmissionException('Pool Ollama wire completion is invalid; custody retained.');
        }
        $this->wireTerminal('ollama_done');
    }

    /** Called on the raw SSE checkpoint before client stop/sentence filtering and before yield. */
    public function openAiWireTerminal(string $marker): void
    {
        if ($this->protocol !== 'openai_chat_completions' || ! in_array($marker, ['done_marker', 'finish_reason'], true)) {
            throw new InferenceAdmissionException('Pool SSE protocol binding is invalid; custody retained.');
        }
        $this->wireTerminal('openai_'.$marker);
    }

    private function wireTerminal(string $evidence): void
    {
        if ($this->phase === 'pool_terminal_verified:'.$this->sequence && ! $this->failed) {
            return;
        }
        if ($this->failed || $this->phase !== 'pool_dispatched:'.$this->sequence) {
            throw new InferenceAdmissionException('Pool wire completion is outside exact dispatch custody.');
        }
        $this->transition('pool_terminal_verified:'.$this->sequence, ['terminal_receipt' => [
            'schema' => 'plos.pool_wire_terminal.v1', 'dispatch_sequence' => $this->sequence,
            'protocol' => $this->protocol, 'evidence' => $evidence,
        ]]);
    }

    private function transition(string $next, array $metadata): void
    {
        try {
            ($this->publish)($this->phase, $next, $metadata);
            $this->phase = $next;
        } catch (\Throwable $error) {
            $this->failed = true;
            throw new InferenceAdmissionException('Pool execution publication failed; custody retained and replay held.', 0, $error);
        }
    }

    /** A transport attempt, even terminal, cannot be replayed as recovery for accounting/output errors. */
    public function stopReplayAfterFailure(?\Throwable $cause = null): void
    {
        if ($this->sequence > 0 || $this->failed || str_starts_with($this->phase, 'pool_dispatched:')) {
            $this->applicationFailed = true;
            if ($cause !== null && InferenceAdmissionException::isAdmissionFailure($cause)) {
                throw $cause;
            }
            throw new InferenceAdmissionException('Pool request crossed its send boundary; automatic retry or fallback held.', 0, $cause);
        }
    }

    public function assertTerminalBeforeSuccess(): void
    {
        if ($this->failed || $this->sequence === 0 || $this->phase !== 'pool_terminal_verified:'.$this->sequence) {
            throw new InferenceAdmissionException('Pool response has no real wire terminal evidence; custody retained.');
        }
        $this->applicationComplete = true;
    }

    /** No-send is established by this receipt's untouched exact transport checkpoint, then owned CAS. */
    public function finish(): void
    {
        if ($this->failed) {
            throw new InferenceAdmissionException('Pool publication outcome is unknown; custody retained.');
        }
        if ($this->phase === 'pool_prepared' && $this->sequence === 0) {
            $this->transition('pool_terminal_verified:0', ['terminal_receipt' => [
                'schema' => 'plos.pool_no_send.v1', 'dispatch_sequence' => 0, 'evidence' => 'transport_checkpoint_not_crossed',
            ]]);
        }
        if ($this->phase !== 'pool_terminal_verified:'.$this->sequence) {
            throw new InferenceAdmissionException('Pool request termination is unproved; custody retained.');
        }
        try {
            $outcome = $this->sequence === 0 ? 'cancel' : ($this->applicationComplete && ! $this->applicationFailed ? 'success' : 'failure');
            ($this->release)($this->phase, $outcome);
            $this->phase = 'pool_released';
        } catch (\Throwable $error) {
            $this->failed = true;
            throw new InferenceAdmissionException('Pool custody release failed; hold retained.', 0, $error);
        }
    }

    public function transportOptions(array $options): array
    {
        return array_replace($options, ['handler' => HandlerStack::create(PipelineHttpClientFactory::oneShotHandler()),
            'allow_redirects' => false]);
    }

    public function transportHttp(PendingRequest $request): PendingRequest
    {
        return $request->setHandler(PipelineHttpClientFactory::oneShotHandler())->withOptions(['allow_redirects' => false]);
    }
}
