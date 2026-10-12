<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentSkillReferenceService;
use Closure;

/** Read complete references from this session's verified, saved skill bundle. */
final class CliSkillReferenceService extends AgentSkillReferenceService
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly ?Closure $keeper = null,
    ) {}

    public function read(string $reference, string $agentId): array
    {
        $this->keeper?->__invoke();
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.md$/D', $reference) !== 1) {
            return ['success' => false, 'error' => 'Reference must be one Markdown filename in the selected skill bundle.'];
        }
        $session = $this->store->session($this->sessionId);
        $configuration = new CliConfiguration($this->store);
        $effective = $configuration->effective($session);
        $agent = $configuration->agentForSession($session);
        $name = $configuration->selectedSkillName($session);
        if (! is_string($name) || $agentId !== ($agent['agent_id'] ?? $name)) {
            return ['success' => false, 'error' => 'Reference access differs from the current agent or skill selection.'];
        }
        $available = array_filter($configuration->skills($session),
            fn ($skill) => $skill['name'] === $name && $skill['version_matches']);
        if (count($available) !== 1) {
            return ['success' => false, 'error' => 'Selected skill has no unique active matching bundle.'];
        }
        $skill = (new CliSkillReader($configuration))->read($session, $name);
        $file = $skill['files']['references/'.$reference] ?? null;
        if ($file === null) {
            return ['success' => false, 'error' => 'Reference is absent from the selected saved skill bundle.'];
        }
        $this->keeper?->__invoke();
        $content = base64_decode($file['base64'], true);
        if (! is_string($content) || strlen($content) !== $file['bytes']
            || ! hash_equals($file['sha256'], hash('sha256', $content))) {
            return ['success' => false, 'error' => 'Saved reference failed original-byte verification.'];
        }
        if (preg_match('//u', $content) !== 1) {
            return ['success' => false, 'error' => 'Reference is not valid UTF-8 Markdown; its original bundle bytes remain saved.'];
        }
        $this->keeper?->__invoke();
        $latest = $this->store->session($this->sessionId);
        if ($latest['generation'] !== $session['generation'] || $configuration->effective($latest) !== $effective) {
            return ['success' => false, 'error' => 'Skill reference selection changed during execution.'];
        }
        $metadata = ['reference' => $reference, 'skill_name' => $name, 'skill_version' => $skill['version'],
            'skill_sha256' => $skill['sha256'], 'bytes' => strlen($content), 'sha256' => $file['sha256']];
        $this->store->recordEvent($this->sessionId, 'skill_reference_read', $metadata);

        // This local adapter returns the complete verified reference. The normal
        // exact context counter decides whether the next model request fits.
        return ['success' => true, 'content' => $content, 'truncated' => false] + $metadata;
    }
}
