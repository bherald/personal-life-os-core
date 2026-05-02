<?php

namespace App\DTOs;

/**
 * OfflinePolicyService decision result (P02b).
 *
 * One immutable object returned by every evaluator on OfflinePolicyService.
 * Consumers (AgentGuardrailService, MCPRouter, AIService, LLMPoolManagerService)
 * read `allowed`, `requiresConfirmation`, and `reason` at minimum, and may
 * inspect the classification fields for audit/logging.
 */
final class PolicyDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly string $profile,
        public readonly bool $requiresConfirmation = false,
        public readonly ?string $toolClass = null,
        public readonly ?string $mcpTrustBoundary = null,
        public readonly ?string $pathClass = null,
        public readonly ?string $providerClass = null,
        public readonly ?string $remoteDomainClass = null,
    ) {}

    public static function allow(
        string $reason,
        string $profile,
        bool $requiresConfirmation = false,
        ?string $toolClass = null,
        ?string $mcpTrustBoundary = null,
        ?string $pathClass = null,
        ?string $providerClass = null,
        ?string $remoteDomainClass = null,
    ): self {
        return new self(
            allowed: true,
            reason: $reason,
            profile: $profile,
            requiresConfirmation: $requiresConfirmation,
            toolClass: $toolClass,
            mcpTrustBoundary: $mcpTrustBoundary,
            pathClass: $pathClass,
            providerClass: $providerClass,
            remoteDomainClass: $remoteDomainClass,
        );
    }

    public static function deny(
        string $reason,
        string $profile,
        ?string $toolClass = null,
        ?string $mcpTrustBoundary = null,
        ?string $pathClass = null,
        ?string $providerClass = null,
        ?string $remoteDomainClass = null,
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            profile: $profile,
            requiresConfirmation: false,
            toolClass: $toolClass,
            mcpTrustBoundary: $mcpTrustBoundary,
            pathClass: $pathClass,
            providerClass: $providerClass,
            remoteDomainClass: $remoteDomainClass,
        );
    }

    /**
     * Flatten into an array suitable for audit logging.
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'profile' => $this->profile,
            'requires_confirmation' => $this->requiresConfirmation,
            'reason' => $this->reason,
            'tool_class' => $this->toolClass,
            'mcp_trust_boundary' => $this->mcpTrustBoundary,
            'path_class' => $this->pathClass,
            'provider_class' => $this->providerClass,
            'remote_domain_class' => $this->remoteDomainClass,
        ];
    }
}
